<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Service;

use Composer\IO\IOInterface;

final class PackageVersionChecker
{
    private const PACKAGIST_API_URL = 'https://repo.packagist.org/p2/%s.json';
    private const CONCURRENT_REQUESTS = 10;

    /** @var array<string, array{versions: array, timestamp: int}> */
    private array $cache = [];

    public function __construct(
        private readonly VersionComparator $versionComparator,
        private readonly ?IOInterface $io = null,
    ) {}

    /**
     * Get the latest stable version for a package from Packagist
     */
    public function getLatestVersion(string $packageName, string $target = 'latest'): ?string
    {
        $versions = $this->fetchPackageVersions($packageName);

        if ($versions === null) {
            return null;
        }

        return $this->findVersionByTarget($versions, $target);
    }

    /**
     * Fetch versions for multiple packages in parallel using curl_multi
     *
     * @param string[] $packageNames
     * @return array<string, string|null> Package name => latest version
     */
    public function getLatestVersionsBatch(array $packageNames, string $target = 'latest'): array
    {
        $results = [];
        $chunks = array_chunk($packageNames, self::CONCURRENT_REQUESTS);

        foreach ($chunks as $chunk) {
            $batchResults = $this->fetchParallel($chunk);
            foreach ($batchResults as $packageName => $versions) {
                $results[$packageName] = $versions !== null
                    ? $this->findVersionByTarget($versions, $target)
                    : null;
            }
        }

        return $results;
    }

