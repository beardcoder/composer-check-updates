<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Output;

use Beardcoder\ComposerCheckUpdates\Model\PackageUpdate;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class InteractiveSelector
{
    private const COLOR_MAJOR = 'red';
    private const COLOR_MINOR = 'cyan';
    private const COLOR_PATCH = 'green';

    /** Lines reserved for header, footer, and margins */
    private const CHROME_LINES = 7;

    private int $cursorPosition = 0;
    /** @var array<int, bool> */
    private array $selected = [];
    /** @var array<int, int> Content line index → package index (-1 for non-package lines) */
    private array $contentLineToIndex = [];
    private int $renderedLineCount = 0;
    private int $scrollOffset = 0;

    /**
     * @param PackageUpdate[] $updates
     * @return PackageUpdate[]
     */
    public function select(array $updates, OutputInterface $output): array
    {
        if (empty($updates)) {
            return [];
        }

        if (!stream_isatty(STDIN)) {
            return $this->fallbackSelect($updates, $output);
        }

        $this->selected = array_fill(0, count($updates), true);
        $this->cursorPosition = 0;
        $this->renderedLineCount = 0;

        return $this->runInteractive($updates, $output);
    }

    /**
     * @param PackageUpdate[] $updates
     * @return PackageUpdate[]
     */
    private function runInteractive(array $updates, OutputInterface $output): array
    {
        $sttyState = trim((string) shell_exec('stty -g 2>/dev/null'));
        system('stty -echo -icanon min 1 time 0 2>/dev/null');

        $this->hideCursor($output);

        try {
            $this->render($updates, $output);

            while (true) {
                $key = $this->readKey();

                switch ($key) {
                    case 'up':
                        $this->cursorPosition = ($this->cursorPosition - 1 + count($updates)) % count($updates);
                        break;
                    case 'down':
                        $this->cursorPosition = ($this->cursorPosition + 1) % count($updates);
                        break;
                    case 'space':
                        $this->selected[$this->cursorPosition] = !$this->selected[$this->cursorPosition];
                        break;
                    case 'all':
                        $allSelected = !in_array(false, $this->selected, true);
                        $this->selected = array_fill(0, count($updates), !$allSelected);
                        break;
                    case 'enter':
                        $this->clearRendered($output);
                        return array_values(array_filter(
                            $updates,
                            fn($_, int $i) => $this->selected[$i],
                            ARRAY_FILTER_USE_BOTH,
                        ));
                    case 'quit':
                    case 'escape':
                        $this->clearRendered($output);
                        return [];
                    default:
                        continue 2;
                }

                $this->render($updates, $output);
            }
        } finally {
            $this->showCursor($output);
            if ($sttyState !== '') {
                system('stty ' . escapeshellarg($sttyState) . ' 2>/dev/null');
            } else {
                system('stty echo icanon 2>/dev/null');
            }
        }
    }

    private function readKey(): string
    {
        $char = fread(STDIN, 1);

        if ($char === "\033") {
            $seq = fread(STDIN, 2);
            if ($seq === '[A') {
                return 'up';
            }
            if ($seq === '[B') {
                return 'down';
            }
            return 'escape';
        }

        return match ($char) {
            'k' => 'up',
            'j' => 'down',
            ' ' => 'space',
            'a' => 'all',
            "\n", "\r" => 'enter',
            'q' => 'quit',
            default => '',
        };
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function render(array $updates, OutputInterface $output): void
    {
        // Move cursor up to overwrite previous render
        if ($this->renderedLineCount > 0) {
            $output->write(sprintf("\033[%dF", $this->renderedLineCount));
            $output->write("\033[J"); // Clear from cursor to end of screen
        }

        $lines = $this->buildLines($updates);
        foreach ($lines as $line) {
            $output->writeln($line);
        }

        $this->renderedLineCount = count($lines);
    }

    private function clearRendered(OutputInterface $output): void
    {
        if ($this->renderedLineCount > 0) {
            $output->write(sprintf("\033[%dF", $this->renderedLineCount));
            $output->write("\033[J");
            $this->renderedLineCount = 0;
        }
    }

    /**
     * @param PackageUpdate[] $updates
     * @return string[]
     */
    private function buildLines(array $updates): array
    {
        $lines = [];
        $lines[] = ' <info>Select packages to update:</info>';
        $lines[] = '';

        $contentLines = $this->buildContentLines($updates);
        $viewportHeight = $this->getViewportHeight();

        // Apply scrolling if content exceeds viewport
        if (count($contentLines) > $viewportHeight && $viewportHeight > 0) {
            $this->adjustScroll($contentLines, $viewportHeight);

            $hasMoreAbove = $this->scrollOffset > 0;
            $hasMoreBelow = ($this->scrollOffset + $viewportHeight) < count($contentLines);

            if ($hasMoreAbove) {
                $lines[] = ' <fg=gray>▲ ' . $this->scrollOffset . ' more above</>';
            }

            $visible = array_slice($contentLines, $this->scrollOffset, $viewportHeight);
            array_push($lines, ...$visible);

            if ($hasMoreBelow) {
                $remaining = count($contentLines) - $this->scrollOffset - $viewportHeight;
                $lines[] = ' <fg=gray>▼ ' . $remaining . ' more below</>';
            }
        } else {
            array_push($lines, ...$contentLines);
        }

        $lines[] = '';
        $selectedCount = count(array_filter($this->selected));
        $lines[] = sprintf(' <comment>%d of %d selected</comment>', $selectedCount, count($updates));
        $lines[] = '';
        $lines[] = ' <comment>↑↓</comment> navigate  <comment>space</comment> toggle  <comment>a</comment> select all  <comment>enter</comment> confirm  <comment>q</comment> cancel';

        return $lines;
    }

    /**
     * Build the scrollable content lines (groups + packages).
     *
     * Each line that represents a package stores its index in $this->contentLineToIndex
     * so scrolling can track which line the cursor is on.
     *
     * @param PackageUpdate[] $updates
     * @return string[]
     */
    private function buildContentLines(array $updates): array
    {
        $lines = [];
        $this->contentLineToIndex = [];

        $grouped = $this->groupByType($updates);

        $maxNameLen = max(array_map(fn(PackageUpdate $u) => strlen($u->name), $updates));
        $maxCurrentLen = max(array_map(fn(PackageUpdate $u) => strlen($u->currentConstraint), $updates));

        foreach (['major', 'minor', 'patch'] as $type) {
            if (empty($grouped[$type])) {
                continue;
            }

            $color = match ($type) {
                'major' => self::COLOR_MAJOR,
                'minor' => self::COLOR_MINOR,
                'patch' => self::COLOR_PATCH,
            };

            $label = ucfirst($type) . ($type === 'patch' ? ' Updates' : ' Upgrades');
            $lines[] = sprintf(' <fg=%s;options=bold>%s</>', $color, $label);
            $this->contentLineToIndex[count($lines) - 1] = -1; // header, not a package

            foreach ($grouped[$type] as ['update' => $update, 'index' => $index]) {
                $isCurrent = $index === $this->cursorPosition;
                $isSelected = $this->selected[$index];

                $pointer = $isCurrent ? '❯' : ' ';
                $checkbox = $isSelected ? '◉' : '◯';
                $devTag = $update->isDev ? ' (dev)' : '';

                $line = sprintf(
                    ' %s %s %-' . $maxNameLen . 's  %' . $maxCurrentLen . 's  →  %s%s',
                    $pointer,
                    $checkbox,
                    $update->name,
                    $update->currentConstraint,
                    $update->getNewConstraint(),
                    $devTag,
                );

                if ($isCurrent) {
                    $lines[] = sprintf('<options=reverse>%s</>', $line);
                } else {
                    $lines[] = sprintf('<fg=%s>%s</>', $color, $line);
                }
                $this->contentLineToIndex[count($lines) - 1] = $index;
            }
            $lines[] = '';
            $this->contentLineToIndex[count($lines) - 1] = -1;
        }

        return $lines;
    }

    private function getViewportHeight(): int
    {
        $terminal = new Terminal();
        $termHeight = $terminal->getHeight();

        // Reserve lines for chrome (header, footer, margins)
        return max(5, $termHeight - self::CHROME_LINES);
    }

    /**
     * Adjust scroll offset to keep the cursor-highlighted line visible.
     *
     * @param string[] $contentLines
     */
    private function adjustScroll(array $contentLines, int $viewportHeight): void
    {
        // Find the content line that contains the current cursor
        $cursorLine = null;
        foreach ($this->contentLineToIndex as $lineIdx => $pkgIdx) {
            if ($pkgIdx === $this->cursorPosition) {
                $cursorLine = $lineIdx;
                break;
            }
        }

        if ($cursorLine === null) {
            return;
        }

        // Scroll up if cursor is above viewport
        if ($cursorLine < $this->scrollOffset) {
            // Show the group header above if possible
            $this->scrollOffset = max(0, $cursorLine - 1);
        }

        // Scroll down if cursor is below viewport
        if ($cursorLine >= $this->scrollOffset + $viewportHeight) {
            $this->scrollOffset = $cursorLine - $viewportHeight + 2;
        }

        // Clamp
        $maxOffset = max(0, count($contentLines) - $viewportHeight);
        $this->scrollOffset = max(0, min($this->scrollOffset, $maxOffset));
    }

    /**
     * @param PackageUpdate[] $updates
     * @return array<string, list<array{update: PackageUpdate, index: int}>>
     */
    private function groupByType(array $updates): array
    {
        $grouped = ['major' => [], 'minor' => [], 'patch' => []];
        foreach ($updates as $index => $update) {
            if (isset($grouped[$update->updateType])) {
                $grouped[$update->updateType][] = ['update' => $update, 'index' => $index];
            }
        }
        return $grouped;
    }

    /**
     * Fallback for non-interactive terminals
     *
     * @param PackageUpdate[] $updates
     * @return PackageUpdate[]
     */
    private function fallbackSelect(array $updates, OutputInterface $output): array
    {
        $output->writeln(' <info>Available updates:</info>');
        $output->writeln('');

        foreach ($updates as $i => $update) {
            $output->writeln(sprintf(
                '  [%d] %-40s  %s  →  %s',
                $i + 1,
                $update->name,
                $update->currentConstraint,
                $update->getNewConstraint(),
            ));
        }

        $output->writeln('');
        $output->writeln(' Enter package numbers (comma-separated), <info>all</info>, or <info>q</info> to cancel:');
        $output->write(' > ');

        $input = trim((string) fgets(STDIN));

        if ($input === '' || $input === 'q') {
            return [];
        }

        if ($input === 'all') {
            return $updates;
        }

        $indices = array_map(fn(string $s) => (int) trim($s) - 1, explode(',', $input));
        return array_values(array_filter(
            $updates,
            fn($_, int $i) => in_array($i, $indices, true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function hideCursor(OutputInterface $output): void
    {
        if ($output->isDecorated()) {
            $output->write("\033[?25l");
        }
    }

    private function showCursor(OutputInterface $output): void
    {
        if ($output->isDecorated()) {
            $output->write("\033[?25h");
        }
    }
}
