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
use Composer\Package\PackageInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PackagesBuilder extends Builder
{
    /** @var string packages.json file name. */
    private $filename;
    /** @var string included json filename template */
    private $includeFileName;
    /** @var array */
    private $writtenIncludeJsons = [];

    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors);

        $this->filename = $this->outputDir . '/packages.json';
        $this->includeFileName = $config['include-filename'] ?? 'include/all$%hash%.json';
    }

    /**
     * @param PackageInterface[] $packages List of packages to dump
     */
    public function dump(array $packages): void
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

    private function findReplacements(array $packages, string $replaced): array
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

    private function pruneIncludeDirectories(): void
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

    private function dumpPackageIncludeJson(array $packages, string $includesUrl, string $hashAlgorithm = 'sha1'): array
    {
        $filename = str_replace('%hash%', 'prep', $includesUrl);
        $path = $tmpPath = $this->outputDir . '/' . ltrim($filename, '/');

        $repoJson = new JsonFile($path);
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->config['pretty-print'] ?? true) {
            $options |= JSON_PRETTY_PRINT;
        }

        $contents = $repoJson->encode(['packages' => $packages], $options) . "\n";
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
            $this->output->writeln("<info>Wrote packages to $path</info>");
        }

        return [
            $filename => [$hashAlgorithm => $hash],
        ];
    }

    /**
     * @throws \UnexpectedValueException
     * @throws \Exception
     */
    private function writeToFile(string $path, string $contents): void
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
     * @param array $repo Repository information
     */
    private function dumpPackagesJson(array $repo): void
    {
        if (isset($this->config['notify-batch'])) {
            $repo['notify-batch'] = $this->config['notify-batch'];
        }

        $this->output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($this->filename);
        $repoJson->write($repo);
    }
}
