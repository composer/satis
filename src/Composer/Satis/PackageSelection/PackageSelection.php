<?php

/*
 * This file is part of composer/statis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\PackageSelection;

use Composer\Composer;
use Composer\DependencyResolver\Pool;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the Packages list.
 *
 * @author James Hautot <james@rezo.net>
 */
class PackageSelection
{
    /** @var OutputInterface The output Interface. */
    protected $output;

    /** @var bool Skips Exceptions if true. */
    protected $skipErrors;

    /** @var string packages.json file name. */
    private $filename;

    /** @var bool Selects All Packages if true. */
    private $requireAll;

    /** @var bool Add required dependencies if true. */
    private $requireDependencies;

    /** @var bool required dev-dependencies if true. */
    private $requireDevDependencies;

    /** @var string Minimum stability accepted for Packages in the list. */
    private $minimumStability;

    /** @var array The active package filter to merge. */
    private $packagesFilter = array();

    /** @var string The active repository filter to merge. */
    private $repositoryFilter;

    /** @var array The selected packages from config */
    private $selected = array();

    /** @var array A list of packages marked as abandoned */
    private $abandoned = array();

    /**
     * Base Constructor.
     *
     * @param OutputInterface $output The output Interface
     * @param string $outputDir The directory where to build
     * @param array $config The parameters from ./satis.json
     * @param bool $skipErrors Escapes Exceptions if true
     */
    public function __construct(OutputInterface $output, $outputDir, $config, $skipErrors)
    {
        $this->output = $output;
        $this->skipErrors = (bool) $skipErrors;
        $this->filename = $outputDir.'/packages.json';
        $this->fetchOptions($config);
    }

    private function fetchOptions($config)
    {
        $this->requireAll = isset($config['require-all']) && true === $config['require-all'];
        $this->requireDependencies = isset($config['require-dependencies']) && true === $config['require-dependencies'];
        $this->requireDevDependencies = isset($config['require-dev-dependencies']) && true === $config['require-dev-dependencies'];

        if (!$this->requireAll && !isset($config['require'])) {
            $this->output->writeln('No explicit requires defined, enabling require-all');
            $this->requireAll = true;
        }

        $this->minimumStability = isset($config['minimum-stability']) ? $config['minimum-stability'] : 'dev';
        $this->abandoned = isset($config['abandoned']) ? $config['abandoned'] : array();
    }

    /**
     * Sets the active repository filter to merge
     *
     * @param string $repositoryFilter The active repository filter to merge
     */
    public function setRepositoryFilter($repositoryFilter)
    {
        $this->repositoryFilter = $repositoryFilter;

        return $this;
    }

    /**
     * Tells if repository list should be reduced to single repository
     *
     * @return bool true if repository filter is set
     */
    public function hasRepositoryFilter()
    {
        return $this->repositoryFilter !== null;
    }

    /**
     * Sets the active package filter to merge
     *
     * @param array $packagesFilter The active package filter to merge
     */
    public function setPackagesFilter(array $packagesFilter = array())
    {
        $this->packagesFilter = $packagesFilter;

        return $this;
    }

    /**
     * Tells if there is at least one package filter.
     *
     * @return bool true if there is at least one package filter
     */
    public function hasFilterForPackages()
    {
        return count($this->packagesFilter) > 0;
    }

