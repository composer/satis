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

use Composer\Json\JsonFile;
use Composer\Package\Dumper\ArrayDumper;
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

    private $writtenIncludeJsons = [];

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

        $this->filename = $this->outputDir . '/packages.json';
        $this->includeFileName = $config['include-filename'] ?? 'include/all$%hash%.json';
    }

    /**
     * Builds the JSON stuff of the repository.
     *
     * @param \Composer\Package\PackageInterface[] $packages List of packages to dump
     */
    public function dump(array $packages)
    {
        $packagesByName = [];
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $packagesByName[$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }

        $repo = ['packages' => []];
        if (isset($this->config['providers']) && $this->config['providers']) {
            $providersUrl = 'p/%package%$%hash%.json';
            if (!empty($this->config['homepage'])) {
                $repo['providers-url'] = parse_url(rtrim($this->config['homepage'], '/'), PHP_URL_PATH) . '/' . $providersUrl;
            } else {
                $repo['providers-url'] = $providersUrl;
            }
            $repo['providers'] = [];
            $i = 1;
            // Give each version a unique ID
            foreach ($packagesByName as $packageName => $versionPackages) {
                foreach ($versionPackages as $version => $versionPackage) {
                    $packagesByName[$packageName][$version]['uid'] = $i++;
                }
            }
            // Dump the packages along with packages they're replaced by
            foreach ($packagesByName as $packageName => $versionPackages) {
                $dumpPackages = $this->findReplacements($packagesByName, $packageName);
                $dumpPackages[$packageName] = $versionPackages;
                $includes = $this->dumpPackageIncludeJson(
                    $dumpPackages,
                    str_replace('%package%', $packageName, $providersUrl),
                    'sha256'
                );
                $repo['providers'][$packageName] = current($includes);
            }
        } else {
            $repo['includes'] = $this->dumpPackageIncludeJson($packagesByName, $this->includeFileName);
        }

        $this->dumpPackagesJson($repo);

        $this->pruneIncludeDirectories();
    }

    /**
     * Find packages replacing the $replaced packages
     *
     * @param array $packages
     * @param string $replaced
     *
     * @return array
     */
    private function findReplacements($packages, $replaced)
    {
        $replacements = [];
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as $versionConfig) {
                if (!empty($versionConfig['replace']) && array_key_exists($replaced, $versionConfig['replace'])) {
                    $replacements[$packageName] = $packageConfig;
                    break;
                }
            }
        }

        return $replacements;
    }

    /**
     * Remove all files matching the includeUrl pattern next to just created include jsons
     */
    private function pruneIncludeDirectories()
    {
        $this->output->writeln('<info>Pruning include directories</info>');
        $paths = [];
        while ($this->writtenIncludeJsons) {
            list($hash, $includesUrl) = array_shift($this->writtenIncludeJsons);
            $path = $this->outputDir . '/' . ltrim($includesUrl, '/');
            $dirname = dirname($path);
            $basename = basename($path);
            if (false !== strpos($dirname, '%hash%')) {
                throw new \RuntimeException('Refusing to prune when %hash% is in dirname');
            }
            $pattern = '#^' . str_replace('%hash%', '([0-9a-zA-Z]{' . strlen($hash) . '})', preg_quote($basename, '#')) . '$#';
            $paths[$dirname][] = [$pattern, $hash];
        }
        $pruneFiles = [];
        foreach ($paths as $dirname => $entries) {
            foreach (new \DirectoryIterator($dirname) as $file) {
                foreach ($entries as $entry) {
                    list($pattern, $hash) = $entry;
                    if (preg_match($pattern, $file->getFilename(), $matches) && $matches[1] !== $hash) {
                        $group = sprintf(
                            '%s/%s',
                            basename($dirname),
                            preg_replace('/\$.*$/', '', $file->getFilename())
                        );
                        if (!array_key_exists($group, $pruneFiles)) {
                            $pruneFiles[$group] = [];
                        }
                        // Mark file for pruning.
                        $pruneFiles[$group][] = new \SplFileInfo($file->getPathname());
                    }
                }
            }
        }
        // Get the pruning limit.
        $offset = $this->config['providers-history-size'] ?? 0;
        // Unlink to-be-pruned files.
        foreach ($pruneFiles as $group => $files) {
            // Sort to-be-pruned files base on ctime, latest first.
            usort(
                $files,
                function (\SplFileInfo $fileA, \SplFileInfo $fileB) {
                    return $fileB->getCTime() <=> $fileA->getCTime();
                }
            );
            // If configured, skip files from the to-be-pruned files by offset.
            $files = array_splice($files, $offset);
            foreach ($files as $file) {
                unlink($file->getPathname());
                $this->output->writeln(
                    sprintf(
                        '<comment>Deleted %s</comment>',
                        $file->getPathname()
                    )
                );
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
        $contents = $repoJson->encode(['packages' => $packages]) . "\n";

        $hash = hash($hashAlgorithm, $contents);

        if (false !== strpos($includesUrl, '%hash%')) {
            $this->writtenIncludeJsons[] = [$hash, $includesUrl];
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

        return [
            $filename => [$hashAlgorithm => $hash],
        ];
    }

    /**
     * Write to a file
     *
     * @param string $path
     * @param string $contents
     *
     * @throws \UnexpectedValueException
     * @throws \Exception
     */
    private function writeToFile($path, $contents)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \UnexpectedValueException(
                    $dir . ' exists and is not a directory.'
                );
            }
            if (!@mkdir($dir, 0777, true)) {
                throw new \UnexpectedValueException(
                    $dir . ' does not exist and could not be created.'
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
     * @param array $repo Repository information
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
