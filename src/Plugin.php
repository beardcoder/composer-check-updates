<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Beardcoder\ComposerCheckUpdates\Command\CommandProvider;

final class Plugin implements PluginInterface, Capable
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // Plugin is activated
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Plugin is deactivated
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Plugin is uninstalled
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }
}
