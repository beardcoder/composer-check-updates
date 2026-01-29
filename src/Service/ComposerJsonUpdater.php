<?php

declare(strict_types=1);

namespace Beardcoder\ComposerCheckUpdates\Service;

use Netresearch\ComposerCheckUpdates\Model\PackageUpdate;

final class ComposerJsonUpdater
{
    /**
     * Update composer.json with new package versions
     *
     * @param PackageUpdate[] $updates
     */
    public function update(string $composerJsonPath, array $updates): bool
    {
        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return false;
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            return false;
        }

        foreach ($updates as $update) {
            $section = $update->isDev ? 'require-dev' : 'require';

            if (isset($json[$section][$update->name])) {
                $json[$section][$update->name] = $update->getNewConstraint();
            }
        }

        $newContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($newContent === false) {
            return false;
        }

        // Preserve the original file's trailing newline
        if (str_ends_with($content, "\n")) {
            $newContent .= "\n";
        }

        return file_put_contents($composerJsonPath, $newContent) !== false;
    }

    /**
     * Read composer.json and return the require and require-dev sections
     *
     * @return array{require: array<string, string>, require-dev: array<string, string>}|null
     */
    public function readDependencies(string $composerJsonPath): ?array
    {
        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return null;
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            return null;
        }

        return [
            'require' => $json['require'] ?? [],
            'require-dev' => $json['require-dev'] ?? [],
        ];
    }

    /**
     * Read composer.lock and return its contents
     *
     * @return array<string, mixed>|null
     */
    public function readLockFile(string $composerLockPath): ?array
    {
        if (!file_exists($composerLockPath)) {
            return null;
        }

        $content = file_get_contents($composerLockPath);
        if ($content === false) {
            return null;
        }

        $json = json_decode($content, true);
        if (!is_array($json)) {
            return null;
        }

        return $json;
    }
}
