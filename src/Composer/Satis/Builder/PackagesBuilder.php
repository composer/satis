<?php

/**
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Composer\Satis\Builder;

use Composer\Composer;
use Composer\Repository\ComposerRepository;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Repository\PlatformRepository;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Dumper\ArrayDumper;
use Composer\DependencyResolver\Pool;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the JSON stuff.
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
     * @param OutputInterface $output    The output Interface
     * @param string          $outputDir The directory where to build
     * @param array           $config    The parameters from ./satis.json
     */
    public function __construct(OutputInterface $output, $outputDir, $config)
    {
        parent::__construct($output, $outputDir, $config);

        $this->filenamePrefix = $this->outputDir.'/include/all';
        $this->filename = $this->outputDir.'/packages.json';
    }

    /**
     * Sets the list of packages to build.
     *
     * @param Composer $composer       The Composer instance
     * @param bool     $verbose        Output infos is true
     * @param array    $packagesFilter The active package filter to merge
     *
     * @return array list of packages to build
     */
    public function select(Composer $composer, $verbose, array $packagesFilter = array())
    {
        $selected = array();

        // fetch options
        $requireAll = isset($this->config['require-all']) && true === $this->config['require-all'];
        $requireDependencies = isset($this->config['require-dependencies']) && true === $this->config['require-dependencies'];
        $requireDevDependencies = isset($this->config['require-dev-dependencies']) && true === $this->config['require-dev-dependencies'];

        if (!$requireAll && !isset($this->config['require'])) {
            $this->output->writeln('No explicit requires defined, enabling require-all');
            $requireAll = true;
        }

        $minimumStability = isset($this->config['minimum-stability']) ? $this->config['minimum-stability'] : 'dev';

        // run over all packages and store matching ones
        $this->output->writeln('<info>Scanning packages</info>');

        $repos = $composer->getRepositoryManager()->getRepositories();
        $pool = new Pool($minimumStability);
        foreach ($repos as $repo) {
            try {
                $pool->addRepository($repo);
            } catch (\Exception $exception) {
                if (!$this->skipErrors) {
                    throw $exception;
                }
                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }
        }

        if ($requireAll) {
            $links = array();
            $filterForPackages = count($packagesFilter) > 0;

            foreach ($repos as $repo) {
                // collect links for composer repos with providers
                if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                    foreach ($repo->getProviderNames() as $name) {
                        $links[] = new Link('__root__', $name, new MultiConstraint(array()), 'requires', '*');
                    }
                } else {
                    $packages = array();
                    if ($filterForPackages) {
                        // apply package filter if defined
                        foreach ($packagesFilter as $filter) {
                            $packages += $repo->findPackages($filter);
                        }
                    } else {
                        // process other repos directly
                        $packages = $repo->getPackages();
                    }

                    foreach ($packages as $package) {
                        // skip aliases
                        if ($package instanceof AliasPackage) {
                            continue;
                        }

                        if ($package->getStability() > BasePackage::$stabilities[$minimumStability]) {
                            continue;
                        }

                        // add matching package if not yet selected
                        if (!isset($selected[$package->getUniqueName()])) {
                            if ($verbose) {
                                $this->output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                            }
                            $selected[$package->getUniqueName()] = $package;
                        }
                    }
                }
            }
        } else {
            $links = array_values($composer->getPackage()->getRequires());

            // only pick up packages in our filter, if a filter has been set.
            if (count($packagesFilter) > 0) {
                $links = array_filter($links, function (Link $link) use ($packagesFilter) {
                    return in_array($link->getTarget(), $packagesFilter);
                });
            }

            $links = array_values($links);
        }

        // process links if any
        $depsLinks = array();

        $i = 0;
        while (isset($links[$i])) {
            $link = $links[$i];
            ++$i;
            $name = $link->getTarget();
            $matches = $pool->whatProvides($name, $link->getConstraint(), true);

            foreach ($matches as $index => $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                // add matching package if not yet selected
                if (!isset($selected[$package->getUniqueName()])) {
                    if ($verbose) {
                        $this->output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                    }
                    $selected[$package->getUniqueName()] = $package;

                    if (!$requireAll) {
                        $required = array();
                        if ($requireDependencies) {
                            $required = $package->getRequires();
                        }
                        if ($requireDevDependencies) {
                            $required = array_merge($required, $package->getDevRequires());
                        }
                        // append non-platform dependencies
                        foreach ($required as $dependencyLink) {
                            $target = $dependencyLink->getTarget();
                            if (!preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $target)) {
                                $linkId = $target.' '.$dependencyLink->getConstraint();
                                // prevent loading multiple times the same link
                                if (!isset($depsLinks[$linkId])) {
                                    $links[] = $dependencyLink;
                                    $depsLinks[$linkId] = true;
                                }
                            }
                        }
                    }
                }
            }

            if (!$matches) {
                $this->output->writeln('<error>The '.$name.' '.$link->getPrettyConstraint().' requirement did not match any package</error>');
            }
        }

        ksort($selected, SORT_STRING);

        return $selected;
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
     * Loads previously dumped Packages in order to merge with updates.
     *
     * @param array $packagesFilter The active package filter to merge
     *
     * @return array $packages List of packages to dump
     */
    public function load(array $packagesFilter = array())
    {
        $packages = array();
        $repoJson = new JsonFile($this->filename);
        $dirName = dirname($this->filename);

        if ($repoJson->exists()) {
            $loader = new ArrayLoader();
            $jsonIncludes = $repoJson->read();
            $jsonIncludes = isset($jsonIncludes['includes']) && is_array($jsonIncludes['includes'])
                ? $jsonIncludes['includes']
                : array();

            foreach ($jsonIncludes as $includeFile => $includeConfig) {
                $includeJson = new JsonFile($dirName.'/'.$includeFile);
                $jsonPackages = $includeJson->read();
                $jsonPackages = isset($jsonPackages['packages']) && is_array($jsonPackages['packages'])
                    ? $jsonPackages['packages']
                    : array();

                foreach ($jsonPackages as $jsonPackage) {
                    if (is_array($jsonPackage)) {
                        foreach ($jsonPackage as $jsonVersion) {
                            if (is_array($jsonVersion)) {
                                if (isset($jsonVersion['name']) && in_array($jsonVersion['name'], $packagesFilter)) {
                                    continue;
                                }
                                $package = $loader->load($jsonVersion);
                                $packages[$package->getUniqueName()] = $package;
                            }
                        }
                    }
                }
            }
        }

        return $packages;
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

        $this->output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($this->filename);
        $repoJson->write($repo);
    }
}
