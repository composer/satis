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
            $providersUrl = 'p/%package%$%hash%.json';
            if (!empty($this->config['homepage'])) {
                $repo['providers-url'] = parse_url(rtrim($this->config['homepage'], '/'), PHP_URL_PATH) . '/' . $providersUrl;
            } else {
                $repo['providers-url'] = $providersUrl;
            }
            $repo['providers'] = array();
            $i = 1;
            foreach ($packagesByName as $packageName => $versionPackages) {
                foreach ($versionPackages as $version => &$versionPackage) {
                    $versionPackage['uid'] = $i++;
                }
                $includes = $this->dumpPackageIncludeJson(
                    array($packageName => $versionPackages),
                    str_replace('%package%', $packageName, $providersUrl),
                    'sha256'
                );
                $repo['providers'][$packageName] = current($includes);
            }
        } else {
            $repo['includes'] = $this->dumpPackageIncludeJson($packagesByName, 'include/all$%hash%.json');
        }

        $this->dumpPackagesJson($repo);

        $this->pruneIncludeDirectories();
    }

    /**
     * Remove all files matching the includeUrl pattern next to just created include jsons
     */
    private function pruneIncludeDirectories()
    {
        $this->output->writeln("<info>Pruning include directories</info>");
        $paths = array();
        while ($this->writtenIncludeJsons) {
            list($hash, $includesUrl) = array_shift($this->writtenIncludeJsons);
            $path = $this->outputDir . '/' . ltrim($includesUrl, '/');
            $dirname = dirname($path);
            $basename = basename($path);
            if (strpos($dirname, '%hash%') !== false) {
                throw new \RuntimeException('Refusing to prune when %hash% is in dirname');
            }
            $pattern = '#^' . str_replace('%hash%', '([0-9a-zA-Z]{' . strlen($hash) . '})', preg_quote($basename, '#')) . '$#';
            $paths[$dirname][] = array($pattern, $hash);
        }
        foreach ($paths as $dirname => $entries) {
            foreach (new \DirectoryIterator($dirname) as $file) {
                foreach ($entries as $entry) {
                    list($pattern, $hash) = $entry;
                    if(preg_match($pattern, $file->getFilename(), $matches) && $matches[1] !== $hash) {
                        unlink($file->getPathname());
                        $this->output->writeln('<comment>Deleted ' . $file->getPathname() . '</comment>');
                    }
                }
            }
        }
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
        $path = $tmpPath = $this->outputDir . '/' . ltrim($filename, '/');

        $repoJson = new JsonFile($path);
        $contents = $repoJson->encode(array('packages' => $packages)) . "\n";

        $hash = hash($hashAlgorithm, $contents);

        if (strpos($includesUrl, '%hash%') !== false) {
            $this->writtenIncludeJsons[] = array($hash, $includesUrl);
            $filename = str_replace('%hash%', $hash, $includesUrl);
            if (file_exists($path = $this->outputDir . '/' . ltrim($filename, '/'))) {
                // When the file exists, we don't need to override it as we assume,
                // the contents satisfy the hash
                $path = null;
            }
        }
        if ($path) {
            $this->writeToFile($path, $contents);
            $this->output->writeln("<info>wrote packages to $path</info>");
        }

        return array(
            $filename => array($hashAlgorithm => $hash)
        );
    }

    /**
     * Write to a file
     *
     * @param string $path
     * @param string $contents
     */
    private function writeToFile($path, $contents)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \UnexpectedValueException(
                    $dir.' exists and is not a directory.'
                );
            }
            if (!@mkdir($dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $dir.' does not exist and could not be created.'
                );
            }
        }

        $retries = 3;
        while ($retries--) {
            try {
                file_put_contents($path, $contents);
                break;
            } catch (\Exception $e) {
                if ($retries) {
                    usleep(500000);
                    continue;
                }

                throw $e;
            }
        }
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
