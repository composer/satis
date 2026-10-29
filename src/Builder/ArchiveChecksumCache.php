<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Builder;

use Composer\Cache;
use Composer\Util\Filesystem;

/**
 * Caches the SHA-1 checksums of archives, so existing archives aren't read again on every build.
 *
 * A cached checksum is only used while size and modification time of the archive are unchanged. Satis never rewrites
 * existing archives, so the checksums stay identical to freshly computed ones. The checksums are stored in Composer's
 * cache, one file per archive directory.
 */
final class ArchiveChecksumCache
{
    /** @var array<string, array{size: int, mtime: int, sha1: string}> */
    private array $entries = [];

    private bool $changed = false;

    private ?string $directory = null;

    private string $cacheFile = '';

    public function __construct(private readonly Cache $cache, private readonly string $archiveDirectory)
    {
    }

    public function getChecksum(string $path): ?string
    {
        if (!$this->initialize()) {
            $sha1 = hash_file('sha1', $path);

            return is_string($sha1) ? $sha1 : null;
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        $mtime = filemtime($path);
        if (false === $size || false === $mtime) {
            return null;
        }

        $key = $this->getKey($path);
        $entry = $this->entries[$key] ?? null;
        if (null !== $entry && $entry['size'] === $size && $entry['mtime'] === $mtime) {
            return $entry['sha1'];
        }

        $sha1 = hash_file('sha1', $path);
        if (!is_string($sha1)) {
            return null;
        }

        $this->entries[$key] = ['size' => $size, 'mtime' => $mtime, 'sha1' => $sha1];
        $this->changed = true;

        return $sha1;
    }

    /**
     * Writes the cache, dropping entries of archives that no longer exist. Entries of archives not used in this build
     * are kept, as partial builds only process some packages.
     */
    public function save(): void
    {
        if (!$this->initialize()) {
            return;
        }

        foreach (array_keys($this->entries) as $key) {
            if (!is_file($this->getPath($key))) {
                unset($this->entries[$key]);
                $this->changed = true;
            }
        }

        if (!$this->changed) {
            return;
        }

        ksort($this->entries);
        $json = json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        // Composer's cache writes atomically and respects disabled or read-only caches
        if (false !== $json && $this->cache->write($this->cacheFile, $json . "\n")) {
            $this->changed = false;
        }
    }

    /**
     * Resolves the archive directory and loads its cache on first use. The directory may not exist before the first
     * archive is created, and archive paths are resolved by realpath(), while the configured directory may be relative
     * or a symlink.
     */
    private function initialize(): bool
    {
        if (null !== $this->directory) {
            return true;
        }

        $directory = realpath($this->archiveDirectory);
        if (false === $directory) {
            return false;
        }

        $this->directory = $directory;
        $this->cacheFile = 'archive-checksums-' . hash('sha1', $directory) . '.json';

        $contents = $this->cache->read($this->cacheFile);
        $entries = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($entries)) {
            return true;
        }

        // Ignore malformed entries, they are recomputed on demand
        foreach ($entries as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_int($entry['size'] ?? null) && is_int($entry['mtime'] ?? null) && is_string($entry['sha1'] ?? null)) {
                $this->entries[$key] = ['size' => $entry['size'], 'mtime' => $entry['mtime'], 'sha1' => $entry['sha1']];
            }
        }

        return true;
    }

    /**
     * Archives inside the archive directory are stored relative to it, archives outside (e.g. in a symlinked package
     * directory) by their absolute path.
     */
    private function getKey(string $path): string
    {
        $prefix = $this->directory . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function getPath(string $key): string
    {
        return new Filesystem()->isAbsolutePath($key) ? $key : $this->directory . DIRECTORY_SEPARATOR . $key;
    }
}
