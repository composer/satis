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
use Composer\Downloader\DownloadManager;
use Composer\Factory;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\SyncHelper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveBuilder extends Builder
{
    private Composer $composer;

    private InputInterface $input;

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

        shuffle($packages);

        /** @var ProgressBar|null $progressBar Should only remain `null` if $renderProgress is `false` */
        $progressBar = null;
        $hasStarted = false;
        $verbosity = $this->output->getVerbosity();
        $renderProgress = (bool) $this->input->getOption('stats') && OutputInterface::VERBOSITY_NORMAL == $verbosity;

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

                $intermediatePath = preg_replace('#[^a-z0-9-_/]#i', '-', $package->getName());

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
                $hashedPath = hash_file('sha1', $path);
                $package->setDistSha1Checksum($includeArchiveChecksum ? (is_string($hashedPath) ? $hashedPath : null) : null);
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

    private function archive(DownloadManager $downloadManager, ArchiveManager $archiveManager, CompletePackageInterface $package, string $targetDir): string
    {
        $format = (string) ($this->config['archive']['format'] ?? 'zip');
        $ignoreFilters = (bool) ($this->config['archive']['ignore-filters'] ?? false);
        $overrideDistType = (bool) ($this->config['archive']['override-dist-type'] ?? false);
        $rearchive = (bool) ($this->config['archive']['rearchive'] ?? true);

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($targetDir);
        $targetDir = (string) realpath($targetDir);

        if ($overrideDistType) {
            $originalDistType = $package->getDistType();
            $package->setDistType($format);
            $packageName = $archiveManager->getPackageFilename($package);
            $package->setDistType($originalDistType);
        } else {
            $packageName = $archiveManager->getPackageFilename($package);
        }

        $path = $targetDir . '/' . $packageName . '.' . $format;
        if (file_exists($path)) {
            return $path;
        }

        if (!$rearchive && in_array($distType = $package->getDistType(), ['tar', 'zip'], true)) {
            if ($overrideDistType) {
                $packageName = $archiveManager->getPackageFilename($package);
            }

            $path = $targetDir . '/' . $packageName . '.' . $distType;
            if (file_exists($path)) {
                return $path;
            }

            $downloadDir = sys_get_temp_dir() . '/composer_archiver' . uniqid();
            $filesystem->ensureDirectoryExists($downloadDir);
            $downloader = $downloadManager->getDownloader('file');
            $downloadPromise = $downloader->download($package, $downloadDir);
            $downloadPromise->then(function ($filename) use ($path, $filesystem) {
                $filesystem->ensureDirectoryExists(dirname($path));
                if (is_string($filename)) {
                    $filesystem->rename($filename, $path);
                }
            });
            SyncHelper::await($this->composer->getLoop(), $downloadPromise);
            $filesystem->removeDirectory($downloadDir);

            return $path;
        }

        if ($overrideDistType) {
            $path = $targetDir . '/' . $packageName . '.' . $format;
            $downloaded = $archiveManager->archive($package, $format, $targetDir, null, $ignoreFilters);
            $filesystem->rename($downloaded, $path);

            return $path;
        }

        return $archiveManager->archive($package, $format, $targetDir, null, $ignoreFilters);
    }
}
