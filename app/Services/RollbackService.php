<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Restores the application tree from a pre-update snapshot.
 *
 * The updater does not overwrite files in place. It moves the live tree aside
 * into a snapshot directory and moves the new tree in, so rolling back is a
 * directory-level operation rather than a file-by-file undo. That makes
 * rollback fast, and — more importantly — makes it *complete*: there is no
 * partially-reverted state to reason about.
 *
 * Preserved paths (config, uploads, storage, logs, backups) are never inside
 * either tree swap, so they survive both the update and the rollback untouched.
 */
final class RollbackService
{
    public function __construct(private AuditService $audit)
    {
    }

    /**
     * Restore the application files from a snapshot directory.
     *
     * @return array{success:bool,message:string,restored:int}
     */
    public function restoreFromSnapshot(string $snapshotPath, string $reason): array
    {
        $basePath = (string) Config::get('app.base_path', '');

        if (!is_dir($snapshotPath)) {
            $this->audit->critical(
                'update.rollback_impossible',
                'A rollback was requested but the snapshot directory is missing: ' . $snapshotPath
            );
            return [
                'success' => false,
                'message' => 'The pre-update snapshot is missing, so the files could not be rolled back.',
                'restored' => 0,
            ];
        }

        $preserved = $this->preservedPaths();
        $restored = 0;
        $failures = [];

        try {
            foreach (new \DirectoryIterator($snapshotPath) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $name = $entry->getFilename();
                if ($this->isPreserved($name, $preserved)) {
                    continue;
                }

                $source = $snapshotPath . '/' . $name;
                $target = $basePath . '/' . $name;

                if (is_dir($target) || is_file($target)) {
                    if (!$this->removePath($target)) {
                        $failures[] = $name;
                        continue;
                    }
                }

                if (!@rename($source, $target)) {
                    // Cross-device: fall back to a copy.
                    if (!$this->copyPath($source, $target)) {
                        $failures[] = $name;
                        continue;
                    }
                }

                $restored++;
            }
        } catch (\Throwable $e) {
            Logger::critical('Rollback threw', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'The rollback failed part-way: ' . $e->getMessage(),
                'restored' => $restored,
            ];
        }

        if ($failures !== []) {
            $this->audit->critical(
                'update.rollback_partial',
                'A rollback could not restore every path. The installation may be inconsistent.',
                ['failed_paths' => $failures, 'reason' => $reason]
            );
            return [
                'success' => false,
                'message' => 'The rollback restored ' . $restored . ' path(s) but failed on: '
                    . implode(', ', $failures) . '. Check file permissions and restore manually.',
                'restored' => $restored,
            ];
        }

        $this->clearCaches();

        $this->audit->log(
            'update.rolled_back',
            sprintf('Rolled the application back to its pre-update state (%d paths). Reason: %s', $restored, $reason),
            'update',
            null,
            'critical'
        );

        Logger::critical('Application rolled back', ['restored' => $restored, 'reason' => $reason]);

        return [
            'success' => true,
            'message' => sprintf('Rolled back %d path(s) to the previous version.', $restored),
            'restored' => $restored,
        ];
    }

    /** @return array<int,string> */
    public function preservedPaths(): array
    {
        return (array) Config::get('updates.preserve', []);
    }

    /** @param array<int,string> $preserved */
    public function isPreserved(string $relativePath, array $preserved): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        foreach ($preserved as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern), '/');
            if ($relativePath === $pattern) {
                return true;
            }
            // A preserved directory protects everything inside it...
            if (str_starts_with($relativePath . '/', $pattern . '/')) {
                return true;
            }
            // ...and a preserved file inside a directory protects that directory
            // from being removed wholesale.
            if (str_starts_with($pattern . '/', $relativePath . '/')) {
                return true;
            }
        }

        return false;
    }

    public function removePath(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $ok = $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
            if (!$ok) {
                return false;
            }
        }

        return @rmdir($path);
    }

    public function copyPath(string $source, string $target): bool
    {
        if (is_file($source)) {
            $directory = dirname($target);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                return false;
            }
            return @copy($source, $target);
        }

        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            return false;
        }

        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (!$this->copyPath($entry->getPathname(), $target . '/' . $entry->getFilename())) {
                return false;
            }
        }

        return true;
    }

    /** Clear compiled/cached state so the restored code is what actually runs. */
    public function clearCaches(): void
    {
        $cachePath = (string) Config::get('app.cache_path', '');
        if ($cachePath !== '' && is_dir($cachePath)) {
            foreach (glob($cachePath . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                } elseif (is_dir($file)) {
                    $this->removePath($file);
                }
            }
        }

        // Stale opcache entries would keep serving the pre-rollback code.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }
}
