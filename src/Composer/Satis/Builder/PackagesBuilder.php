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

use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the JSON files.
 *
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilder extends Builder implements BuilderInterface
{
    /** @var string packages.json file name. */
    private $filename;

    /** @var string included json filename template */
    private $includeFileName;

    /**
     * Dedicated Packages Constructor.
     *
     * @param OutputInterface $output     The output Interface
     * @param string          $outputDir  The directory where to build
     * @param array           $config     The parameters from ./satis.json
     * @param bool            $skipErrors Escapes Exceptions if true
     */
    public function __construct(OutputInterface $output, $outputDir, $config, $skipErrors)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors);

        $this->filename = $this->outputDir.'/packages.json';
        $this->includeFileName = isset($config['include-filename']) ? $config['include-filename'] : 'include/all${sha1}.json';
    }

    /**
     * Builds the JSON stuff of the repository.
     *
     * @param array $packages List of packages to dump
     */
    public function dump(array $packages)
    {
        $includes = $this->dumpPackageIncludeJson($packages);
        $this->dumpPackagesJson($includes);
    }

    /**
     * Writes includes JSON Files.
     *
     * @param array $packages List of packages to dump
     *
     * @return array Definition of "includes" block for packages.json
     */
    private function dumpPackageIncludeJson(array $packages)
    {
        $repo = array('packages' => array());
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $repo['packages'][$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }

        // dump to temporary file
        $tempFilename = $this->outputDir.'/$include.json';
        $repoJson = new JsonFile($tempFilename);
        $repoJson->write($repo);

        // rename file accordingly
        $includeFileHash = hash_file('sha1', $tempFilename);
        $includeFileName = str_replace(
            '{sha1}', $includeFileHash, $this->includeFileName
        );
        $fs = new Filesystem();
        $fs->ensureDirectoryExists(dirname($this->outputDir.'/'.$includeFileName));
        $fs->rename($tempFilename, $this->outputDir.'/'.$includeFileName);
        $this->output->writeln("<info>Wrote packages json $includeFileName</info>");

        $includes = array(
            $includeFileName => array('sha1' => $includeFileHash),
        );

        return $includes;
    }

    /**
     * Writes the packages.json of the repository.
     *
     * @param array $includes List of included JSON files.
     */
    private function dumpPackagesJson($includes)
    {
        $repo = array(
            'packages' => array(),
            'includes' => $includes,
        );

        if (isset($this->config['notify-batch'])) {
            $repo['notify-batch'] = $this->config['notify-batch'];
        }

        $this->output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($this->filename);
        $repoJson->write($repo);
    }
}
