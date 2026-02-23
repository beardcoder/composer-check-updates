<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Command;

use Composer\Command\BaseCommand;
use Beardcoder\ComposerCheckUpdates\Model\PackageUpdate;
use Beardcoder\ComposerCheckUpdates\Output\InteractiveSelector;
use Beardcoder\ComposerCheckUpdates\Output\UpdatesRenderer;
use Beardcoder\ComposerCheckUpdates\Service\ComposerJsonUpdater;
use Beardcoder\ComposerCheckUpdates\Service\PackageVersionChecker;
use Beardcoder\ComposerCheckUpdates\Service\VersionComparator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckUpdatesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('check-updates')
            ->setAliases(['ccu'])
            ->setDescription('Check for package updates beyond composer.json constraints')
            ->addOption('upgrade', 'u', InputOption::VALUE_NONE, 'Update composer.json with new versions')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Interactive mode: select which packages to update')
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter packages by name (supports wildcards)')
            ->addOption('reject', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude packages by name (supports wildcards)')
            ->addOption('dev-only', null, InputOption::VALUE_NONE, 'Only check dev dependencies')
            ->addOption('prod-only', null, InputOption::VALUE_NONE, 'Only check production dependencies')
            ->addOption('minor-only', null, InputOption::VALUE_NONE, 'Only show minor and patch updates')
            ->addOption('patch-only', null, InputOption::VALUE_NONE, 'Only show patch updates')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target version: latest, minor, patch (default: latest)')
            ->addOption('working-dir', 'd', InputOption::VALUE_REQUIRED, 'Use the given directory as working directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDir = $input->getOption('working-dir') ?? getcwd();
        $composerJsonPath = $workingDir . '/composer.json';
        $composerLockPath = $workingDir . '/composer.lock';

        if (!file_exists($composerJsonPath)) {
            $output->writeln('<error> composer.json not found in ' . $workingDir . ' </error>');
            return 1;
        }

        $versionComparator = new VersionComparator();
        $versionChecker = new PackageVersionChecker($versionComparator);
        $jsonUpdater = new ComposerJsonUpdater();
        $renderer = new UpdatesRenderer();

        $dependencies = $jsonUpdater->readDependencies($composerJsonPath);
        if ($dependencies === null) {
            $output->writeln('<error> Failed to read composer.json </error>');
            return 1;
        }

        $lockData = $jsonUpdater->readLockFile($composerLockPath) ?? [];
        $packages = $this->collectPackages($dependencies, $input);

        if (empty($packages)) {
            $output->writeln('<info>No packages to check.</info>');
            return 0;
        }

        $updates = $this->checkUpdates($packages, $versionChecker, $versionComparator, $lockData, $output, $input);

        if (empty($updates)) {
            $output->writeln('');
            $output->writeln(' <info>✓ All packages are up to date!</info>');
            return 0;
        }

        if ($input->getOption('json')) {
            $renderer->renderJson($updates, $output);
            return 0;
        }

        if ($input->getOption('interactive')) {
            return $this->runInteractiveMode($updates, $composerJsonPath, $jsonUpdater, $renderer, $input, $output);
        }

        $renderer->render($updates, $output);

        if ($input->getOption('upgrade')) {
            return $this->performUpgrade($updates, $composerJsonPath, $jsonUpdater, $output);
        }

        $output->writeln('');
        $output->writeln(' Run <info>composer ccu -u</info> to upgrade composer.json');
        $output->writeln(' Run <info>composer ccu -i</info> for interactive mode');

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
        if (str_starts_with($name, 'ext-') || $name === 'php') {
            return false;
        }

        if (!str_contains($name, '/')) {
            return false;
        }

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
        InputInterface $input,
    ): array {
        $updates = [];
        $total = count($packages);

        $minorOnly = $input->getOption('minor-only');
        $patchOnly = $input->getOption('patch-only');
        $target = $input->getOption('target') ?? 'latest';

        if (!in_array($target, ['latest', 'minor', 'patch'], true)) {
            $output->writeln('<error> Invalid target. Use: latest, minor, or patch </error>');
            return [];
        }

        $output->writeln('');
        $output->writeln(sprintf(' Checking <info>%d</info> packages for updates...', $total));
        $output->writeln('');

        $packageNames = array_keys($packages);

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %bar%  %current%/%max%  <comment>%message%</comment>');
        $progressBar->setBarCharacter('<fg=green>█</>');
        $progressBar->setEmptyBarCharacter('<fg=gray>░</>');
        $progressBar->setProgressCharacter('<fg=green>█</>');
        $progressBar->setBarWidth(30);
        $progressBar->setMessage('Fetching versions…');
        $progressBar->start();

        $latestVersions = $versionChecker->getLatestVersionsBatch($packageNames, $target);

        $current = 0;
        foreach ($packages as $name => $data) {
            $current++;
            $shortName = strlen($name) > 35 ? substr($name, 0, 32) . '…' : $name;
            $progressBar->setMessage($shortName);
            $progressBar->advance();

            $latestVersion = $latestVersions[$name] ?? null;
            if ($latestVersion === null) {
                continue;
            }

            $currentVersion = $versionChecker->getInstalledVersion($name, $lockData)
                ?? $versionChecker->extractVersionFromConstraint($data['constraint']);

            if ($target !== 'latest') {
                $packageVersions = $versionChecker->getCachedVersions($name);
                if ($packageVersions !== null) {
                    $targetVersion = $versionChecker->findVersionForTarget($packageVersions, $currentVersion, $target);
                    if ($targetVersion !== null) {
                        $latestVersion = $targetVersion;
                    }
                }
            }

            if (!$versionComparator->isVersionNewer($currentVersion, $latestVersion)) {
                continue;
            }

            $updateType = $versionComparator->getUpdateType($currentVersion, $latestVersion);

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
                $data['isDev'],
            );

            if ($update->getNewConstraint() === $data['constraint']) {
                continue;
            }

            $updates[] = $update;
        }

        $progressBar->setMessage('<info>Done!</info>');
        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        return $updates;
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function performUpgrade(
        array $updates,
        string $composerJsonPath,
        ComposerJsonUpdater $jsonUpdater,
        OutputInterface $output,
    ): int {
        $output->writeln('');
        $output->writeln(' Updating composer.json…');

        if ($jsonUpdater->update($composerJsonPath, $updates)) {
            $count = count($updates);
            $output->writeln(sprintf(' <info>✓ Updated %d package%s in composer.json</info>', $count, $count !== 1 ? 's' : ''));
            $output->writeln('');
            $output->writeln(' Run <info>composer update</info> to install new versions.');
            return 0;
        }

        $output->writeln('<error> Failed to update composer.json </error>');
        return 1;
    }

    /**
     * @param PackageUpdate[] $updates
     */
    private function runInteractiveMode(
        array $updates,
        string $composerJsonPath,
        ComposerJsonUpdater $jsonUpdater,
        UpdatesRenderer $renderer,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        if (!$input->isInteractive()) {
            $output->writeln('<error> Interactive mode requires a TTY </error>');
            return 1;
        }

        // Show updates first so the user knows what's available
        $renderer->render($updates, $output);
        $output->writeln('');

        $selector = new InteractiveSelector();
        $selected = $selector->select($updates, $output);

        if (empty($selected)) {
            $output->writeln(' No packages selected.');
            return 0;
        }

        return $this->performUpgrade($selected, $composerJsonPath, $jsonUpdater, $output);
    }
}
