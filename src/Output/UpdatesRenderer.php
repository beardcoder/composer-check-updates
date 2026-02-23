<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Output;

use Beardcoder\ComposerCheckUpdates\Model\PackageUpdate;
use Symfony\Component\Console\Output\OutputInterface;

final class UpdatesRenderer
{
    private const COLOR_MAJOR = 'red';
    private const COLOR_MINOR = 'cyan';
    private const COLOR_PATCH = 'green';

    /**
     * @param PackageUpdate[] $updates
     */
    public function render(array $updates, OutputInterface $output): void
    {
        if (empty($updates)) {
            return;
        }

        $grouped = ['major' => [], 'minor' => [], 'patch' => []];
        foreach ($updates as $update) {
            $grouped[$update->updateType][] = $update;
        }

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

            $count = count($grouped[$type]);
            $label = ucfirst($type) . ($type === 'patch' ? ' Updates' : ' Upgrades');
            $output->writeln(sprintf(' <fg=%s;options=bold>%s</> <fg=%s>(%d)</>', $color, $label, $color, $count));
            $output->writeln('');

            foreach ($grouped[$type] as $update) {
                $devTag = $update->isDev ? ' <comment>(dev)</comment>' : '';
                $output->writeln(sprintf(
                    '  %-' . $maxNameLen . 's  %' . $maxCurrentLen . 's  <fg=%s>→</>  <fg=%s>%s</>%s',
                    $update->name,
                    $update->currentConstraint,
                    $color,
                    $color,
                    $update->getNewConstraint(),
                    $devTag,
                ));
            }
            $output->writeln('');
        }

        $this->renderSummary($grouped, $output);
    }

    /**
     * @param PackageUpdate[] $updates
     */
    public function renderJson(array $updates, OutputInterface $output): void
    {
        $data = array_map(fn(PackageUpdate $update) => [
            'name' => $update->name,
            'current' => $update->currentConstraint,
            'currentVersion' => $update->currentVersion,
            'latest' => $update->latestVersion,
            'new' => $update->getNewConstraint(),
            'type' => $update->updateType,
            'isDev' => $update->isDev,
        ], $updates);

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, PackageUpdate[]> $grouped
     */
    private function renderSummary(array $grouped, OutputInterface $output): void
    {
        $total = array_sum(array_map('count', $grouped));
        $parts = [];

        foreach (['major' => self::COLOR_MAJOR, 'minor' => self::COLOR_MINOR, 'patch' => self::COLOR_PATCH] as $type => $color) {
            $count = count($grouped[$type]);
            if ($count > 0) {
                $parts[] = sprintf('<fg=%s>%d %s</>', $color, $count, $type);
            }
        }

        $output->writeln(sprintf(' <info>%d</info> updates available (%s)', $total, implode(', ', $parts)));
    }
}
