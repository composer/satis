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
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Semver\VersionParser;
use Symfony\Component\Console\Output\OutputInterface;

class PackagesBuilder extends Builder
{
    public const MINIFY_ALGORITHM_V2 = 'composer/2.0';

    /** packages.json file name. */
    private string $filename;
    /** included json filename template */
    private string $includeFileName;
    /** @var list<mixed> */
    private array $writtenIncludeJsons = [];
    private bool $minify;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors, bool $minify = false)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors);

        $this->filename = $this->outputDir . '/packages.json';
        $this->includeFileName = $config['include-filename'] ?? 'include/all$%hash%.json';
        $this->minify = $minify;
        $this->config['includes'] = $config['includes'] ?? true;
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

        // Composer 1.0 format
        $repo = ['packages' => []];
        if (isset($this->config['providers']) && true === $this->config['providers']) {
            $providersUrl = 'p/%package%$%hash%.json';
            if (isset($this->config['homepage']) && is_string($this->config['homepage'])) {
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
        }

        if (isset($this->config['includes']) && true === $this->config['includes']) {
            $repo['includes'] = $this->dumpPackageIncludeJson($packagesByName, $this->includeFileName);
        }

        // Composer 2.0 format
        $metadataUrl = 'p2/%package%.json';
        if (array_key_exists('homepage', $this->config) && false !== filter_var($this->config['homepage'], FILTER_VALIDATE_URL)) {
            $repo['metadata-url'] = parse_url(rtrim($this->config['homepage'], '/'), PHP_URL_PATH) . '/' . $metadataUrl;
        } else {
            $repo['metadata-url'] = $metadataUrl;
        }

        if (array_key_exists('available-package-patterns', $this->config) && count($this->config['available-package-patterns']) > 0) {
            $repo['available-package-patterns'] = $this->config['available-package-patterns'];
        } else {
            $repo['available-packages'] = array_keys($packagesByName);
        }

        $additionalMetaData = [];

        if ($this->minify) {
            $additionalMetaData['minified'] = self::MINIFY_ALGORITHM_V2;
        }

        foreach ($packagesByName as $packageName => $versionPackages) {
            $stableVersions = [];
            $devVersions = [];
            foreach ($versionPackages as $version => $versionConfig) {
                if ('dev' === VersionParser::parseStability($versionConfig['version'])) {
                    $devVersions[] = $versionConfig;
                } else {
                    $stableVersions[] = $versionConfig;
                }
            }

            // Stable versions
            $this->dumpPackageIncludeJson(
                [$packageName => $this->minify ? MetadataMinifier::minify($stableVersions) : $stableVersions],
                str_replace('%package%', $packageName, $metadataUrl),
                'sha1',
                $additionalMetaData
            );

            // Dev versions
            $this->dumpPackageIncludeJson(
                [$packageName => $this->minify ? MetadataMinifier::minify($devVersions) : $devVersions],
                str_replace('%package%', $packageName.'~dev', $metadataUrl),
                'sha1',
                $additionalMetaData
            );
        }

        $this->dumpPackagesJson($repo);

        $this->pruneIncludeDirectories();
    }

    /**
     * @param array<string, mixed> $packages
     *
     * @return array<string, mixed>
     */
    private function findReplacements(array $packages, string $replaced): array
    {
        $replacements = [];
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as $versionConfig) {
                if (array_key_exists('replace', $versionConfig) && array_key_exists($replaced, $versionConfig['replace'])) {
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
                    if (1 === preg_match($pattern, $file->getFilename(), $matches) && $matches[1] !== $hash) {
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
     * @param array<string, mixed> $packages
     * @param array<string, string> $additionalMetaData
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \Exception
     *
     * @return array<string, mixed>
     */
    private function dumpPackageIncludeJson(array $packages, string $includesUrl, string $hashAlgorithm = 'sha1', array $additionalMetaData = []): array
    {
        $filename = str_replace('%hash%', 'prep', $includesUrl);
        $path = $tmpPath = $this->outputDir . '/' . ltrim($filename, '/');

        $repoJson = new JsonFile($path);
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $shouldPrettyPrint = isset($this->config['pretty-print']) ? (bool) $this->config['pretty-print'] : true;
        if ($shouldPrettyPrint) {
            $options |= JSON_PRETTY_PRINT;
        }

        $contents = $repoJson::encode(array_merge(['packages' => $packages], $additionalMetaData), $options) . "\n";
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

        if (is_string($path)) {
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
        if (file_exists($path) && sha1_file($path) === sha1($contents)) {
            // The file already contains the expected contents.
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \UnexpectedValueException($dir . ' exists and is not a directory.');
            }
            if (!@mkdir($dir, 0777, true)) {
                throw new \UnexpectedValueException($dir . ' does not exist and could not be created.');
            }
        }

        $retries = 3;
        while ($retries--) {
            try {
                file_put_contents($path, $contents);
                break;
            } catch (\Exception $e) {
                if ($retries > 0) {
                    usleep(500000);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $repo Repository information
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
