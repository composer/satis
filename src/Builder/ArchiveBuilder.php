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
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveBuilder extends Builder
{
    /** @var Composer A Composer instance. */
    private $composer;
    /** @var InputInterface */
    private $input;

    public function dump(array $packages): void
    {
        $helper = new ArchiveBuilderHelper($this->output, $this->config['archive']);
        $basedir = $helper->getDirectory($this->outputDir);
        $this->output->writeln(sprintf("<info>Creating local downloads in '%s'</info>", $basedir));
        $endpoint = $this->config['archive']['prefix-url'] ?? $this->config['homepage'];
        $includeArchiveChecksum = (bool) ($this->config['archive']['checksum'] ?? true);
        $composerConfig = $this->composer->getConfig();
        $factory = new Factory();
        /* @var DownloadManager $downloadManager */
        $downloadManager = $this->composer->getDownloadManager();
        /* @var ArchiveManager $archiveManager */
        $archiveManager = $factory->createArchiveManager($composerConfig, $downloadManager);
        $archiveManager->setOverwriteFiles(false);

        shuffle($packages);

        $progressBar = null;
        $hasStarted = false;
        $verbosity = $this->output->getVerbosity();
        $renderProgress = $this->input->getOption('stats') && OutputInterface::VERBOSITY_NORMAL == $verbosity;

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

        /* @var CompletePackage $package */
        foreach ($packages as $package) {
            if ($helper->isSkippable($package)) {
                continue;
            }

            if ($renderProgress) {
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

                $packageName = $archiveManager->getPackageFilename($package);

                if ('pear-library' === $package->getType()) {
                    // PEAR packages are archives already
                    $filesystem = new Filesystem();
                    $path = sprintf(
                        '%s/%s/%s.%s',
                        realpath($basedir),
                        $intermediatePath,
                        $packageName,
                        pathinfo($package->getDistUrl(), PATHINFO_EXTENSION)
                    );

                    if (!file_exists($path)) {
                        $downloadDir = sys_get_temp_dir() . '/composer_archiver/' . $packageName;
                        $filesystem->ensureDirectoryExists($downloadDir);
                        $downloadManager->download($package, $downloadDir, false);
                        $filesystem->ensureDirectoryExists(dirname($path));
                        $filesystem->rename($downloadDir . '/' . pathinfo($package->getDistUrl(), PATHINFO_BASENAME), $path);
                        $filesystem->removeDirectory($downloadDir);
                    }

                    // Set archive format to `file` to tell composer to download it as is
                    $archiveFormat = 'file';
                } else {
                    $targetDir = sprintf('%s/%s', $basedir, $intermediatePath);

                    $path = $this->archive($downloadManager, $archiveManager, $package, $targetDir);
                    $archiveFormat = pathinfo($path, PATHINFO_EXTENSION);
                }

                $archive = basename($path);
                $distUrl = sprintf('%s/%s/%s/%s', $endpoint, $this->config['archive']['directory'], $intermediatePath, $archive);
                $package->setDistType($archiveFormat);
                $package->setDistUrl($distUrl);
                $package->setDistSha1Checksum($includeArchiveChecksum ? hash_file('sha1', $path) : null);
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

            if ($renderProgress) {
                $progressBar->advance();
            }
        }

        if ($renderProgress) {
            $progressBar->finish();

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

    private function archive(DownloadManager $downloadManager, ArchiveManager $archiveManager, PackageInterface $package, string $targetDir): string
    {
        $format = (string) ($this->config['archive']['format'] ?? 'zip');
        $ignoreFilters = (bool) ($this->config['archive']['ignore-filters'] ?? false);
        $overrideDistType = (bool) ($this->config['archive']['override-dist-type'] ?? false);
        $rearchive = (bool) ($this->config['archive']['rearchive'] ?? true);

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($targetDir);
        $targetDir = realpath($targetDir);

        if ($overrideDistType) {
            $originalDistType = $package->getDistType();
            $package->setDistType($format);
            $packageName = $overriddenPackageName = $archiveManager->getPackageFilename($package);
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
            $downloader->download($package, $downloadDir);

            $filesystem->ensureDirectoryExists(dirname($path));
            $filesystem->rename($downloadDir . '/' . pathinfo($package->getDistUrl(), PATHINFO_BASENAME), $path);
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
