<?php

/*
 * This file is part of composer/statis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Builder;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for Satis Builders.
 *
 * @author James Hautot <james@rezo.net>
 */
class Builder
{
    /** @var OutputInterface $output The output Interface. */
    protected $output;

    /** @var string $outputDir The directory where to build. */
    protected $outputDir;

    /** @var array $config The parameters from ./satis.json. */
    protected $config;

    /** @var bool $skipErrors Skips Exceptions if true. */
    protected $skipErrors;

    /**
     * Base Constructor.
     *
     * @param OutputInterface $output The output Interface
     * @param string $outputDir The directory where to build
     * @param array $config The parameters from ./satis.json
     * @param bool $skipErrors Skips Exceptions if true
     */
    public function __construct(OutputInterface $output, $outputDir, $config, $skipErrors)
    {
        $this->output = $output;
        $this->outputDir = $outputDir;
        $this->config = $config;
        $this->skipErrors = (bool) $skipErrors;
    }

    /**
     * Remove from $directory all files excluding $excludeFiles
     *
     * @param string          $directory    Directory to clean
     * @param string|string[] $excludeFiles File list to exclude
     * @param bool            $forceRemove  Forcibly remove files
     *
     * @return void
     */
    protected function removeAllBut($directory, $excludeFiles, $forceRemove = false)
    {
        $isRemove = $forceRemove ||
                    (isset($this->config['archive']['autoclean']) && true === $this->config['archive']['autoclean']);

        if (!$isRemove) {
            return;
        }

        $files = scandir($directory);
        if (!is_array($excludeFiles)) {
            $excludeFiles = (array) $excludeFiles;
        }

        foreach (array_diff($files, $excludeFiles) as $file) {
            if (in_array($file, array('.', '..'))) {
                continue;
            }

            $this->output->writeln("<info>remove $directory/$file</info>");
            unlink("$directory/$file");
        }
    }
}
