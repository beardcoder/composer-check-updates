<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Service;

final class VersionComparator
{
    public const TYPE_MAJOR = 'major';
    public const TYPE_MINOR = 'minor';
    public const TYPE_PATCH = 'patch';
    public const TYPE_PRERELEASE = 'prerelease';

    public function getUpdateType(string $currentVersion, string $latestVersion): string
    {
        $current = $this->parseVersion($currentVersion);
        $latest = $this->parseVersion($latestVersion);

        if ($current === null || $latest === null) {
            return self::TYPE_PATCH;
        }

        // Major version 0 is always considered major (unstable API)
        if ($current['major'] === 0 || $latest['major'] > $current['major']) {
            return self::TYPE_MAJOR;
        }

        if ($latest['minor'] > $current['minor']) {
            return self::TYPE_MINOR;
        }

        if ($latest['patch'] > $current['patch']) {
            return self::TYPE_PATCH;
        }

        // Check prerelease
        if ($current['prerelease'] !== '' && $latest['prerelease'] === '') {
            return self::TYPE_PATCH;
        }

        return self::TYPE_PRERELEASE;
    }

    /**
     * @return array{major: int, minor: int, patch: int, prerelease: string}|null
     */
    private function parseVersion(string $version): ?array
    {
        // Remove 'v' prefix
        $version = ltrim($version, 'vV');

        // Match semver pattern
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-(.+))?/', $version, $matches)) {
            return null;
        }

        return [
            'major' => (int) ($matches[1] ?? 0),
            'minor' => (int) ($matches[2] ?? 0),
            'patch' => (int) ($matches[3] ?? 0),
            'prerelease' => $matches[4] ?? '',
        ];
    }

    public function isVersionNewer(string $currentVersion, string $latestVersion): bool
    {
        $current = $this->normalizeVersion($currentVersion);
        $latest = $this->normalizeVersion($latestVersion);

        return version_compare($latest, $current, '>');
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
