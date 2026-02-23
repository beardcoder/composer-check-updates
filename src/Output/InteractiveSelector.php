<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Output;

use Beardcoder\ComposerCheckUpdates\Model\PackageUpdate;
use Symfony\Component\Console\Output\OutputInterface;

final class InteractiveSelector
{
    private const COLOR_MAJOR = 'red';
    private const COLOR_MINOR = 'cyan';
    private const COLOR_PATCH = 'green';

    private int $cursorPosition = 0;
    /** @var array<int, bool> */
    private array $selected = [];
    private int $renderedLineCount = 0;

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
            }
            $lines[] = '';
        }

        $selectedCount = count(array_filter($this->selected));
        $lines[] = sprintf(' <comment>%d of %d selected</comment>', $selectedCount, count($updates));
        $lines[] = '';
        $lines[] = ' <comment>↑↓</comment> navigate  <comment>space</comment> toggle  <comment>a</comment> select all  <comment>enter</comment> confirm  <comment>q</comment> cancel';

        return $lines;
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