    /**
     * Sets the list of packages to build.
     *
     * @param Composer $composer The Composer instance
     * @param bool $verbose Output infos if true
     *
     * @throws \InvalidArgumentException
     *
     * @return array list of packages to build
     */
    public function select(Composer $composer, $verbose)
    {
        // run over all packages and store matching ones
        $this->output->writeln('<info>Scanning packages</info>');

        $repos = $composer->getRepositoryManager()->getRepositories();
        $pool = new Pool($this->minimumStability);
        foreach ($repos as $key => $repo) {
            if ($this->hasRepositoryFilter()) {
                $skipThis = false;
                if ($repo instanceof ConfigurableRepositoryInterface) {
                    $repoConfig = $repo->getRepoConfig();
                    if (!isset($repoConfig['url']) || $repoConfig['url'] !== $this->repositoryFilter) {
                        $skipThis = true;
                    }
                } else {
                    $skipThis = true;
                } 
                
                if ($skipThis) {
                    unset($repos[$key]);
                    continue;
                }
            }

            try {
                $pool->addRepository($repo);
            } catch (\Exception $exception) {
                if (!$this->skipErrors) {
                    throw $exception;
                }
                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }
        }

        if ($this->hasRepositoryFilter()) {
            if (count($repos) === 0) {
                throw new \InvalidArgumentException(sprintf('Specified repository url "%s" does not exist.', $this->repositoryFilter));
            } elseif (count($repos) > 1) {
                throw new \InvalidArgumentException(sprintf('Found more than one repository for url "%s".', $this->repositoryFilter));
            }
        }

        $links = $this->requireAll ? $this->getAllLinks($repos, $this->minimumStability, $verbose) : $this->getFilteredLinks($composer);

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
                if (!isset($this->selected[$package->getUniqueName()])) {
                    if ($verbose) {
                        $this->output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                    }
                    $this->selected[$package->getUniqueName()] = $package;

                    if (!$this->requireAll) {
                        $required = $this->getRequired($package);
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

        $this->setSelectedAsAbandoned();

        ksort($this->selected, SORT_STRING);

        return $this->selected;
    }

    /**
     * Loads previously dumped Packages in order to merge with updates.
     *
     * @return array $packages List of packages to dump
     */
    public function load()
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

                if (!$includeJson->exists()) {
                    $this->output->writeln(sprintf(
                        '<error>File \'%s\' does not exist, defined in "includes" in \'%s\'</error>',
                        $includeJson->getPath(),
                        $repoJson->getPath()
                    ));

                    continue;
                }

                $jsonPackages = $includeJson->read();
                $jsonPackages = isset($jsonPackages['packages']) && is_array($jsonPackages['packages'])
                    ? $jsonPackages['packages']
                    : array();

                foreach ($jsonPackages as $jsonPackage) {
                    if (is_array($jsonPackage)) {
                        foreach ($jsonPackage as $jsonVersion) {
                            if (is_array($jsonVersion)) {
                                if (isset($jsonVersion['name']) && in_array($jsonVersion['name'], $this->packagesFilter)) {
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
     * Marks selected packages as abandoned by Configuration file
     */
    private function setSelectedAsAbandoned()
    {
        foreach ($this->selected as $name => $package) {
            if (array_key_exists($package->getName(), $this->abandoned)) {
                $package->setAbandoned($this->abandoned[$package->getName()]);
            }
        }
    }

    /**
     * Gets a list of filtered Links.
     *
     * @param Composer $composer The Composer instance
     *
     * @return array a list of filtered Links
     */
    private function getFilteredLinks(Composer $composer)
    {
        $links = array_values($composer->getPackage()->getRequires());

        // only pick up packages in our filter, if a filter has been set.
        if ($this->hasFilterForPackages()) {
            $packagesFilter = $this->packagesFilter;
            $links = array_filter($links, function (Link $link) use ($packagesFilter) {
                return in_array($link->getTarget(), $packagesFilter);
            });
        }

        return array_values($links);
    }

    /**
     * Gets all Links.
     *
     * This method is called when 'require-all' is set to true.
     *
     * @param array $repos List of all Repositories configured
     * @param string $minimumStability The minimum stability each package must have to be selected
     * @param bool $verbose Output infos if true
     *
     * @return array all Links
     */
    private function getAllLinks($repos, $minimumStability, $verbose)
    {
        $links = array();

        foreach ($repos as $repo) {
            // collect links for composer repos with providers
            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                foreach ($repo->getProviderNames() as $name) {
                    $links[] = new Link('__root__', $name, new MultiConstraint(array()), 'requires', '*');
                }
            } else {
                $packages = $this->getPackages($repo);

                foreach ($packages as $package) {
                    // skip aliases
                    if ($package instanceof AliasPackage) {
                        continue;
                    }

                    if (BasePackage::$stabilities[$package->getStability()] > BasePackage::$stabilities[$minimumStability]) {
                        if ($verbose) {
                            $this->output->writeln('Skipped '.$package->getPrettyName().' ('.$package->getStability().')');
                        }
                        continue;
                    }

                    // add matching package if not yet selected
                    if (!isset($this->selected[$package->getUniqueName()])) {
                        if ($verbose) {
                            $this->output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                        }
                        $this->selected[$package->getUniqueName()] = $package;
                    }
                }
            }
        }

        return $links;
    }

    /**
     * Gets All or filtered Packages of a Repository.
     *
     * @param  RepositoryInterface $repo a Repository
     *
     * @return array $packages List of Packages
     */
    private function getPackages(RepositoryInterface $repo)
    {
        $packages = array();

        if ($this->hasFilterForPackages()) {
            // apply package filter if defined
            foreach ($this->packagesFilter as $filter) {
                $packages += $repo->findPackages($filter);
            }
        } else {
            // process other repos directly
            $packages = $repo->getPackages();
        }

        return $packages;
    }

    /**
     * Gets the required Links if needed.
     *
     * @param  PackageInterface $package A package
     *
     * @return array                     A list of Links
     */
    private function getRequired(PackageInterface $package)
    {
        $required = array();

        if ($this->requireDependencies) {
            $required = $package->getRequires();
        }
        if ($this->requireDevDependencies) {
            $required = array_merge($required, $package->getDevRequires());
        }

        return $required;
    }
}
