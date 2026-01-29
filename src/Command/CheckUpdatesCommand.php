<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Command;

use Composer\Command\BaseCommand;
use Beardcoder\ComposerCheckUpdates\Model\PackageUpdate;
use Beardcoder\ComposerCheckUpdates\Service\ComposerJsonUpdater;
use Beardcoder\ComposerCheckUpdates\Service\PackageVersionChecker;
use Beardcoder\ComposerCheckUpdates\Service\VersionComparator;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class CheckUpdatesCommand extends BaseCommand
{
    private const COLOR_MAJOR = 'red';
    private const COLOR_MINOR = 'cyan';
    private const COLOR_PATCH = 'green';

    protected function configure(): void
    {
        $this
            ->setName('check-updates')
            ->setAliases(['ccu'])
            ->setDescription('Check for package updates beyond composer.json constraints')
            ->addOption(
                'upgrade',
                'u',
                InputOption::VALUE_NONE,
                'Update composer.json with new versions'
            )
            ->addOption(
                'interactive',
                'i',
                InputOption::VALUE_NONE,
                'Interactive mode: select which packages to update'
            )
            ->addOption(
                'filter',
                'f',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter packages by name (supports wildcards)'
            )
            ->addOption(
                'reject',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude packages by name (supports wildcards)'
            )
            ->addOption(
                'dev-only',
                null,
                InputOption::VALUE_NONE,
                'Only check dev dependencies'
            )
            ->addOption(
                'prod-only',
                null,
                InputOption::VALUE_NONE,
                'Only check production dependencies'
            )
            ->addOption(
                'minor-only',
                null,
                InputOption::VALUE_NONE,
                'Only show minor and patch updates'
            )
            ->addOption(
                'patch-only',
                null,
                InputOption::VALUE_NONE,
                'Only show patch updates'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON'
            )
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED,
                'Target version to upgrade to: latest, minor, patch (default: latest)'
            )
            ->addOption(
                'working-dir',
                'd',
                InputOption::VALUE_REQUIRED,
                'Use the given directory as working directory'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDir = $input->getOption('working-dir') ?? getcwd();
        $composerJsonPath = $workingDir . '/composer.json';
        $composerLockPath = $workingDir . '/composer.lock';

        if (!file_exists($composerJsonPath)) {
            $output->writeln('<error>composer.json not found in ' . $workingDir . '</error>');
            return 1;
        }

        $versionComparator = new VersionComparator();
        $versionChecker = new PackageVersionChecker($versionComparator);
        $jsonUpdater = new ComposerJsonUpdater();

        $dependencies = $jsonUpdater->readDependencies($composerJsonPath);
        if ($dependencies === null) {
            $output->writeln('<error>Failed to read composer.json</error>');
            return 1;
        }

        $lockData = $jsonUpdater->readLockFile($composerLockPath) ?? [];

        $packages = $this->collectPackages($dependencies, $input);
        $updates = $this->checkUpdates($packages, $versionChecker, $versionComparator, $lockData, $output, $input);

        if (empty($updates)) {
            $output->writeln('<info>All packages are up to date!</info>');
            return 0;
        }

        if ($input->getOption('json')) {
            return $this->outputJson($updates, $output);
        }

        if ($input->getOption('interactive')) {
            return $this->runInteractiveMode($updates, $composerJsonPath, $jsonUpdater, $input, $output);
        }

        $this->displayUpdates($updates, $output);

        if ($input->getOption('upgrade')) {
            return $this->performUpgrade($updates, $composerJsonPath, $jsonUpdater, $output);
        }

        $output->writeln('');
        $output->writeln('Run <info>composer ccu -u</info> to upgrade composer.json');
        $output->writeln('Run <info>composer ccu -i</info> for interactive mode');

        return 0;
    }

    /**
     * @return array<string, array{constraint: string, isDev: bool}>
     */
    private function collectPackages(array $dependencies, InputInterface $input): array
    {
        $packages = [];
        $filters = $input->getOption('filter') ?: [];
        $rejects = $input->getOption('reject') ?: [];
        $devOnly = $input->getOption('dev-only');
        $prodOnly = $input->getOption('prod-only');

        if (!$prodOnly) {
            foreach ($dependencies['require-dev'] as $name => $constraint) {
                if ($this->shouldIncludePackage($name, $filters, $rejects)) {
                    $packages[$name] = ['constraint' => $constraint, 'isDev' => true];
                }
            }
        }

        if (!$devOnly) {
            foreach ($dependencies['require'] as $name => $constraint) {
                if ($this->shouldIncludePackage($name, $filters, $rejects)) {
                    $packages[$name] = ['constraint' => $constraint, 'isDev' => false];
                }
            }
        }

        return $packages;
    }

    private function shouldIncludePackage(string $name, array $filters, array $rejects): bool
    {
        // Skip PHP extensions and PHP itself
        if (str_starts_with($name, 'ext-') || $name === 'php') {
            return false;
        }

        // Must contain a slash (vendor/package format)
        if (!str_contains($name, '/')) {
            return false;
        }

        // Apply filters
        if (!empty($filters)) {
            $matched = false;
            foreach ($filters as $filter) {
                if ($this->matchesPattern($name, $filter)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        // Apply rejects
        foreach ($rejects as $reject) {
            if ($this->matchesPattern($name, $reject)) {
                return false;
            }
        }

        return true;
    }

    private function matchesPattern(string $name, string $pattern): bool
    {
        $regex = '/^' . str_replace(['*', '/'], ['.*', '\\/'], $pattern) . '$/i';
        return preg_match($regex, $name) === 1;
    }

    /**
     * @return PackageUpdate[]
     */
    private function checkUpdates(
        array $packages,
        PackageVersionChecker $versionChecker,
        VersionComparator $versionComparator,
        array $lockData,
        OutputInterface $output,
        InputInterface $input
    ): array {
        $updates = [];
        $total = count($packages);

        $minorOnly = $input->getOption('minor-only');
        $patchOnly = $input->getOption('patch-only');
        $target = $input->getOption('target') ?? 'latest';

        // Validate target option
        if (!in_array($target, ['latest', 'minor', 'patch'], true)) {
            $output->writeln('<error>Invalid target. Use: latest, minor, or patch</error>');
            return [];
        }

        $output->writeln('Checking <info>' . $total . '</info> packages...');
        $output->writeln('');

        // Get all package names for batch fetching
        $packageNames = array_keys($packages);

        // Show initial progress
        $this->updateProgress($output, 0, $total, 'Fetching versions...');

        // Fetch all versions in parallel
        $latestVersions = $versionChecker->getLatestVersionsBatch($packageNames, $target);

        // Process results
        $current = 0;
        foreach ($packages as $name => $data) {
            $current++;
            $this->updateProgress($output, $current, $total, $name);

            $latestVersion = $latestVersions[$name] ?? null;

            if ($latestVersion === null) {
                continue;
            }

            // Get installed version from lock file, or extract from constraint
            $currentVersion = $versionChecker->getInstalledVersion($name, $lockData)
                ?? $versionChecker->extractVersionFromConstraint($data['constraint']);

            // For minor/patch targets, find appropriate version
            if ($target !== 'latest') {
                $packageVersions = $this->getPackageVersionsFromChecker($versionChecker, $name);
                if ($packageVersions !== null) {
                    $targetVersion = $versionChecker->findVersionForTarget(
                        $packageVersions,
                        $currentVersion,
                        $target
                    );
                    if ($targetVersion !== null) {
                        $latestVersion = $targetVersion;
                    }
                }
            }

            if (!$versionComparator->isVersionNewer($currentVersion, $latestVersion)) {
                continue;
            }

            $updateType = $versionComparator->getUpdateType($currentVersion, $latestVersion);

            // Filter by update type (--minor-only and --patch-only flags)
            if ($patchOnly && $updateType !== VersionComparator::TYPE_PATCH) {
                continue;
            }
            if ($minorOnly && $updateType === VersionComparator::TYPE_MAJOR) {
                continue;
            }

            $update = new PackageUpdate(
                $name,
                $data['constraint'],
                $currentVersion,
                $latestVersion,
                $updateType,
                $data['isDev']
            );

            // Skip if the new constraint is the same as the current one
            if ($update->getNewConstraint() === $data['constraint']) {
                continue;
            }

            $updates[] = $update;
        }

        // Clear progress line
        $output->write("\r" . str_repeat(' ', 80) . "\r");

        return $updates;
    }

    /**
     * Helper to get cached package versions from the checker
     */
    private function getPackageVersionsFromChecker(PackageVersionChecker $versionChecker, string $packageName): ?array
    {
        return $versionChecker->getCachedVersions($packageName);
    }

    private function updateProgress(OutputInterface $output, int $current, int $total, string $packageName): void
    {
        $percentage = (int) (($current / $total) * 100);
        $barWidth = 30;
        $filled = (int) (($current / $total) * $barWidth);
        $bar = str_repeat('=', $filled) . str_repeat(' ', $barWidth - $filled);

        $shortName = strlen($packageName) > 30 ? substr($packageName, 0, 27) . '...' : $packageName;

        $output->write(sprintf(
            "\r[%s] %3d%% %s",
            $bar,
            $percentage,
            str_pad($shortName, 30)
        ));
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function displayUpdates(array $updates, OutputInterface $output): void
    {
        // Group by update type
        $grouped = ['major' => [], 'minor' => [], 'patch' => []];

        foreach ($updates as $update) {
            $grouped[$update->updateType][] = $update;
        }

        // Display each group
        foreach (['major', 'minor', 'patch'] as $type) {
            if (empty($grouped[$type])) {
                continue;
            }

            $color = match ($type) {
                'major' => self::COLOR_MAJOR,
                'minor' => self::COLOR_MINOR,
                'patch' => self::COLOR_PATCH,
                default => 'white',
            };

            $output->writeln(sprintf(
                '<fg=%s>%s %s</>',
                $color,
                ucfirst($type),
                $type === 'patch' ? 'Updates' : 'Upgrades'
            ));
            $output->writeln('');

            foreach ($grouped[$type] as $update) {
                $this->displayPackageUpdate($update, $output, $color);
            }
            $output->writeln('');
        }
    }

    private function displayPackageUpdate(PackageUpdate $update, OutputInterface $output, string $color): void
    {
        $devTag = $update->isDev ? ' <comment>(dev)</comment>' : '';
        $output->writeln(sprintf(
            '  %-40s  %15s  →  <fg=%s>%-15s</>%s',
            $update->name,
            $update->currentConstraint,
            $color,
            $update->getNewConstraint(),
            $devTag
        ));
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function performUpgrade(
        array $updates,
        string $composerJsonPath,
        ComposerJsonUpdater $jsonUpdater,
        OutputInterface $output
    ): int {
        $output->writeln('');
        $output->writeln('Updating composer.json...');

        if ($jsonUpdater->update($composerJsonPath, $updates)) {
            $output->writeln('<info>composer.json updated successfully!</info>');
            $output->writeln('');
            $output->writeln('Run <info>composer update</info> to install new versions.');
            return 0;
        }

        $output->writeln('<error>Failed to update composer.json</error>');
        return 1;
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function runInteractiveMode(
        array $updates,
        string $composerJsonPath,
        ComposerJsonUpdater $jsonUpdater,
        InputInterface $input,
        OutputInterface $output
    ): int {
        if (!$input->isInteractive()) {
            $output->writeln('<error>Interactive mode requires a TTY</error>');
            return 1;
        }

        $selected = $this->interactiveSelect($updates, $output);

        if (empty($selected)) {
            $output->writeln('No packages selected.');
            return 0;
        }

        return $this->performUpgrade($selected, $composerJsonPath, $jsonUpdater, $output);
    }

    /**
     * @param PackageUpdate[] $updates
     * @return PackageUpdate[]
     */
    private function interactiveSelect(array $updates, OutputInterface $output): array
    {
        $selectedIndices = array_fill_keys(array_keys($updates), true);
        $currentIndex = 0;
        $total = count($updates);

        // Save terminal state
        system('stty -echo -icanon');

        try {
            while (true) {
                // Clear screen and move to top
                $output->write("\033[2J\033[H");

                $this->renderInteractiveList($updates, $selectedIndices, $currentIndex, $output);

                $output->writeln('');
                $output->writeln('<comment>↑/↓</comment> Navigate  <comment>Space</comment> Toggle  <comment>a</comment> Toggle all  <comment>Enter</comment> Confirm  <comment>q</comment> Cancel');

                // Read single character
                $char = $this->readKey();

                switch ($char) {
                    case 'A': // Up arrow (escape sequence)
                    case 'k':
                        $currentIndex = ($currentIndex - 1 + $total) % $total;
                        break;

                    case 'B': // Down arrow (escape sequence)
                    case 'j':
                        $currentIndex = ($currentIndex + 1) % $total;
                        break;

                    case ' ': // Space - toggle selection
                        $selectedIndices[$currentIndex] = !$selectedIndices[$currentIndex];
                        break;

                    case 'a': // Toggle all
                        $allSelected = !in_array(false, $selectedIndices, true);
                        $selectedIndices = array_fill_keys(array_keys($updates), !$allSelected);
                        break;

                    case "\n": // Enter - confirm
                    case "\r":
                        return array_values(array_filter(
                            $updates,
                            fn($_, $index) => $selectedIndices[$index],
                            ARRAY_FILTER_USE_BOTH
                        ));

                    case 'q': // Quit
                    case "\033": // Escape
                        return [];
                }
            }
        } finally {
            // Restore terminal state
            system('stty echo icanon');
        }
    }

    private function readKey(): string
    {
        $char = fread(STDIN, 1);

        // Handle escape sequences (arrow keys)
        if ($char === "\033") {
            $next = fread(STDIN, 2);
            if (strlen($next) === 2 && $next[0] === '[') {
                return $next[1]; // Returns A, B, C, or D for arrows
            }
            return "\033"; // Just escape key
        }

        return $char ?: '';
    }

    /**
     * @param PackageUpdate[] $updates
     * @param array<int, bool> $selected
     */
    private function renderInteractiveList(
        array $updates,
        array $selected,
        int $currentIndex,
        OutputInterface $output
    ): void {
        $output->writeln('<info>Select packages to update:</info>');
        $output->writeln('');

        // Group updates by type for display
        $grouped = ['major' => [], 'minor' => [], 'patch' => []];
        foreach ($updates as $index => $update) {
            $grouped[$update->updateType][] = ['update' => $update, 'index' => $index];
        }

        $displayIndex = 0;
        foreach (['major', 'minor', 'patch'] as $type) {
            if (empty($grouped[$type])) {
                continue;
            }

            $color = match ($type) {
                'major' => self::COLOR_MAJOR,
                'minor' => self::COLOR_MINOR,
                'patch' => self::COLOR_PATCH,
                default => 'white',
            };

            $output->writeln(sprintf('<fg=%s>%s %s</>', $color, ucfirst($type), $type === 'patch' ? 'Updates' : 'Upgrades'));

            foreach ($grouped[$type] as $item) {
                $update = $item['update'];
                $index = $item['index'];
                $isSelected = $selected[$index];
                $isCurrent = $index === $currentIndex;

                $checkbox = $isSelected ? '[x]' : '[ ]';
                $pointer = $isCurrent ? '>' : ' ';

                $line = sprintf(
                    '%s %s %-35s  %12s  →  %-12s',
                    $pointer,
                    $checkbox,
                    $update->name,
                    $update->currentConstraint,
                    $update->getNewConstraint()
                );

                if ($isCurrent) {
                    $output->writeln('<fg=black;bg=white>' . $line . '</>');
                } else {
                    $output->writeln(sprintf('  <fg=%s>%s %s  %s  →  %s</>',
                        $color,
                        $checkbox,
                        str_pad($update->name, 35),
                        str_pad($update->currentConstraint, 12, ' ', STR_PAD_LEFT),
                        str_pad($update->getNewConstraint(), 12)
                    ));
                }
                $displayIndex++;
            }
            $output->writeln('');
        }

        $selectedCount = count(array_filter($selected));
        $output->writeln(sprintf('<comment>%d of %d packages selected</comment>', $selectedCount, count($updates)));
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function outputJson(array $updates, OutputInterface $output): int
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

        return 0;
    }
}
