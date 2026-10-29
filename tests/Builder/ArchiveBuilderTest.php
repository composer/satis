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

use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Downloader\DownloaderInterface;
use Composer\Downloader\DownloadManager;
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Loop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Michael Lee <michael.lee@zerustech.com>
 */
class ArchiveBuilderTest extends TestCase
{
    protected Composer $composer;

    protected NullOutput $output;

    protected InputInterface $input;

    protected string $outputDir;

    /**
     * @var array<string, mixed>
     */
    protected array $satisConfig;

    protected string $root;

    protected string $home;

    protected string $target;

    /**
     * Downloads and waits on the loop, in the order they happened.
     *
     * @var list<string>
     */
    private array $events = [];

    /**
     * The directories the file downloader was asked to download into.
     *
     * @var list<string>
     */
    private array $downloadDirs = [];

    public function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->root);
    }

    public function setUp(): void
    {
        $this->root = __DIR__ . '/vfs';
        $this->home = $this->root . '/home/ubuntu';
        $this->target = $this->home . '/satis.server/dist/monolog/monolog';

        $composerConfig = new Config(true, $this->home . '/satis.server');
        $composerConfig->merge([
            'config' => [
                'cache-dir' => $this->home . '/.cache/composer',
                'data-dir' => $this->home . '/.local/share/composer',
                'home' => $this->home . '/.config/composer',
            ],
        ]);
        $composerConfig->setConfigSource(new JsonConfigSource(new JsonFile($this->home . '/.config/composer/config.json')));
        $composerConfig->setAuthConfigSource(new JsonConfigSource(new JsonFile($this->home . '/.config/composer/auth.json')));

        $downloadManager = $this->getMockBuilder(DownloadManager::class)->disableOriginalConstructor()->getMock();
        $downloadManager->method('download')->willReturnCallback(
            function ($package, $source) {
                $filesystem = new Filesystem();
                $filesystem->dumpFile(realpath($source) . '/README.md', '# The demo archive.');
            }
        );

        $loop = $this->getMockBuilder(Loop::class)->disableOriginalConstructor()->getMock();
        $archiveManager = new class($downloadManager, $loop) extends ArchiveManager {
            public function __construct(DownloadManager $downloadManager, Loop $loop)
            {
                parent::__construct($downloadManager, $loop);
            }

            public function archive(CompletePackageInterface $package, string $format, string $targetDir, ?string $fileName = null, bool $ignoreFilters = false): string
            {
                $target = $targetDir.'/'.$this->getPackageFilename($package).'.'.$format;
                touch($target);

                return $target;
            }
        };

        $this->composer = new Composer();
        $this->composer->setConfig($composerConfig);
        $this->composer->setDownloadManager($downloadManager);
        $this->composer->setArchiveManager($archiveManager);

        $this->input = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->disableOriginalConstructor()->getMock();
        $this->input->method('getOption')->with('stats')->willReturn(false);

        $this->output = new NullOutput();

        $this->outputDir = $this->home . '/satis.server';

        $this->satisConfig = [
            'name' => 'monolog/monolog',
            'homepage' => 'https://github.com/Seldaek/monolog.git',
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://packagist.org'],
            ],
            'require' => [
                'monolog/monolog' => '1.13.0',
            ],
            'config' => [
                'secure-http' => false,
            ],
            'require-dependencies' => false,
            'require-dev-dependencies' => false,
            'archive' => [
                'directory' => 'dist',
                'format' => 'tar',
                'prefix-url' => 'http://satis.localhost:4680',
                'skip-dev' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $customConfig
     * @param array<PackageInterface> $packages
     */
    #[DataProvider('getDataForTestDump')]
    public function testDumpWithDownloadedArchives(array $customConfig, array $packages, string $expectedFileName): void
    {
        $this->initArchives();

        $config = array_merge_recursive($this->satisConfig, $customConfig);

        $builder = new ArchiveBuilder($this->output, $this->outputDir, $config, true);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);
        $builder->dump($packages);

        self::assertSame($expectedFileName, basename((string) $packages[0]->getDistUrl()));
    }

    public function testDumpCachesArchiveChecksumsInComposerCache(): void
    {
        $this->initArchives();
        $packages = self::getPackages();

        $builder = new ArchiveBuilder($this->output, $this->outputDir, $this->satisConfig, true);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);
        $builder->dump($packages);

        $cacheFiles = glob($this->home . '/.cache/composer/satis/archive-checksums-*.json');
        self::assertIsArray($cacheFiles);
        self::assertCount(1, $cacheFiles);
        $entries = json_decode((string) file_get_contents($cacheFiles[0]), true);
        self::assertIsArray($entries);
        self::assertSame([$packages[0]->getDistSha1Checksum()], array_column($entries, 'sha1'));
    }

    /**
     * @return array<CompletePackage>
     */
    private static function getPackages(): array
    {
        $package = new CompletePackage('monolog/monolog', '1.13.0.0', '1.13.0');
        $package->setId(9);
        $package->setType('library');
        $package->setSourceType('git');
        $package->setSourceUrl('https://github.com/Seldaek/monolog.git');
        $package->setSourceReference('c41c218e239b50446fd883acb1ecfd4b770caeae');
        $package->setDistType('zip');
        $package->setDistUrl('https://api.github.com/repos/Seldaek/monolog/zipball/');
        $package->setDistReference('c41c218e239b50446fd883acb1ecfd4b770caeae');
        $package->setReleaseDate(new \DateTime('2015-03-05T01:12:12+00:00'));
        $package->setExtra(
            [
                'branch-alias' => [
                    'dev-master' => '1.13.x-dev',
                ],
            ]
        );
        $package->setNotificationUrl('https://packagist.org/downloads/');

        return [$package];
    }

    /**
     * @return array<mixed>
     */
    public static function getDataForTestDump(): array
    {
        return [
            [[], self::getPackages(), 'monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar'],
            [['archive' => ['override-dist-type' => false]], self::getPackages(), 'monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar'],
            [['archive' => ['override-dist-type' => true]], self::getPackages(), 'monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-tar-d4a976.tar'],
        ];
    }

    /**
     * @param array<string, mixed> $customConfig
     * @param array<PackageInterface> $packages
     */
    #[DataProvider('getDataForTestDump')]
    public function testDumpWithoutDownloadedArchives(array $customConfig, array $packages, string $expectedFileName): void
    {
        $this->removeArchives();

        $config = array_merge_recursive($this->satisConfig, $customConfig);
        $builder = new ArchiveBuilder($this->output, $this->outputDir, $config, true);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);
        $builder->dump($packages);

        self::assertSame($expectedFileName, basename((string) $packages[0]->getDistUrl()));
    }

    public function testDumpDownloadsMissingDistArchivesConcurrently(): void
    {
        $packages = self::getDistPackages();
        $this->useFileDownloader(static fn (): bool => true);

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertSame(['download', 'download', 'download', 'wait 3'], $this->getEventNames());
        foreach ($packages as $package) {
            self::assertFileExists($this->getDistArchivePath($package));
            self::assertSame(basename($this->getDistArchivePath($package)), basename((string) $package->getDistUrl()));
        }
    }

    public function testDumpDoesNotDownloadExistingDistArchives(): void
    {
        $packages = self::getDistPackages();
        $this->useFileDownloader(static fn (): bool => true);
        $existingArchive = $this->getDistArchivePath($packages[0]);
        (new Filesystem())->dumpFile($existingArchive, 'the package archive.');

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertSame(['download', 'download', 'wait 2'], $this->getEventNames());
        self::assertNotContains('download 1.0.0', $this->events);
        self::assertStringEqualsFile($existingArchive, 'the package archive.');
    }

    public function testDumpDownloadsSharedDistArchivesOnce(): void
    {
        $packages = self::getDistPackages();
        // A branch pointing to the same commit as a tag resolves to the same archive
        $branch = clone $packages[2];
        $branch->replaceVersion('dev-main', 'dev-main');
        $packages[] = $branch;
        $this->useFileDownloader(static fn (): bool => true);

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertSame(['download', 'download', 'download', 'wait 3'], $this->getEventNames());
        self::assertSame($packages[2]->getDistUrl(), $packages[3]->getDistUrl());
    }

    public function testDumpTriesOtherDistUrlOfSharedDistArchiveIfDownloadFails(): void
    {
        $packages = self::getDistPackages();
        $branch = clone $packages[2];
        $branch->replaceVersion('dev-main', 'dev-main');
        $branch->setDistUrl('https://example.org/monolog/monolog/dev-main.zip');
        $packages[] = $branch;
        $distUrls = [$packages[2]->getDistUrl(), $branch->getDistUrl()];
        // Packages are dumped in random order, so let whichever of the two URLs is tried first fail
        $attempted = [];
        $this->useFileDownloader(static function (PackageInterface $package) use ($distUrls, &$attempted): bool {
            if (!in_array($package->getDistUrl(), $distUrls, true)) {
                return true;
            }
            $attempted[] = $package->getDistUrl();

            return count($attempted) > 1;
        });

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertSame(['download', 'download', 'download', 'wait 3', 'download'], $this->getEventNames());
        self::assertEqualsCanonicalizing($distUrls, $attempted);
        $archive = basename($this->getDistArchivePath($branch));
        self::assertFileExists($this->getDistArchivePath($branch));
        self::assertSame($archive, basename((string) $packages[2]->getDistUrl()));
        self::assertSame($archive, basename((string) $branch->getDistUrl()));
    }

    public function testDumpRemovesTemporaryDownloadDirIfArchiveCannotBeMoved(): void
    {
        $packages = self::getDistPackages();
        (new Filesystem())->dumpFile($this->target, 'not a directory');
        $this->useFileDownloader(static fn (): bool => true);

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertCount(3, $this->downloadDirs);
        foreach ($this->downloadDirs as $downloadDir) {
            self::assertDirectoryDoesNotExist($downloadDir);
        }
    }

    public function testDumpDoesNotRetryFailedDownloads(): void
    {
        $packages = self::getDistPackages();
        $this->useFileDownloader(static fn (PackageInterface $package): bool => '1.1.0' !== $package->getPrettyVersion());

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertSame(['download', 'download', 'download', 'wait 3', 'wait 1'], $this->getEventNames());
    }

    public function testDumpFailsIfDownloadFails(): void
    {
        $this->useFileDownloader(static fn (PackageInterface $package): bool => '1.1.0' !== $package->getPrettyVersion());

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Download of 1.1.0 failed');

        $this->createDistArchiveBuilder(false)->dump(self::getDistPackages());
    }

    public function testDumpSkipsFailedDownloadsIfErrorsAreSkipped(): void
    {
        $packages = self::getDistPackages();
        $this->useFileDownloader(static fn (PackageInterface $package): bool => '1.1.0' !== $package->getPrettyVersion());

        $this->createDistArchiveBuilder()->dump($packages);

        foreach ($packages as $package) {
            if ('1.1.0' === $package->getPrettyVersion()) {
                self::assertFileDoesNotExist($this->getDistArchivePath($package));
            } else {
                self::assertFileExists($this->getDistArchivePath($package));
            }
        }
    }

    public function testDumpSkipsDownloadsFailingImmediatelyIfErrorsAreSkipped(): void
    {
        $packages = self::getDistPackages();
        $packages[1]->setDistUrl(null);
        $this->useFileDownloader(static fn (): bool => true);

        $this->createDistArchiveBuilder()->dump($packages);

        self::assertSame(['download', 'download', 'wait 3', 'wait 1'], $this->getEventNames());
        self::assertFileExists($this->getDistArchivePath($packages[0]));
        self::assertFileDoesNotExist($this->getDistArchivePath($packages[1]));
        self::assertFileExists($this->getDistArchivePath($packages[2]));
        foreach ($this->downloadDirs as $downloadDir) {
            self::assertDirectoryDoesNotExist($downloadDir);
        }
    }

    public function testDumpFailsIfDownloadFailsImmediately(): void
    {
        $packages = self::getDistPackages();
        $packages[1]->setDistUrl(null);
        $this->useFileDownloader(static fn (): bool => true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The given package is missing url information');

        $this->createDistArchiveBuilder(false)->dump($packages);
    }

    public function testDumpSkipsPackagesWithUnusableTargetDirIfErrorsAreSkipped(): void
    {
        $packages = self::getDistPackages();
        $package = new CompletePackage('vendor/blocked', '1.0.0.0', '1.0.0');
        $package->setType('library');
        $package->setDistType('zip');
        $package->setDistUrl('https://example.org/vendor/blocked.zip');
        $packages[] = $package;
        (new Filesystem())->dumpFile($this->outputDir . '/dist/vendor/blocked', 'not a directory');

        $builder = new ArchiveBuilder($this->output, $this->outputDir, $this->satisConfig, true);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);
        $builder->dump($packages);

        self::assertStringEqualsFile($this->outputDir . '/dist/vendor/blocked', 'not a directory');
        foreach (self::getDistPackages() as $distPackage) {
            self::assertFileExists($this->target . '/' . $this->composer->getArchiveManager()->getPackageFilename($distPackage) . '.tar');
        }
    }

    /**
     * @return list<CompletePackage>
     */
    private static function getDistPackages(): array
    {
        $packages = [];
        foreach (['1.0.0', '1.1.0', '1.2.0'] as $version) {
            $reference = sha1($version);
            $package = new CompletePackage('monolog/monolog', $version . '.0', $version);
            $package->setType('library');
            $package->setSourceType('git');
            $package->setSourceUrl('https://github.com/Seldaek/monolog.git');
            $package->setSourceReference($reference);
            $package->setDistType('zip');
            $package->setDistUrl('https://api.github.com/repos/Seldaek/monolog/zipball/' . $reference);
            $package->setDistReference($reference);
            $packages[] = $package;
        }

        return $packages;
    }

    private function createDistArchiveBuilder(bool $skipErrors = true): ArchiveBuilder
    {
        $config = array_replace_recursive($this->satisConfig, ['archive' => ['rearchive' => false]]);

        $builder = new ArchiveBuilder($this->output, $this->outputDir, $config, $skipErrors);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);

        return $builder;
    }

    /**
     * Replaces the file downloader and the loop with doubles recording their calls. Like Composer's curl downloader,
     * downloads only complete while the loop waits, and the loop rethrows rejections like Composer's loop does.
     *
     * @param callable(PackageInterface): bool $succeeds
     */
    private function useFileDownloader(callable $succeeds): void
    {
        $events = &$this->events;
        $downloadDirs = &$this->downloadDirs;
        $pending = [];

        $downloader = $this->createMock(DownloaderInterface::class);
        $downloader->method('download')->willReturnCallback(
            static function (PackageInterface $package, string $downloadDir) use ($succeeds, &$events, &$downloadDirs, &$pending): PromiseInterface {
                $downloadDirs[] = $downloadDir;
                if (null === $package->getDistUrl()) {
                    throw new \InvalidArgumentException('The given package is missing url information');
                }

                $events[] = 'download ' . $package->getPrettyVersion();
                $deferred = new Deferred();
                $pending[] = static function () use ($succeeds, $package, $downloadDir, $deferred): void {
                    if (!$succeeds($package)) {
                        $deferred->reject(new TransportException('Download of ' . $package->getPrettyVersion() . ' failed'));

                        return;
                    }

                    $file = $downloadDir . '/' . $package->getPrettyVersion() . '.zip';
                    (new Filesystem())->dumpFile($file, 'the downloaded archive of ' . $package->getPrettyVersion());
                    $deferred->resolve($file);
                };

                return $deferred->promise();
            }
        );

        $downloadManager = $this->createMock(DownloadManager::class);
        $downloadManager->method('getDownloader')->with('file')->willReturn($downloader);

        $loop = $this->createMock(Loop::class);
        $loop->method('wait')->willReturnCallback(
            static function (array $promises) use (&$events, &$pending): void {
                $events[] = 'wait ' . count($promises);
                // Completing a download can start another one, which completes during the same wait
                while ([] !== $pending) {
                    $complete = array_shift($pending);
                    $complete();
                }
                $uncaught = null;
                foreach ($promises as $promise) {
                    $promise->then(null, static function (\Throwable $exception) use (&$uncaught): void {
                        $uncaught = $exception;
                    });
                }
                if (null !== $uncaught) {
                    throw $uncaught;
                }
            }
        );

        $this->composer->setDownloadManager($downloadManager);
        $this->composer->setLoop($loop);
    }

    /**
     * Returns the recorded events without the package versions, as packages are dumped in random order.
     *
     * @return list<string>
     */
    private function getEventNames(): array
    {
        return array_map(static fn (string $event): string => (string) preg_replace('/^download .+$/', 'download', $event), $this->events);
    }

    private function getDistArchivePath(CompletePackageInterface $package): string
    {
        return $this->target . '/' . $this->composer->getArchiveManager()->getPackageFilename($package) . '.zip';
    }

    private function initArchives(): void
    {
        $fs = new Filesystem();
        $fs->mkdir($this->target, 0777);
        $fs->dumpFile($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar', 'the package archive.');
        $fs->dumpFile($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-tar-d4a976.tar', 'the package archive.');
    }

    private function removeArchives(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar');
        $fs->remove($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-tar-d4a976.tar');
    }
}
