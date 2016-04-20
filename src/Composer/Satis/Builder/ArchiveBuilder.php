<?php

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
use Composer\Factory;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the archives of the repository.
 *
 * @author James Hautot <james@rezo.net>
 */
class ArchiveBuilder extends Builder
{
    /** @var Composer A Composer instance. */
    private $composer;

    /** @var InputInterface */
    private $input;

    /**
     * {@inheritdoc}
     */
    public function dump(array $packages)
    {
        $helper = new ArchiveBuilderHelper($this->output, $this->config['archive']);

        $directory = $helper->getDirectory($this->outputDir);

        $this->output->writeln(sprintf("<info>Creating local downloads in '%s'</info>", $directory));

        $format = isset($this->config['archive']['format']) ? $this->config['archive']['format'] : 'zip';
        $endpoint = isset($this->config['archive']['prefix-url']) ? $this->config['archive']['prefix-url'] : $this->config['homepage'];

        $includeArchiveChecksum = isset($this->config['archive']['checksum']) ? (bool) $this->config['archive']['checksum'] : true;

        $composerConfig = $this->composer->getConfig();
        $factory = new Factory();

        /* @var \Composer\Downloader\DownloadManager $downloadManager */
        $downloadManager = $this->composer->getDownloadManager();

        /* @var \Composer\Package\Archiver\ArchiveManager $archiveManager */
        $archiveManager = $factory->createArchiveManager($composerConfig, $downloadManager);

        $archiveManager->setOverwriteFiles(false);

        shuffle($packages);

        $progressBar = null;
        $hasStarted = false;
        $verbosity = $this->output->getVerbosity();
        $isStats = $this->input->getOption('stats') && OutputInterface::VERBOSITY_NORMAL == $verbosity;

        if ($isStats) {
            $packageCount = 0;

            foreach ($packages as $package) {
                if (!$helper->isSkippable($package)) {
                    ++$packageCount;
                }
            }

            $progressBar = new ProgressBar($this->output, $packageCount);
            $progressBar->setFormat(
                ' %current%/%max% [%bar%] %percent:3s%% - '
                . "Installing %packageName% (%packageVersion%)"
            );
        }

        /* @var \Composer\Package\CompletePackage $package */
        foreach ($packages as $package) {
            if ($helper->isSkippable($package)) {
                continue;
            }

            if ($isStats) {
                $progressBar->setMessage($package->getName(), 'packageName');
                $progressBar->setMessage($package->getPrettyVersion(), 'packageVersion');

                if (!$hasStarted) {
                    $progressBar->start();
                    $hasStarted = true;
                } else {
                    $progressBar->display();
                }
            } else {
                $this->output->writeln(sprintf("<info>Dumping '%s'.</info>", $package->getName()));
            }

            try {
                if ($isStats) {
                    $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
                }

                if ('pear-library' === $package->getType()) {
                    // PEAR packages are archives already
                    $filesystem = new Filesystem();
                    $packageName = $archiveManager->getPackageFilename($package);
                    $path =
                        realpath($directory).'/'.$packageName.'.'.
                        pathinfo($package->getDistUrl(), PATHINFO_EXTENSION);
                    if (!file_exists($path)) {
                        $downloadDir = sys_get_temp_dir().'/composer_archiver/'.$packageName;
                        $filesystem->ensureDirectoryExists($downloadDir);

                        $downloadManager->download($package, $downloadDir, false);

                        $filesystem->ensureDirectoryExists($directory);
                        $filesystem->rename($downloadDir.'/'.pathinfo($package->getDistUrl(), PATHINFO_BASENAME), $path);
                        $filesystem->removeDirectory($downloadDir);
                    }
                    // Set archive format to `file` to tell composer to download it as is
                    $archiveFormat = 'file';
                } else {
                    $path = $archiveManager->archive($package, $format, $directory);
                    $archiveFormat = $format;
                }
                $archive = basename($path);
                $distUrl = sprintf('%s/%s/%s', $endpoint, $this->config['archive']['directory'], $archive);
                $package->setDistType($archiveFormat);
                $package->setDistUrl($distUrl);

                if ($includeArchiveChecksum) {
                    $package->setDistSha1Checksum(hash_file('sha1', $path));
                }

                $package->setDistReference($package->getSourceReference());

                if ($isStats) {
                    $this->output->setVerbosity($verbosity);
                }
            } catch (\Exception $exception) {
                if ($isStats) {
                    $this->output->setVerbosity($verbosity);
                }

                if (!$this->skipErrors) {
                    throw $exception;
                }
                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }

            if ($isStats) {
                $progressBar->advance();
            }
        }

        if ($isStats) {
            $progressBar->clear();
            $this->output->writeln('');
        }
    }

    /**
     * Sets the Composer instance.
     *
     * @param Composer $composer A Composer instance
     *
     * @return $this
     */
    public function setComposer(Composer $composer)
    {
        $this->composer = $composer;

        return $this;
    }

    /**
     * @param InputInterface $input
     *
     * @return $this;
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;

        return $this;
    }
}