    /**
     * Fetch package data from Packagist
     *
     * @return array<array{version: string, version_normalized?: string}>|null
     */
    private function fetchPackageVersions(string $packageName): ?array
    {
        // Check cache first
        if (isset($this->cache[$packageName])) {
            return $this->cache[$packageName]['versions'];
        }

        $url = sprintf(self::PACKAGIST_API_URL, $packageName);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'composer-check-updates/1.0',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data['packages'][$packageName])) {
            return null;
        }

        $versions = $data['packages'][$packageName];
        $this->cache[$packageName] = ['versions' => $versions, 'timestamp' => time()];

        return $versions;
    }

    /**
     * Fetch multiple packages in parallel using curl_multi
     *
     * @param string[] $packageNames
     * @return array<string, array|null>
     */
    private function fetchParallel(array $packageNames): array
    {
        if (!function_exists('curl_multi_init')) {
            // Fallback to sequential if curl_multi not available
            $results = [];
            foreach ($packageNames as $name) {
                $results[$name] = $this->fetchPackageVersions($name);
            }
            return $results;
        }

        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($packageNames as $packageName) {
            // Check cache first
            if (isset($this->cache[$packageName])) {
                continue;
            }

            $url = sprintf(self::PACKAGIST_API_URL, $packageName);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'composer-check-updates/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $handles[$packageName] = $ch;
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect results
        $results = [];
        foreach ($packageNames as $packageName) {
            if (isset($this->cache[$packageName])) {
                $results[$packageName] = $this->cache[$packageName]['versions'];
                continue;
            }

            if (!isset($handles[$packageName])) {
                $results[$packageName] = null;
                continue;
            }

            $ch = $handles[$packageName];
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($multiHandle, $ch);

            if ($httpCode !== 200 || $response === false) {
                $results[$packageName] = null;
                continue;
            }

            $data = json_decode($response, true);
            if (!is_array($data) || !isset($data['packages'][$packageName])) {
                $results[$packageName] = null;
                continue;
            }

            $versions = $data['packages'][$packageName];
            $this->cache[$packageName] = ['versions' => $versions, 'timestamp' => time()];
            $results[$packageName] = $versions;
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Find version based on target (latest, minor, patch)
     *
     * @param array<array{version: string, version_normalized?: string}> $versions
     */
    private function findVersionByTarget(array $versions, string $target, ?string $currentVersion = null): ?string
    {
        return $this->findLatestStableVersion($versions);
    }

    /**
     * Find the best version based on target constraint
     *
     * @param array<array{version: string, version_normalized?: string}> $versions
     */
    public function findVersionForTarget(
        array $versions,
        string $currentVersion,
        string $target
    ): ?string {
        $current = $this->parseVersion($currentVersion);
        if ($current === null) {
            return $this->findLatestStableVersion($versions);
        }

        $candidates = [];

        foreach ($versions as $versionData) {
            $version = $versionData['version'] ?? '';
            $normalized = ltrim($version, 'vV');

            // Skip dev versions
            if (str_starts_with($version, 'dev-') || str_contains($version, 'dev')) {
                continue;
            }

            // Skip pre-release versions
            if (preg_match('/(alpha|beta|rc|RC|a|b)\d*/i', $version)) {
                continue;
            }

            $parsed = $this->parseVersion($normalized);
            if ($parsed === null) {
                continue;
            }

            // Apply target filter
            $validForTarget = match ($target) {
                'patch' => $parsed['major'] === $current['major'] && $parsed['minor'] === $current['minor'],
                'minor' => $parsed['major'] === $current['major'],
                default => true, // 'latest'
            };

            if ($validForTarget) {
                $candidates[] = $normalized;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => version_compare($b, $a));

        return $candidates[0];
    }

    /**
     * Get cached versions for a package (after batch fetch)
     *
     * @return array<array{version: string, version_normalized?: string}>|null
     */
    public function getCachedVersions(string $packageName): ?array
    {
        return $this->cache[$packageName]['versions'] ?? null;
    }

    /**
     * @return array{major: int, minor: int, patch: int}|null
     */
    private function parseVersion(string $version): ?array
    {
        $version = ltrim($version, 'vV');

        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $matches)) {
            return null;
        }

        return [
            'major' => (int) ($matches[1] ?? 0),
            'minor' => (int) ($matches[2] ?? 0),
            'patch' => (int) ($matches[3] ?? 0),
        ];
    }

    /**
     * Extract the actual installed version from a constraint
     */
    public function extractVersionFromConstraint(string $constraint): string
    {
        // Remove common prefixes
        $version = preg_replace('/^[~^>=<!\s|]+/', '', $constraint);

        // Take the first version in case of complex constraints
        if (preg_match('/^[\d]+(?:\.[\d]+)*(?:-[\w.]+)?/', $version ?? '', $matches)) {
            return $matches[0];
        }

        return $constraint;
    }

    /**
     * Get the currently installed version of a package
     */
    public function getInstalledVersion(string $packageName, array $lockData): ?string
    {
        $packages = array_merge(
            $lockData['packages'] ?? [],
            $lockData['packages-dev'] ?? []
        );

        foreach ($packages as $package) {
            if (($package['name'] ?? '') === $packageName) {
                $version = $package['version'] ?? null;
                if ($version !== null) {
                    // Remove 'v' prefix if present
                    return ltrim($version, 'vV');
                }
            }
        }

        return null;
    }

    /**
     * @param array<array{version: string, version_normalized?: string}> $versions
     */
    private function findLatestStableVersion(array $versions): ?string
    {
        $stableVersions = [];

        foreach ($versions as $versionData) {
            $version = $versionData['version'] ?? '';
            $normalized = $versionData['version_normalized'] ?? $version;

            // Skip dev versions
            if (str_starts_with($version, 'dev-') || str_contains($normalized, 'dev')) {
                continue;
            }

            // Skip alpha, beta, RC versions for stable
            if (preg_match('/(alpha|beta|rc|RC|a|b)\d*/i', $version)) {
                continue;
            }

            $stableVersions[] = ltrim($version, 'vV');
        }

        if (empty($stableVersions)) {
            // If no stable versions, return the first non-dev version
            foreach ($versions as $versionData) {
                $version = $versionData['version'] ?? '';
                if (!str_starts_with($version, 'dev-')) {
                    return ltrim($version, 'vV');
                }
            }
            return null;
        }

        // Sort versions and return the latest
        usort($stableVersions, fn($a, $b) => version_compare($b, $a));

        return $stableVersions[0] ?? null;
    }
}
