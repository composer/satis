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

namespace Composer\Satis\PackageSelection;

use Composer\Composer;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\EmptyConstraint;
use Composer\Util\Filesystem;
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

    /** @var array Array of additional repositories for dependencies */
    private $depRepositories;

    /** @var bool Selects All Packages if true. */
    private $requireAll;

    /** @var bool Add required dependencies if true. */
    private $requireDependencies;

    /** @var bool required dev-dependencies if true. */
    private $requireDevDependencies;

    /** @var bool Filter dependencies if true. */
    private $requireDependencyFilter;

    /** @var string Minimum stability accepted for Packages in the list. */
    private $minimumStability;

    /** @var array The active package filter to merge. */
    private $packagesFilter = [];

    /** @var string The active repository filter to merge. */
    private $repositoryFilter;

    /** @var bool Apply the filter also for resolving dependencies. */
    private $repositoryFilterDep;

    /** @var array The selected packages from config */
    private $selected = [];

    /** @var array A list of packages marked as abandoned */
    private $abandoned = [];

    /** @var array|bool Patterns from strip-hosts. */
    private $stripHosts = false;

    /** @var string The prefix of the distURLs when using archive. */
    private $archiveEndpoint;

    /** @var string The homepage - needed to get the relative paths of the providers */
    private $homepage;

    /**
     * Base Constructor.
     *
     * @param OutputInterface $output     The output Interface
     * @param string          $outputDir  The directory where to build
     * @param array           $config     The parameters from ./satis.json
     * @param bool            $skipErrors Escapes Exceptions if true
     */
    public function __construct(OutputInterface $output, $outputDir, $config, $skipErrors)
    {
        $this->output = $output;
        $this->skipErrors = (bool) $skipErrors;
        $this->filename = $outputDir . '/packages.json';
        $this->fetchOptions($config);
    }

    private function fetchOptions($config)
    {
        $this->depRepositories = $config['repositories-dep'] ?? [];

        $this->requireAll = isset($config['require-all']) && true === $config['require-all'];
        $this->requireDependencies = isset($config['require-dependencies']) && true === $config['require-dependencies'];
        $this->requireDevDependencies = isset($config['require-dev-dependencies']) && true === $config['require-dev-dependencies'];
        $this->requireDependencyFilter = (bool) ($config['require-dependency-filter'] ?? true);

        if (!$this->requireAll && !isset($config['require'])) {
            $this->output->writeln('No explicit requires defined, enabling require-all');
            $this->requireAll = true;
        }

        $this->minimumStability = $config['minimum-stability'] ?? 'dev';
        $this->abandoned = $config['abandoned'] ?? [];

        $this->stripHosts = $this->createStripHostsPatterns($config['strip-hosts'] ?? false);
        $this->archiveEndpoint = isset($config['archive']['directory']) ? ($config['archive']['prefix-url'] ?? $config['homepage']) . '/' : null;

        $this->homepage = $config['homepage'] ?? null;
    }

    /**
     * Sets the active repository filter to merge
     *
     * @param string $repositoryFilter The active repository filter to merge
     * @param bool $forDependencies Apply the filter also for resolving dependencies
     */
    public function setRepositoryFilter($repositoryFilter, $forDependencies = false)
    {
        $this->repositoryFilter = $repositoryFilter;
        $this->repositoryFilterDep = (bool) $forDependencies;
    }

    /**
     * Tells if repository list should be reduced to single repository
     *
     * @return bool true if repository filter is set
     */
    public function hasRepositoryFilter()
    {
        return null !== $this->repositoryFilter;
    }

    /**
     * Sets the active package filter to merge
     *
     * @param array $packagesFilter The active package filter to merge
     */
    public function setPackagesFilter(array $packagesFilter = [])
    {
        $this->packagesFilter = $packagesFilter;
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
     * @param bool     $verbose  Output info if true
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     *
     * @return PackageInterface[]
     */
    public function select(Composer $composer, $verbose)
    {
        // run over all packages and store matching ones
        $this->output->writeln('<info>Scanning packages</info>');

        $repos = $initialRepos = $composer->getRepositoryManager()->getRepositories();
        $pool = new Pool($this->minimumStability);

        if ($this->hasRepositoryFilter()) {
            $repos = $this->filterRepositories($repos);

            if (0 === count($repos)) {
                throw new \InvalidArgumentException(sprintf('Specified repository url "%s" does not exist.', $this->repositoryFilter));
            } elseif (count($repos) > 1) {
                throw new \InvalidArgumentException(sprintf('Found more than one repository for url "%s".', $this->repositoryFilter));
            }
        }

        $this->addRepositories($pool, $repos);

        // determine the required packages
        $rootLinks = $this->requireAll ? $this->getAllLinks($repos, $this->minimumStability, $verbose) : $this->getFilteredLinks($composer);

        // select the required packages and determine dependencies
        $depsLinks = $this->selectLinks($pool, $rootLinks, true, $verbose);

        if ($this->requireDependencies || $this->requireDevDependencies) {
            // dependencies of required packages might have changed and be part of filtered repos
            if ($this->hasRepositoryFilter() && true !== $this->repositoryFilterDep) {
                $this->addRepositories($pool, \array_filter($initialRepos, function ($r) use ($repos) {
                    return \in_array($r, $repos) === false;
                }));
            }

            // additional repositories for dependencies
            if (!$this->hasRepositoryFilter() || true !== $this->repositoryFilterDep) {
                $this->addRepositories($pool, $this->getDepRepos($composer));
            }

            // select dependencies
            $this->selectLinks($pool, $depsLinks, false, $verbose);
        }

        $this->setSelectedAsAbandoned();

        ksort($this->selected, SORT_STRING);

        return $this->selected;
    }

    /**
     * Clean up the selection for publishing
     */
    public function clean()
    {
        $this->applyStripHosts();

        return $this->selected;
    }

    /**
     * Loads previously dumped Packages in order to merge with updates.
     *
     * @return PackageInterface[]
     */
    public function load()
    {
        $packages = [];
        $repoJson = new JsonFile($this->filename);
        $dirName = dirname($this->filename);

        if ($repoJson->exists()) {
            $loader = new ArrayLoader();
            $packagesJson = $repoJson->read();
            $jsonIncludes = isset($packagesJson['includes']) && is_array($packagesJson['includes'])
                ? $packagesJson['includes']
                : [];

            if (isset($packagesJson['providers']) && is_array($packagesJson['providers']) && isset($packagesJson['providers-url'])) {
                $baseUrl = $this->homepage ? parse_url(rtrim($this->homepage, '/'), PHP_URL_PATH) . '/' : null;
                $baseUrlLength = strlen($baseUrl);
                foreach ($packagesJson['providers'] as $packageName => $provider) {
                    $file = str_replace(['%package%', '%hash%'], [$packageName, $provider['sha256']], $packagesJson['providers-url']);
                    if ($baseUrl && substr($file, 0, $baseUrlLength) === $baseUrl) {
                        $file = substr($file, $baseUrlLength);
                    }
                    $jsonIncludes[$file] = $provider;
                }
            }

            foreach ($jsonIncludes as $includeFile => $includeConfig) {
                $includeJson = new JsonFile($dirName . '/' . $includeFile);

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
                    : [];

                foreach ($jsonPackages as $jsonPackage) {
                    if (is_array($jsonPackage)) {
                        foreach ($jsonPackage as $jsonVersion) {
                            if (is_array($jsonVersion)) {
                                if (isset($jsonVersion['name']) && in_array($jsonVersion['name'], $this->packagesFilter)) {
                                    continue;
                                }
                                $package = $loader->load($jsonVersion);

                                // skip aliases
                                if ($package instanceof AliasPackage) {
                                    $package = $package->getAliasOf();
                                }

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
     * Create patterns from strip-hosts
     */
    private function createStripHostsPatterns($stripHostsConfig)
    {
        if (!is_array($stripHostsConfig)) {
            return $stripHostsConfig;
        }
        $patterns = [];
        foreach ($stripHostsConfig as $entry) {
            if (!strlen($entry)) {
                continue;
            }
            if ('/private' === $entry || '/local' === $entry) {
                $patterns[] = [$entry];
                continue;
            } elseif (false !== strpos($entry, ':')) {
                $type = 'ipv6';
                if (!defined('AF_INET6')) {
                    $this->output->writeln('<error>Unable to use IPv6.</error>');
                    continue;
                }
            } elseif (0 === preg_match('#[^/.\\d]#', $entry)) {
                $type = 'ipv4';
            } else {
                $type = 'name';
                $host = '#^(?:.+\.)?' . preg_quote($entry, '#') . '$#ui';
                $patterns[] = [$type, $host];
                continue;
            }
            @list($host, $mask) = explode('/', $entry, 2);
            $host = @inet_pton($host);
            if (false === $host || (int) $mask != $mask) {
                $this->output->writeln(sprintf('<error>Invalid subnet "%s"</error>', $entry));
                continue;
            }
            $host = unpack('N*', $host);
            if (null === $mask) {
                $mask = 'ipv4' === $type ? 32 : 128;
            } else {
                $mask = (int) $mask;
                if ($mask < 0 || 'ipv4' === $type && $mask > 32 || 'ipv6' === $type && $mask > 128) {
                    continue;
                }
            }
            $patterns[] = [$type, $host, $mask];
        }

        return $patterns;
    }

    /**
     * Apply the patterns from strip-hosts
     */
    private function applyStripHosts()
    {
        if (false === $this->stripHosts) {
            return;
        }
        foreach ($this->selected as $uniqueName => $package) {
            $sources = [];
            if ($package->getSourceType()) {
                $sources[] = 'source';
            }
            if ($package->getDistType()) {
                $sources[] = 'dist';
            }
            foreach ($sources as $i => $s) {
                $url = 'source' === $s ? $package->getSourceUrl() : $package->getDistUrl();
                // skip distURL applied by ArchiveBuilder
                if ('dist' === $s && null !== $this->archiveEndpoint
                    && substr($url, 0, strlen($this->archiveEndpoint)) === $this->archiveEndpoint
                ) {
                    continue;
                }
                if ($this->matchStripHostsPatterns($url)) {
                    if ('dist' === $s) {
                        // if the type is not set, ArrayDumper ignores the other properties
                        $package->setDistType(null);
                    } else {
                        $package->setSourceType(null);
                    }
                    unset($sources[$i]);
                    if (0 === count($sources)) {
                        $this->output->writeln(sprintf('<error>%s has no source left after applying the strip-hosts filters and will be removed</error>', $package->getUniqueName()));
                        unset($this->selected[$uniqueName]);
                    }
                }
            }
        }
    }

    /**
     * Match an URL against the patterns from strip-hosts
     *
     * @return bool
     */
    private function matchStripHostsPatterns($url)
    {
        if (Filesystem::isLocalPath($url)) {
            return true;
        }
        if (is_array($this->stripHosts)) {
            $url = trim(parse_url($url, PHP_URL_HOST), '[]');
            if (false !== filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $urltype = 'ipv4';
            } elseif (false !== filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $urltype = 'ipv6';
            } else {
                $urltype = 'name';
            }
            if ('ipv4' === $urltype || 'ipv6' === $urltype) {
                $urlunpack = unpack('N*', @inet_pton($url));
            }

            foreach ($this->stripHosts as $pattern) {
                @list($type, $host, $mask) = $pattern;
                if ('/local' === $type) {
                    if ('name' === $urltype && 'localhost' === strtolower($url)
                        || ('ipv4' === $urltype || 'ipv6' === $urltype)
                        && false === filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)
                    ) {
                        return true;
                    }
                } elseif ('/private' === $type) {
                    if (('ipv4' === $urltype || 'ipv6' === $urltype)
                        && false === filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)
                    ) {
                        return true;
                    }
                } elseif ('ipv4' === $type || 'ipv6' === $type) {
                    if ($urltype === $type && $this->matchAddr($urlunpack, $host, $mask)) {
                        return true;
                    }
                } elseif ('name' === $type) {
                    if ('name' === $urltype && preg_match($host, $url)) {
                        return true;
                    }
                }
            }
        }
    }

    /**
     * Test if two addresses have the same prefix
     *
     * @param int[] $addr1
     *  Chunked addr
     * @param int[] $addr2
     *  Chunked addr
     * @param int $len
     *  Length of the test
     * @param int $chunklen
     *  Length of each chunk
     *
     * @return bool
     */
    private function matchAddr($addr1, $addr2, $len = 0, $chunklen = 32)
    {
        for (; $len > 0; $len -= $chunklen, next($addr1), next($addr2)) {
            $shift = $len >= $chunklen ? 0 : $chunklen - $len;
            if ((current($addr1) >> $shift) !== (current($addr2) >> $shift)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add repositories to a pool
     *
     * @param Pool  $pool
     *  The Pool instance
     * @param RepositoryInterface[] $repos
     *  Array of repositories
     */
    private function addRepositories(Pool $pool, $repos)
    {
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
     * @return Link[]
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
     * @param array  $repos            List of all Repositories configured
     * @param string $minimumStability The minimum stability each package must have to be selected
     * @param bool   $verbose          Output info if true
     *
     * @return Link[]|Package[]
     */
    private function getAllLinks($repos, $minimumStability, $verbose)
    {
        $links = [];

        foreach ($repos as $repo) {
            // collect links for composer repos with providers
            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                foreach ($repo->getProviderNames() as $name) {
                    $links[] = new Link('__root__', $name, new EmptyConstraint(), 'requires', '*');
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
                            $this->output->writeln('Skipped ' . $package->getPrettyName() . ' (' . $package->getStability() . ')');
                        }
                        continue;
                    }

                    $links[] = $package;
                }
            }
        }

        return $links;
    }

    /**
     * Add the linked packages to the selection
     *
     * @param Pool  $pool
     *  Pool used to search for linked packages
     * @param Link[]|PackageInterface[]  $links
     *  Array of links or packages
     * @param bool $isRoot
     *  If the packages are linked in root or as dependency
     * @param bool $verbose
     *  Output informations if true
     *
     * @return Link[]
     */
    private function selectLinks(Pool $pool, $links, bool $isRoot, bool $verbose)
    {
        $depsLinks = $isRoot ? [] : $links;

        $policies = [
            new DefaultPolicy(true, false),
            new DefaultPolicy(false, false),
            new DefaultPolicy(true, true),
            new DefaultPolicy(false, true),
        ];

        reset($links);
        while (null !== key($links)) {
            $link = current($links);

            if (is_a($link, PackageInterface::class)) {
                $matches = [$link];
            } elseif (is_a($link, Link::class)) {
                $name = $link->getTarget();
                $matches = $pool->whatProvides($name, $link->getConstraint(), true);
                if (0 === \count($matches)) {
                    $this->output->writeln('<error>The ' . $name . ' ' . $link->getPrettyConstraint() . ' requirement did not match any package</error>');
                }
            }

            if (!$isRoot && $this->requireDependencyFilter && \count($matches) > 1) {
                // filter matches like Composer's installer
                \array_walk($matches, function (&$package) {
                    $package = $package->getId();
                });
                $m = [];
                foreach ($policies as $policy) {
                    $pm = $policy->selectPreferredPackages($pool, [], $matches);
                    if (isset($pm[0])) {
                        $m[] = $pool->packageById($pm[0]);
                    }
                }
                $matches = $m;
            }

            foreach ($matches as $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                $uniqueName = $package->getUniqueName();
                // add matching package if not yet selected
                if (!isset($this->selected[$uniqueName])) {
                    if ($verbose) {
                        $this->output->writeln('Selected ' . $package->getPrettyName() . ' (' . $package->getPrettyVersion() . ')');
                    }
                    $this->selected[$uniqueName] = $package;

                    $required = $this->getRequired($package, $isRoot);
                    // append non-platform dependencies
                    foreach ($required as $dependencyLink) {
                        $target = $dependencyLink->getTarget();
                        if (!preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $target)) {
                            $linkId = $target . ' ' . $dependencyLink->getConstraint();
                            // prevent loading multiple times the same link
                            if (!isset($depsLinks[$linkId])) {
                                if (false === $isRoot) {
                                    $links[] = $dependencyLink;
                                }
                                $depsLinks[$linkId] = $dependencyLink;
                            }
                        }
                    }
                }
            }

            next($links);
        }

        return $depsLinks;
    }

    /**
     * Create the additional repositories
     *
     * @return RepositoryInterface[]
     */
    private function getDepRepos(Composer $composer)
    {
        $depRepos = [];
        if (\is_array($this->depRepositories)) {
            $rm = $composer->getRepositoryManager();
            foreach ($this->depRepositories as $index => $repoConfig) {
                $name = \is_int($index) && isset($repoConfig['url']) ? $repoConfig['url'] : $index;
                $type = $repoConfig['type'] ?? '';
                $depRepos[$index] = $rm->createRepository($type, $repoConfig, $name);
            }
        }

        return $depRepos;
    }

    /**
     * Gets All or filtered Packages of a Repository.
     *
     * @param RepositoryInterface $repo a Repository
     *
     * @return PackageInterface[]
     */
    private function getPackages(RepositoryInterface $repo)
    {
        $packages = [];

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
     * @param PackageInterface $package A package
     * @param bool $isRoot
     *  If the packages are linked in root or as dependency
     *
     * @return Link[]
     */
    private function getRequired(PackageInterface $package, bool $isRoot)
    {
        $required = [];

        if ($this->requireDependencies) {
            $required = $package->getRequires();
        }
        if (($isRoot || !$this->requireDependencyFilter) && $this->requireDevDependencies) {
            $required = array_merge($required, $package->getDevRequires());
        }

        return $required;
    }

    /**
     * Filter given repositories.
     *
     * @param RepositoryInterface[] $repositories
     *
     * @return RepositoryInterface[]
     */
    private function filterRepositories(array $repositories)
    {
        $url = $this->repositoryFilter;

        return array_filter($repositories, function ($repository) use ($url) {
            if (!($repository instanceof ConfigurableRepositoryInterface)) {
                return false;
            }

            $config = $repository->getRepoConfig();

            if (!isset($config['url']) || $config['url'] !== $url) {
                return false;
            }

            return true;
        });
    }
}
