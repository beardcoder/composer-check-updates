<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Model;

final class PackageUpdate
{
    public const TYPE_MAJOR = 'major';
    public const TYPE_MINOR = 'minor';
    public const TYPE_PATCH = 'patch';
    public const TYPE_PRERELEASE = 'prerelease';

    public function __construct(
        public readonly string $name,
        public readonly string $currentConstraint,
        public readonly string $currentVersion,
        public readonly string $latestVersion,
        public readonly string $updateType,
        public readonly bool $isDev = false,
    ) {}

    public function getNewConstraint(): string
    {
        $constraint = $this->currentConstraint;

        // Handle complex constraints (||, spaces, commas)
        if (preg_match('/[|,\s]/', $constraint)) {
            return '^' . $this->latestVersion;
        }

        // Match the constraint prefix pattern
        // Supports: ^, ~, >=, >, <=, <, !=, =, v, or no prefix
        if (preg_match('/^([~^>=<!]+|v)?(.*)$/', $constraint, $matches)) {
            $prefix = $matches[1] ?? '';
            $versionPart = $matches[2] ?? '';

            // Determine the precision of the original constraint
            $precision = $this->getVersionPrecision($versionPart);

            // Format the new version to match original precision
            $newVersion = $this->formatVersionToPrecision($this->latestVersion, $precision);

            // Special handling for different prefixes
            return match (true) {
                $prefix === '' => $newVersion,
                $prefix === 'v' => 'v' . $newVersion,
                str_starts_with($prefix, '^') => '^' . $newVersion,
                str_starts_with($prefix, '~') => '~' . $newVersion,
                str_starts_with($prefix, '>=') => '>=' . $newVersion,
                str_starts_with($prefix, '>') => '>' . $newVersion,
                default => '^' . $newVersion,
            };
        }

        return '^' . $this->latestVersion;
    }

    /**
     * Determine the precision level of a version string
     * Returns: 1 (major only), 2 (major.minor), 3 (major.minor.patch), 4+ (with prerelease)
     */
    private function getVersionPrecision(string $version): int
    {
        // Remove any prerelease suffix for counting
        $version = preg_replace('/-.*$/', '', $version) ?? $version;
        $version = ltrim($version, 'vV');

        $parts = explode('.', $version);
        $precision = count(array_filter($parts, fn($p) => $p !== '' && $p !== '*'));

        // Check if original had prerelease info
        if (preg_match('/-/', $this->currentConstraint)) {
            return max($precision, 3) + 1;
        }

        return max(1, $precision);
    }

    /**
     * Format version to match a specific precision
     */
    private function formatVersionToPrecision(string $version, int $precision): string
    {
        $version = ltrim($version, 'vV');

        // Split version and prerelease
        $parts = explode('-', $version, 2);
        $versionPart = $parts[0];
        $prerelease = $parts[1] ?? null;

        $versionParts = explode('.', $versionPart);

        // Ensure we have at least 3 parts
        while (count($versionParts) < 3) {
            $versionParts[] = '0';
        }

        // Trim to desired precision
        $result = match ($precision) {
            1 => $versionParts[0],
            2 => $versionParts[0] . '.' . $versionParts[1],
            default => implode('.', array_slice($versionParts, 0, 3)),
        };

        // Add prerelease if precision indicates it
        if ($precision > 3 && $prerelease !== null) {
            $result .= '-' . $prerelease;
        }

        return $result;
    }

    public function hasUpdate(): bool
    {
        return version_compare(
            $this->normalizeVersion($this->currentVersion),
            $this->normalizeVersion($this->latestVersion),
            '<'
        );
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
