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
class PackagesBuilder extends Builder
{
    /** @var string packages.json file name. */
    private $filename;

    /** @var string included json filename template */
    private $includeFileName;

    private $writtenIncludeJsons = array();

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
     * @param \Composer\Package\PackageInterface[] $packages List of packages to dump
     */
    public function dump(array $packages)
    {
        $packagesByName = array();
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $packagesByName[$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }

        $repo = array('packages' => array());
        if (isset($this->config['providers']) && $this->config['providers']) {
            $repo['providers-url'] = 'p/%package%$%hash%.json';
            $repo['providers'] = array();
            foreach ($packagesByName as $packageName => $versionPackages) {
                $includes = $this->dumpPackageIncludeJson(
                    array($packageName => $versionPackages),
                    str_replace('%package%', $packageName, $repo['providers-url']),
                    'sha256'
                );
                $repo['providers'][$packageName] = current($includes);
            }
        } else {
            $repo['includes'] = $this->dumpPackageIncludeJson($packagesByName, 'include/all$%hash%.json');
        }

        $this->dumpPackagesJson($repo);
    }

    /**
     * Writes includes JSON Files.
     *
     * @param array $packages List of packages to dump
     * @param string $includesUrl The includes url (optionally containing %hash%)
     * @param string $hashAlgorithm Hash algorithm {@see hash()}
     *
     * @return array The object for includes key in packages.json
     */
    private function dumpPackageIncludeJson(array $packages, $includesUrl, $hashAlgorithm = 'sha1')
    {
        $filename = str_replace('%hash%', 'prep', $includesUrl);
        $path = $this->outputDir . '/' . ltrim($filename, '/');

        $repoJson = new JsonFile($path);
        $repoJson->write(array('packages' => $packages));

        $hash = hash_file($hashAlgorithm, $path);

        if (strpos($includesUrl, '%hash%') !== false) {
            $this->writtenIncludeJsons[] = array($hash, $includesUrl);
            $filename = str_replace('%hash%', $hash, $includesUrl);
            rename(
                $path,
                $path = $this->outputDir . '/' . ltrim($filename, '/')
            );
        }
        $this->output->writeln("<info>wrote packages to $path</info>");

        return array(
            $filename => array($hashAlgorithm => $hash)
        );
    }

    /**
     * Writes the packages.json of the repository.
     *
     * @param array $repo Repository information.
     */
    private function dumpPackagesJson($repo)
    {
        if (isset($this->config['notify-batch'])) {
            $repo['notify-batch'] = $this->config['notify-batch'];
        }

        $this->output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($this->filename);
        $repoJson->write($repo);
    }
}
