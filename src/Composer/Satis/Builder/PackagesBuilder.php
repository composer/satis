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

use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the JSON files.
 *
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilder extends Builder implements BuilderInterface
{
    /** @var string prefix of included json files. */
    private $filenamePrefix;

    /** @var string packages.json file name. */
    private $filename;

    /**
     * Dedicated Packages Constructor.
     *
     * @param OutputInterface $output The output Interface
     * @param string $outputDir The directory where to build
     * @param array $config The parameters from ./satis.json
     * @param bool $skipErrors Escapes Exceptions if true
     */
    public function __construct(OutputInterface $output, $outputDir, $config, $skipErrors)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors);

        $this->filenamePrefix = $this->outputDir.'/include/all';
        $this->filename = $this->outputDir.'/packages.json';
    }

    /**
     * Builds the JSON stuff of the repository.
     *
     * @param array $packages List of packages to dump
     */
    public function dump(array $packages)
    {
        $packageFile = $this->dumpPackageIncludeJson($packages);
        $packageFileHash = hash_file('sha1', $packageFile);

        $includes = array(
            'include/all$'.$packageFileHash.'.json' => array('sha1' => $packageFileHash),
        );

        $this->dumpPackagesJson($includes);
    }

    /**
     * Writes includes JSON Files.
     *
     * @param array $packages List of packages to dump
     *
     * @return string $filenameWithHash Includes JSON file name
     */
    private function dumpPackageIncludeJson(array $packages)
    {
        $repo = array('packages' => array());
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $repo['packages'][$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }
        $repoJson = new JsonFile($this->filenamePrefix);
        $repoJson->write($repo);
        $hash = hash_file('sha1', $this->filenamePrefix);
        $filenameWithHash = $this->filenamePrefix.'$'.$hash.'.json';
        rename($this->filenamePrefix, $filenameWithHash);
        $this->output->writeln("<info>wrote packages json $filenameWithHash</info>");

        return $filenameWithHash;
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
