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

    /** @var string provider-includes url prefix */
    private $archiveEndpoint;

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

        if (isset($config['archive']['directory'])) {
            $this->filenamePrefix = $this->outputDir . '/' . $config['archive']['directory'];
            $url = isset($config['archive']['prefix-url'])
                 ? $config['archive']['prefix-url']
                 : $config['homepage'] . '/' . $config['archive']['directory'];
            $this->archiveEndpoint = parse_url($url, PHP_URL_PATH);
        } else {
            $this->filenamePrefix = $this->outputDir . '/includes/all';
        }
        $this->filename = $this->outputDir.'/packages.json';
    }

    /**
     * Builds the JSON stuff of the repository.
     *
     * @param array $packages List of packages to dump
     */
    public function dump(array $packages)
    {
        if ($this->archiveEndpoint) {
            $providers = $this->dumpPackageIncludeJson($packages);
            $includes = $this->dumpProviderIncludeJson($providers);
            $repo = array(
                'packages' => array(),
                'provider-includes' => $includes,
                'providers-url' => $this->archiveEndpoint . "/%package%$%hash%.json"
            );
            $this->dumpPackagesJson($repo);
        } else {
            list($file, $hash) = $this->dumpPackageIncludeAllJson($packages);
            $this->dumpPackagesJson([
                'packages' => array(),
                'includes' => [
                    'includes/all$'.$hash.'.json'  => ['sha1' => $hash]
                ]
            ]);
        }
    }

    /**
     * Writes package includes JSON Files.
     *
     * @param array $packages List of packages to dump
     *
     * @return string $hashes package hashes
     */
    private function dumpPackageIncludeAllJson(array $packages)
    {
        $providers = [];
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $providers[$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }
        return $this->writeJson(['packages' => $providers], $this->filenamePrefix . '$%hash%.json');
    }

    /**
     * Writes package includes JSON Files.
     *
     * @param array $packages List of packages to dump
     *
     * @return string $hashes package hashes
     */
    private function dumpPackageIncludeJson(array $packages)
    {
        $dumper = new ArrayDumper();
        $providers = array();
        $hashes = array();
        foreach ($packages as $package) {
            $info = $dumper->dump($package);
            $info['uid'] = crc32($package->getPrettyVersion());
            $providers[$package->getName()][$package->getPrettyVersion()] = $info;
        }
        foreach ($providers as $name => $versions) {
            $repo = array(
                'packages' => array(
                    $name => $versions
                )
            );
            list($file, $hash) = $this->writeJson($repo, $this->filenamePrefix . '/' . $name . '$%hash%.json');
            $this->output->writeln("<info>wrote ".$package->getName()." json $file</info>");
            $hashes[$name] = array('sha256' => $hash);
        }
        return $hashes;
    }

    /**
     * Writes providers JSON to file
     *
     * @param array $providers providers hash
     *
     * @return array privder includes file hashes
     */
    private function dumpProviderIncludeJson(array $providers)
    {
        $repo = array(
            'providers' => $providers
        );
        list($file, $hash) = $this->writeJson($repo, $this->filenamePrefix . '/all$%hash%.json');
        $this->output->writeln("<info>wrote provider json $file</info>");
        return array(
            "p/all$%hash%.json" => array(
                "sha256" => $hash
            )
        );
    }

    /**
     * Writes the packages.json of the repository.
     *
     * @param array $includes List of included JSON files.
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

    /**
     * Writes json data to file
     *
     * @param array $data data
     * @param string $file output file name template with '%hash%' to replace
     *
     * @return string filename
     */
    private function writeJson($data, $file)
    {
        $options = JsonFile::JSON_UNESCAPED_SLASHES | JsonFile::JSON_UNESCAPED_UNICODE;
        $content = JsonFile::encode($data, $options);
        $hash = hash('sha256', $content);
        $file = strtr($file, array('%hash%' => $hash));
        (new JsonFile($file))->write($data, $options);
        return array($file, $hash);
    }
}
