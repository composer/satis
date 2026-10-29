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
use Composer\IO\NullIO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class ArchiveChecksumCacheTest extends TestCase
{
    private string $root;

    private string $archiveDirectory;

    private string $cacheDirectory;

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/satis-checksum-cache-' . bin2hex(random_bytes(4));
        $this->archiveDirectory = $this->root . '/dist';
        $this->cacheDirectory = $this->root . '/cache';
        (new Filesystem())->mkdir([$this->archiveDirectory . '/vendor/package', $this->cacheDirectory]);
    }

    public function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testChecksumMatchesArchiveContents(): void
    {
        $archive = $this->createArchive('vendor/package/package-1.0.0.zip', 'archive contents');

        self::assertSame(sha1('archive contents'), $this->createCache()->getChecksum($archive));
    }

    public function testCachedChecksumIsUsedForUnchangedArchive(): void
    {
        $archive = $this->createArchive('vendor/package/package-1.0.0.zip', 'archive contents');
        $cache = $this->createCache();
        $cache->getChecksum($archive);
        $cache->save();

        // Same size and modification time: the archive is considered unchanged and isn't read again
        $mtime = (int) filemtime($archive);
        file_put_contents($archive, 'ARCHIVE CONTENTS');
        touch($archive, $mtime);

        self::assertSame(sha1('archive contents'), $this->createCache()->getChecksum($archive));
    }

    public function testChecksumIsRecomputedForChangedArchive(): void
    {
        $archive = $this->createArchive('vendor/package/package-1.0.0.zip', 'archive contents');
        $cache = $this->createCache();
        $cache->getChecksum($archive);
        $cache->save();

        file_put_contents($archive, 'changed archive contents');
        touch($archive, (int) filemtime($archive) + 1);

        self::assertSame(sha1('changed archive contents'), $this->createCache()->getChecksum($archive));
    }

    public function testSaveKeepsEntriesOfArchivesNotUsedInThisBuild(): void
    {
        $first = $this->createArchive('vendor/package/package-1.0.0.zip', 'first');
        $second = $this->createArchive('vendor/package/package-2.0.0.zip', 'second');
        $cache = $this->createCache();
        $cache->getChecksum($first);
        $cache->getChecksum($second);
        $cache->save();

        // A partial build only processes some archives
        $cache = $this->createCache();
        $cache->getChecksum($first);
        $cache->save();

        self::assertSame(['vendor/package/package-1.0.0.zip', 'vendor/package/package-2.0.0.zip'], array_keys($this->readCacheFile()));
    }

    public function testSaveDropsEntriesOfRemovedArchives(): void
    {
        $first = $this->createArchive('vendor/package/package-1.0.0.zip', 'first');
        $second = $this->createArchive('vendor/package/package-2.0.0.zip', 'second');
        $cache = $this->createCache();
        $cache->getChecksum($first);
        $cache->getChecksum($second);
        $cache->save();

        unlink($second);
        $this->createCache()->save();

        self::assertSame(['vendor/package/package-1.0.0.zip'], array_keys($this->readCacheFile()));
    }

    public function testMalformedCacheIsIgnored(): void
    {
        $archive = $this->createArchive('vendor/package/package-1.0.0.zip', 'archive contents');
        $cache = $this->createCache();
        $cache->getChecksum($archive);
        $cache->save();
        file_put_contents($this->getCacheFile(), '{"vendor/package/package-1.0.0.zip": {"size": "16", "sha1": 1}');

        self::assertSame(sha1('archive contents'), $this->createCache()->getChecksum($archive));
    }

    public function testReadOnlyCacheIsNotWritten(): void
    {
        $archive = $this->createArchive('vendor/package/package-1.0.0.zip', 'archive contents');
        $cache = $this->createCache(true);

        self::assertSame(sha1('archive contents'), $cache->getChecksum($archive));
        $cache->save();
        self::assertSame([], glob($this->cacheDirectory . '/archive-checksums-*.json'));
    }

    public function testCacheIsSharedBetweenSymlinkedAndRealArchiveDirectory(): void
    {
        $archive = $this->createArchive('vendor/package/package-1.0.0.zip', 'archive contents');
        $link = $this->root . '/dist-link';
        symlink($this->archiveDirectory, $link);

        $cache = $this->createCache(false, $link);
        $cache->getChecksum($archive);
        $cache->save();

        self::assertSame(['vendor/package/package-1.0.0.zip'], array_keys($this->readCacheFile()));
    }

    public function testArchiveInSymlinkedPackageDirectoryIsKeptOnSave(): void
    {
        // realpath() in ArchiveBuilder resolves a symlinked package directory to a path outside the archive directory
        $external = $this->root . '/external';
        (new Filesystem())->mkdir($external);
        symlink($external, $this->archiveDirectory . '/vendor/linked');
        $archive = $this->createArchive('vendor/linked/linked-1.0.0.zip', 'archive contents');

        $cache = $this->createCache();
        $cache->getChecksum($archive);
        $cache->save();
        $this->createCache()->save();

        self::assertSame([$archive], array_keys($this->readCacheFile()));
    }

    public function testArchiveDirectoryCreatedAfterConstructionIsCached(): void
    {
        // On the first build, the archive directory is created along with the first archive
        $directory = $this->root . '/new-dist';
        $cache = $this->createCache(false, $directory);
        (new Filesystem())->mkdir($directory . '/vendor/package');
        file_put_contents($directory . '/vendor/package/package-1.0.0.zip', 'archive contents');

        self::assertSame(sha1('archive contents'), $cache->getChecksum((string) realpath($directory . '/vendor/package/package-1.0.0.zip')));
        $cache->save();

        self::assertSame(['vendor/package/package-1.0.0.zip'], array_keys($this->readCacheFile()));
    }

    private function createCache(bool $readOnly = false, ?string $archiveDirectory = null): ArchiveChecksumCache
    {
        $cache = new Cache(new NullIO(), $this->cacheDirectory);
        $cache->setReadOnly($readOnly);

        return new ArchiveChecksumCache($cache, $archiveDirectory ?? $this->archiveDirectory);
    }

    private function createArchive(string $path, string $contents): string
    {
        $archive = $this->archiveDirectory . '/' . $path;
        file_put_contents($archive, $contents);

        // Archives are resolved by realpath() in ArchiveBuilder
        return (string) realpath($archive);
    }

    private function getCacheFile(): string
    {
        $files = glob($this->cacheDirectory . '/archive-checksums-*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        return $files[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function readCacheFile(): array
    {
        $entries = json_decode((string) file_get_contents($this->getCacheFile()), true);
        self::assertIsArray($entries);

        return $entries;
    }
}
