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
use Composer\Composer;
use Composer\Downloader\DownloadManager;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\SyncHelper;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveBuilder extends Builder
{
    private Composer $composer;

    private InputInterface $input;

    private IOInterface $io;

    /**
     * @param array<PackageInterface> $packages
     */
    public function dump(array $packages): void
    {
        $helper = new ArchiveBuilderHelper($this->output, $this->config['archive']);
        $basedir = $helper->getDirectory($this->outputDir);
        $this->output->writeln(sprintf("<info>Creating local downloads in '%s'</info>", $basedir));
        $endpoint = $this->config['archive']['prefix-url'] ?? $this->config['homepage'];
        $includeArchiveChecksum = (bool) ($this->config['archive']['checksum'] ?? true);
        $composerConfig = $this->composer->getConfig();
        $factory = new Factory();
        /** @var DownloadManager $downloadManager */
        $downloadManager = $this->composer->getDownloadManager();
        /** @var ArchiveManager $archiveManager */
        $archiveManager = $this->composer->getArchiveManager();
        $archiveManager->setOverwriteFiles(false);
        $checksumCache = $this->createChecksumCache($basedir);

        shuffle($packages);

        $this->prefetchDistArchives($helper, $downloadManager, $archiveManager, $packages, $basedir);

        /** @var ProgressBar|null $progressBar Should only remain `null` if $renderProgress is `false` */
        $progressBar = null;
        $hasStarted = false;
        $verbosity = $this->output->getVerbosity();
        $renderProgress = (bool) $this->input->getOption('stats') && OutputInterface::VERBOSITY_NORMAL === $verbosity;

        if ($renderProgress) {
            $packageCount = 0;

            foreach ($packages as $package) {
                if (!$helper->isSkippable($package)) {
                    ++$packageCount;
                }
            }

            $progressBar = new ProgressBar($this->output, $packageCount);
            $progressBar->setFormat(
                ' %current%/%max% [%bar%] %percent:3s%% - Installing %packageName% (%packageVersion%)'
            );
        }

        /** @var CompletePackage $package */
        foreach ($packages as $package) {
            if ($helper->isSkippable($package)) {
                continue;
            }

            if (!is_null($progressBar)) {
                $progressBar->setMessage($package->getName(), 'packageName');
                $progressBar->setMessage($package->getPrettyVersion(), 'packageVersion');

                if (!$hasStarted) {
                    $progressBar->start();
                    $hasStarted = true;
                } else {
                    $progressBar->display();
                }
            } else {
                $this->output->writeln(
                    sprintf(
                        "<info>Dumping package '%s' in version '%s'.</info>",
                        $package->getName(),
                        $package->getPrettyVersion()
                    )
                );
            }

            try {
                if ($renderProgress) {
                    $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
                }

                $intermediatePath = $this->getIntermediatePath($package);

                if ('pear-library' === $package->getType()) {
                    /* @see https://github.com/composer/composer/commit/44a4429978d1b3c6223277b875762b2930e83e8c */
                    throw new \RuntimeException('The PEAR repository has been removed from Composer 2.0');
                }

                $targetDir = sprintf('%s/%s', $basedir, $intermediatePath);

                $path = $this->archive($downloadManager, $archiveManager, $package, $targetDir);
                $archiveFormat = pathinfo($path, PATHINFO_EXTENSION);

                $archive = basename($path);
                $distUrl = sprintf('%s/%s/%s/%s', $endpoint, $this->config['archive']['directory'], $intermediatePath, $archive);
                $package->setDistType($archiveFormat);
                $package->setDistUrl($distUrl);
                $package->setDistSha1Checksum($includeArchiveChecksum ? $checksumCache->getChecksum($path) : null);
                $package->setDistReference($package->getSourceReference());

                if ($renderProgress) {
                    $this->output->setVerbosity($verbosity);
                }
            } catch (\Exception $exception) {
                if ($renderProgress) {
                    $this->output->setVerbosity($verbosity);
                }

                if (!$this->skipErrors) {
                    throw $exception;
                }
                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }

            if (!is_null($progressBar)) {
                $progressBar->advance();
            }
        }

        $checksumCache->save();

        if (!is_null($progressBar)) {
            $progressBar->finish();
        }
        if ($renderProgress) {
            $this->output->writeln('');
        }
    }

    public function setComposer(Composer $composer): self
    {
        $this->composer = $composer;

        return $this;
    }

    public function setInput(InputInterface $input): self
    {
        $this->input = $input;

        return $this;
    }

    public function setIO(IOInterface $io): self
    {
        $this->io = $io;

        return $this;
    }

    private function createChecksumCache(string $archiveDirectory): ArchiveChecksumCache
    {
        $composerConfig = $this->composer->getConfig();
        $cache = new Cache($this->io ?? new NullIO(), $composerConfig->get('cache-dir') . '/satis');
        $cache->setReadOnly((bool) $composerConfig->get('cache-read-only'));

        return new ArchiveChecksumCache($cache, $archiveDirectory);
    }

    private function archive(DownloadManager $downloadManager, ArchiveManager $archiveManager, CompletePackageInterface $package, string $targetDir): string
    {
        $format = (string) ($this->config['archive']['format'] ?? 'zip');
        $ignoreFilters = (bool) ($this->config['archive']['ignore-filters'] ?? false);
        $overrideDistType = (bool) ($this->config['archive']['override-dist-type'] ?? false);

        $filesystem = new Filesystem();
        $targetDir = $this->prepareTargetDir($filesystem, $targetDir);

        $path = $this->getArchivePath($archiveManager, $package, $targetDir);
        if (file_exists($path)) {
            return $path;
        }

        $distArchivePath = $this->getDistArchivePath($archiveManager, $package, $targetDir);
        if (null !== $distArchivePath) {
            if (!file_exists($distArchivePath)) {
                SyncHelper::await($this->composer->getLoop(), $this->downloadDistArchive($downloadManager, $package, $distArchivePath));
            }

            return $distArchivePath;
        }

        if ($overrideDistType) {
            $downloaded = $archiveManager->archive($package, $format, $targetDir, null, $ignoreFilters);
            $filesystem->rename($downloaded, $path);

            return $path;
        }

        return $archiveManager->archive($package, $format, $targetDir, null, $ignoreFilters);
    }

    /**
     * Downloads all missing dist archives concurrently, so the sequential archive() calls find them in place.
     * Failed downloads are ignored here and retried by archive(), which reports errors as usual.
     * Without curl, Composer's HttpDownloader performs the downloads one after another instead.
     *
     * @param array<PackageInterface> $packages
     */
    private function prefetchDistArchives(ArchiveBuilderHelper $helper, DownloadManager $downloadManager, ArchiveManager $archiveManager, array $packages, string $basedir): void
    {
        $promises = [];

        foreach ($packages as $package) {
            if (!$package instanceof CompletePackageInterface || $helper->isSkippable($package)) {
                continue;
            }

            $targetDir = sprintf('%s/%s', $basedir, $this->getIntermediatePath($package));
            $distArchivePath = $this->getDistArchivePath($archiveManager, $package, $targetDir);
            if (null === $distArchivePath || file_exists($distArchivePath) || file_exists($this->getArchivePath($archiveManager, $package, $targetDir))) {
                continue;
            }

            try {
                $promises[] = $this->downloadDistArchive($downloadManager, $package, $distArchivePath)->then(null, static function (): void {
                });
            } catch (\Exception) {
            }
        }

        if ([] === $promises) {
            return;
        }

        $this->output->writeln(sprintf('<info>Downloading %d dist archives</info>', count($promises)));
        $this->composer->getLoop()->wait($promises);
    }

    private function getIntermediatePath(PackageInterface $package): string
    {
        return (string) preg_replace('#[^a-z0-9-_/]#i', '-', $package->getName());
    }

    private function prepareTargetDir(Filesystem $filesystem, string $targetDir): string
    {
        $filesystem->ensureDirectoryExists($targetDir);

        return (string) realpath($targetDir);
    }

    private function getArchivePath(ArchiveManager $archiveManager, CompletePackageInterface $package, string $targetDir): string
    {
        $format = (string) ($this->config['archive']['format'] ?? 'zip');
        $overrideDistType = (bool) ($this->config['archive']['override-dist-type'] ?? false);

        if ($overrideDistType) {
            $originalDistType = $package->getDistType();
            $package->setDistType($format);
            $packageName = $archiveManager->getPackageFilename($package);
            $package->setDistType($originalDistType);
        } else {
            $packageName = $archiveManager->getPackageFilename($package);
        }

        return $targetDir . '/' . $packageName . '.' . $format;
    }

    /**
     * Returns the path of the dist archive if it is used as is, or null if the package needs to be archived.
     */
    private function getDistArchivePath(ArchiveManager $archiveManager, CompletePackageInterface $package, string $targetDir): ?string
    {
        $rearchive = (bool) ($this->config['archive']['rearchive'] ?? true);
        $distType = $package->getDistType();

        if ($rearchive || !in_array($distType, ['tar', 'zip'], true)) {
            return null;
        }

        return $targetDir . '/' . $archiveManager->getPackageFilename($package) . '.' . $distType;
    }

    /**
     * @return PromiseInterface<void>
     */
    private function downloadDistArchive(DownloadManager $downloadManager, CompletePackageInterface $package, string $path): PromiseInterface
    {
        $filesystem = new Filesystem();
        $downloadDir = sys_get_temp_dir() . '/composer_archiver' . uniqid();
        $filesystem->ensureDirectoryExists($downloadDir);
        $downloader = $downloadManager->getDownloader('file');

        return $downloader->download($package, $downloadDir)->then(
            static function ($filename) use ($path, $filesystem, $downloadDir): void {
                $filesystem->ensureDirectoryExists(dirname($path));
                if (is_string($filename)) {
                    $filesystem->rename($filename, $path);
                }
                $filesystem->removeDirectory($downloadDir);
            },
            static function (\Throwable $exception) use ($filesystem, $downloadDir): void {
                $filesystem->removeDirectory($downloadDir);

                throw $exception;
            }
        );
    }
}
