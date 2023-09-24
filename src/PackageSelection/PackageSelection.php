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
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionSelector;
use Composer\PartialComposer;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\VersionParser;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;

class PackageSelection
{
    /** The output Interface. */
    protected OutputInterface $output;

    /** Skips Exceptions if true. */
    protected bool $skipErrors;

    /** @var string packages.json file name. */
    private $filename;

    /** @var mixed Array of additional repositories for dependencies */
    private $depRepositories;

    /** Selects All Packages if true. */
    private bool $requireAll;

    /** Add required dependencies if true. */
    private bool $requireDependencies;

    /** required dev-dependencies if true. */
    private bool $requireDevDependencies;

    /** do not build packages only dependencies */
    private bool $onlyDependencies;

    /** only resolve best candidates in dependencies */
    private bool $onlyBestCandidates;

    /** Filter dependencies if true. */
    private bool $requireDependencyFilter;

    /** Minimum stability accepted for Packages in the list. */
    private string $minimumStability;

    /** @var string[] Minimum stability accepted by Package. */
    private array $minimumStabilityPerPackage;

    /** @var string[] The active package filter to merge. */
    private array $packagesFilter = [];

    /** @var string[]|null The active repository filter to merge. */
    private ?array $repositoriesFilter = null;

    /** @var mixed Repositories mentioned in the satis config */
    private $repositories;

    /** Apply the filter also for resolving dependencies. */
    private bool $repositoryFilterDep;

    /** @var PackageInterface[] The selected packages from config */
    private array $selected = [];

    /** @var string[] A list of packages marked as abandoned */
    private array $abandoned = [];

    /** @var string[] A list of blacklisted package/constraints. */
    private array $blacklist = [];

    /** @var string[]|null A list of package types. If set only packages with one of these types will be selected */
    private ?array $includeTypes;

    /** @var string[] A list of package types that will not be selected */
    private array $excludeTypes = [];

    /** @var mixed Patterns from strip-hosts. */
    private $stripHosts = false;

    /** The prefix of the distURLs when using archive. */
    private ?string $archiveEndpoint = null;

    /** The homepage - needed to get the relative paths of the providers */
    private ?string $homepage = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors)
    {
        $this->output = $output;
        $this->skipErrors = $skipErrors;
        $this->filename = $outputDir . '/packages.json';
        $this->repositories = $config['repositories'] ?? [];
        $this->fetchOptions($config);
    }

    /**
     * @param string[]|null $repositoriesFilter
     */
    public function setRepositoriesFilter(?array $repositoriesFilter, bool $forDependencies = false): void
    {
        $this->repositoriesFilter = [] !== $repositoriesFilter ? $repositoriesFilter : null;
        $this->repositoryFilterDep = $forDependencies;
    }

    public function hasRepositoriesFilter(): bool
    {
        return null !== $this->repositoriesFilter;
    }

    public function hasBlacklist(): bool
    {
        return count($this->blacklist) > 0;
    }

    public function hasTypeFilter(): bool
    {
        return null !== $this->includeTypes || count($this->excludeTypes) > 0;
    }

    /**
     * @param string[] $packagesFilter
     */
    public function setPackagesFilter(array $packagesFilter = []): void
    {
        $this->packagesFilter = $packagesFilter;
    }

    public function hasFilterForPackages(): bool
    {
        return count($this->packagesFilter) > 0;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Exception
     *
     * @return PackageInterface[]
     */
    public function select(PartialComposer $composer, bool $verbose): array
    {
        // run over all packages and store matching ones
        $this->output->writeln('<info>Scanning packages</info>');

        $repos = $initialRepos = $composer->getRepositoryManager()->getRepositories();

        $stabilityFlags = array_map(function ($value) {
            return BasePackage::$stabilities[$value];
        }, $this->minimumStabilityPerPackage);

        if ($this->hasRepositoriesFilter()) {
            $repos = $this->filterRepositories($repos);

            if (0 === count($repos)) {
                throw new \InvalidArgumentException(sprintf('Specified repository URL(s) "%s" do not exist.', implode('", "', $this->repositoriesFilter)));
            }
        } else {
            // Only use repos explicitly activated in satis config if no further filter given
            $repos = [];
            // Todo: Use a filter function instead
            foreach ($initialRepos as $repo) {
                if ($repo instanceof ConfigurableRepositoryInterface) {
                    $config = $repo->getRepoConfig();
                    foreach ($this->repositories as $satisRepo) {
                        // TODO configurable repo types without URL attribute
                        // This is madness and should be an empty() but phpstan-strict-rules does not like empty()
                        if (
                            !isset($config['url'])
                            || !is_string($config['url'])
                            || '' === $config['url']
                            || !isset($satisRepo['url'])
                            || !is_string($satisRepo['url'])
                            || '' === $satisRepo['url']
                        ) {
                            continue;
                        }
                        // Treat any combination of missing or present trailing slash as equal
                        if (rtrim($config['url'], '/') == rtrim($satisRepo['url'], '/')) {
                            $repos[] = $repo;
                        }
                    }
                } else {
                    if ($repo instanceof ArrayRepository) {
                        $repos[] = $repo;
                    }
                }
            }
        }

        if ($this->hasFilterForPackages()) {
            $repos = $this->filterPackages($repos);

            if (0 === count($repos)) {
                throw new \InvalidArgumentException(sprintf('Could not find any repositories config with "name" matching your package(s) filter: %s', implode(', ', $this->packagesFilter)));
            }
        }

        $repositorySet = new RepositorySet($this->minimumStability, $stabilityFlags);
        $this->addRepositories($repositorySet, $repos);

        // determine the required packages
        $rootLinks = $this->requireAll ? $this->getAllLinks($repos, $this->minimumStability, $verbose) : $this->getFilteredLinks($composer);

        // select the required packages and determine dependencies
        $depsLinks = $this->selectLinks($repositorySet, $rootLinks, true, $verbose);

        if ($this->requireDependencies || $this->requireDevDependencies) {
            $repositorySet = new RepositorySet($this->minimumStability, $stabilityFlags);
            $this->addRepositories($repositorySet, $repos);
            // dependencies of required packages might have changed and be part of filtered repos
            if ($this->hasRepositoriesFilter() && true !== $this->repositoryFilterDep) {
                $this->addRepositories(
                    $repositorySet,
                    \array_udiff(
                        $initialRepos,
                        $repos,
                        fn ($a, $b) => (method_exists($a, 'getRepoName') ? $a->getRepoName() : '') <=> (method_exists($b, 'getRepoName') ? $b->getRepoName() : '')
                    )
                );
            }

            // additional repositories for dependencies
            if (!$this->hasRepositoriesFilter() || true !== $this->repositoryFilterDep) {
                $this->addRepositories($repositorySet, $this->getDepRepos($composer));
            }

            // select dependencies
            $this->selectLinks($repositorySet, $depsLinks, false, $verbose);
        }

        $this->setSelectedAsAbandoned();

        $this->pruneBlacklisted($repositorySet, $verbose);
        $this->pruneByType($verbose);

        ksort($this->selected, SORT_STRING);

        return $this->selected;
    }

    /**
     * @return PackageInterface[]
     */
    public function clean(): array
    {
        $this->applyStripHosts();

        return $this->selected;
    }

    /**
     * @return PackageInterface[]
     */
    public function load(): array
    {
        $packages = [];
        $rootJsonFile = new JsonFile($this->filename);
        $dirname = dirname($this->filename);

        if (!$rootJsonFile->exists()) {
            return $packages;
        }

        $loader = new ArrayLoader();
        $rootConfig = $rootJsonFile->read();
        $includes = [];

        if (isset($rootConfig['includes']) && is_array($rootConfig['includes'])) {
            $includes = $rootConfig['includes'];
        }

        if (isset($rootConfig['providers']) && is_array($rootConfig['providers']) && isset($rootConfig['providers-url'])) {
            $baseUrl = is_string($this->homepage) ? parse_url(rtrim($this->homepage, '/'), PHP_URL_PATH) . '/' : '';
            $baseUrlLength = strlen($baseUrl);

            foreach ($rootConfig['providers'] as $package => $provider) {
                $file = str_replace(['%package%', '%hash%'], [$package, $provider['sha256']], (string) $rootConfig['providers-url']);

                if (strlen($baseUrl) > 0 && substr($file, 0, $baseUrlLength) === $baseUrl) {
                    $file = substr($file, $baseUrlLength);
                }

                $includes[$file] = $provider;
            }
        }

        foreach (array_keys($includes) as $file) {
            $includedJsonFile = new JsonFile($dirname . '/' . $file);

            if (!$includedJsonFile->exists()) {
                $this->output->writeln(sprintf(
                    '<error>File \'%s\' does not exist, defined in "includes" in \'%s\'</error>',
                    $includedJsonFile->getPath(),
                    $rootJsonFile->getPath()
                ));

                continue;
            }

            $includedConfig = $includedJsonFile->read();

            if (!isset($includedConfig['packages']) || !is_array($includedConfig['packages'])) {
                continue;
            }

            $includedPackages = $includedConfig['packages'];

            foreach ($includedPackages as $name => $versions) {
                if (!is_array($versions)) {
                    continue;
                }

                foreach ($versions as $package) {
                    if (!is_array($package)) {
                        continue;
                    }

                    if (isset($package['name']) && in_array($package['name'], $this->packagesFilter, true)) {
                        continue;
                    }

                    $package = $loader->load($package);

                    if ($package instanceof AliasPackage) {
                        $package = $package->getAliasOf();
                    }

                    $packages[$package->getUniqueName()] = $package;
                }
            }
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function fetchOptions(array $config): void
    {
        $this->depRepositories = $config['repositories-dep'] ?? [];

        $this->requireAll = isset($config['require-all']) && true === $config['require-all'];
        $this->requireDependencies = isset($config['require-dependencies']) && true === $config['require-dependencies'];
        $this->requireDevDependencies = isset($config['require-dev-dependencies']) && true === $config['require-dev-dependencies'];
        $this->onlyDependencies = isset($config['only-dependencies']) && true === $config['only-dependencies'];
        $this->onlyBestCandidates = isset($config['only-best-candidates']) && true === $config['only-best-candidates'];
        $this->requireDependencyFilter = (bool) ($config['require-dependency-filter'] ?? true);

        if (!$this->requireAll && !isset($config['require'])) {
            $this->output->writeln('No explicit requires defined, enabling require-all');
            $this->requireAll = true;
        }

        $this->minimumStability = $config['minimum-stability'] ?? 'dev';
        $this->minimumStabilityPerPackage = $config['minimum-stability-per-package'] ?? [];
        $this->abandoned = $config['abandoned'] ?? [];
        $this->blacklist = $config['blacklist'] ?? [];
        $this->includeTypes = $config['include-types'] ?? null;
        $this->excludeTypes = $config['exclude-types'] ?? [];

        $this->stripHosts = $this->createStripHostsPatterns($config['strip-hosts'] ?? false);
        $this->archiveEndpoint = isset($config['archive']['directory']) ? ($config['archive']['prefix-url'] ?? $config['homepage']) . '/' : null;

        $this->homepage = $config['homepage'] ?? null;
    }

    /**
     * @param string[]|false $stripHostsConfig
     *
     * @return array<mixed>|false
     */
    private function createStripHostsPatterns($stripHostsConfig)
    {
        if (!is_array($stripHostsConfig)) {
            return $stripHostsConfig;
        }

        $patterns = [];

        foreach ($stripHostsConfig as $entry) {
            if (0 === strlen($entry)) {
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

            /** @var string|null $mask */
            if (false === $host || (int) $mask != $mask) {
                $this->output->writeln(sprintf('<error>Invalid subnet "%s"</error>', $entry));
                continue;
            }

            $host = unpack('N*', $host);

            if (null === $mask) {
                $mask = 'ipv4' === $type ? 32 : 128;
            } else {
                $mask = (int) $mask;

                if ($mask < 0 || ('ipv4' === $type && $mask > 32) || ('ipv6' === $type && $mask > 128)) {
                    continue;
                }
            }

            $patterns[] = [$type, $host, $mask];
        }

        return $patterns;
    }

    private function applyStripHosts(): void
    {
        if (false === $this->stripHosts) {
            return;
        }

        /** @var CompletePackage $package */
        foreach ($this->selected as $uniqueName => $package) {
            $sources = [];

            if (is_string($package->getSourceType())) {
                $sources[] = 'source';
            }

            if (is_string($package->getDistType())) {
                $sources[] = 'dist';
            }

            foreach ($sources as $index => $type) {
                $url = (string) ('source' === $type ? $package->getSourceUrl() : $package->getDistUrl());

                // skip distURL applied by ArchiveBuilder
                if ('dist' === $type && null !== $this->archiveEndpoint
                    && substr($url, 0, strlen($this->archiveEndpoint)) === $this->archiveEndpoint
                ) {
                    continue;
                }

                if ($this->matchStripHostsPatterns($url)) {
                    if ('dist' === $type) {
                        // if the type is not set, ArrayDumper ignores the other properties
                        $package->setDistType(null);
                    } else {
                        $package->setSourceType(null);
                    }

                    unset($sources[$index]);

                    if (0 === count($sources)) {
                        $this->output->writeln(sprintf('<error>%s has no source left after applying the strip-hosts filters and will be removed</error>', $package->getUniqueName()));

                        unset($this->selected[$uniqueName]);
                    }
                }
            }
        }
    }

    private function matchStripHostsPatterns(string $url): bool
    {
        if (Filesystem::isLocalPath($url)) {
            return true;
        }

        if (!is_array($this->stripHosts)) {
            return false;
        }

        $sshRegex = '#^[^@:\/]+@([^\/:]+)#ui';
        if (1 === preg_match($sshRegex, $url, $matches)) {
            $url = $matches[1];
        } else {
            $url = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        }

        if (false !== filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $urltype = 'ipv4';
        } elseif (false !== filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $urltype = 'ipv6';
        } else {
            $urltype = 'name';
        }

        $urlunpack = null;
        if ('ipv4' === $urltype || 'ipv6' === $urltype) {
            $urlunpack = (array) unpack('N*', (string) @inet_pton($url));
        }

        foreach ($this->stripHosts as $pattern) {
            @list($type, $host, $mask) = $pattern;

            if ('/local' === $type) {
                if (('name' === $urltype && 'localhost' === strtolower($url)) || (
                    ('ipv4' === $urltype || 'ipv6' === $urltype)
                    && false === filter_var($url, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)
                )) {
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
                if ('name' === $urltype && 1 === preg_match($host, $url)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Test if two addresses have the same prefix
     *
     * @param int[] $addr1 Chunked addr
     * @param int[] $addr2 Chunked addr
     * @param int $len Length of the test
     * @param int $chunklen Length of each chunk
     */
    private function matchAddr($addr1, $addr2, $len = 0, $chunklen = 32): bool
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
     * @param array<RepositoryInterface|ConfigurableRepositoryInterface> $repositories
     *
     * @throws \Exception
     */
    private function addRepositories(RepositorySet $repositorySet, array $repositories): void
    {
        foreach ($repositories as $repository) {
            try {
                if ($repository instanceof RepositoryInterface) {
                    $repositorySet->addRepository($repository);
                }
            } catch (\Exception $exception) {
                if (!$this->skipErrors) {
                    throw $exception;
                }

                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }
        }
    }

    private function setSelectedAsAbandoned(): void
    {
        /** @var CompletePackage $package */
        foreach ($this->selected as $name => $package) {
            if (array_key_exists($package->getName(), $this->abandoned)) {
                $package->setAbandoned($this->abandoned[$package->getName()]);
            }
        }
    }

    /**
     * Removes selected packages which are blacklisted in configuration.
     *
     * @return PackageInterface[]
     */
    private function pruneBlacklisted(RepositorySet $repositorySet, bool $verbose): array
    {
        $blacklisted = [];
        if ($this->hasBlacklist()) {
            $parser = new VersionParser();
            $pool = $repositorySet->createPoolWithAllPackages();
            /** @var BasePackage $package */
            foreach ($this->selected as $selectedKey => $package) {
                foreach ($this->blacklist as $blacklistName => $blacklistConstraint) {
                    $constraint = $parser->parseConstraints($blacklistConstraint);
                    if ($pool->match($package, $blacklistName, $constraint)) {
                        if ($verbose) {
                            $this->output->writeln('Blacklisted ' . $package->getPrettyName() . ' (' . $package->getPrettyVersion() . ')');
                        }
                        $blacklisted[$selectedKey] = $package;
                        unset($this->selected[$selectedKey]);
                    }
                }
            }
        }

        return $blacklisted;
    }

    /**
     * Removes packages with types that don't match the configuration
     *
     * @return PackageInterface[]
     */
    private function pruneByType(bool $verbose): array
    {
        $excluded = [];
        if ($this->hasTypeFilter()) {
            foreach ($this->selected as $selectedKey => $package) {
                if (null !== $this->includeTypes && !in_array($package->getType(), $this->includeTypes, true)) {
                    if ($verbose) {
                        $this->output->writeln(
                            'Excluded ' . $package->getPrettyName()
                            . ' (' . $package->getPrettyVersion() . ') because '
                            . $package->getType() . ' was not in the array of types to include.'
                        );
                    }
                    $excluded[$selectedKey] = $package;
                    unset($this->selected[$selectedKey]);
                } elseif (in_array($package->getType(), $this->excludeTypes, true)) {
                    if ($verbose) {
                        $this->output->writeln(
                            'Excluded ' . $package->getPrettyName()
                            . ' (' . $package->getPrettyVersion() . ') because '
                            . $package->getType() . ' was in the array of types to exclude.'
                        );
                    }
                    $excluded[$selectedKey] = $package;
                    unset($this->selected[$selectedKey]);
                }
            }
        }

        return $excluded;
    }

    /**
     * Gets a list of filtered Links.
     *
     * @return Link[]
     */
    private function getFilteredLinks(PartialComposer $composer): array
    {
        $links = array_values($composer->getPackage()->getRequires());

        if (!$this->hasFilterForPackages()) {
            return $links;
        }

        $packagesFilter = $this->packagesFilter;
        $links = array_filter(
            $links,
            function (Link $link) use ($packagesFilter) {
                return in_array($link->getTarget(), $packagesFilter, true);
            }
        );

        return array_values($links);
    }

    /**
     * @param array<RepositoryInterface|ConfigurableRepositoryInterface> $repositories
     *
     * @return Link[]|PackageInterface[]
     */
    private function getAllLinks(array $repositories, string $minimumStability, bool $verbose): array
    {
        $links = [];

        foreach ($repositories as $repository) {
            if ($repository instanceof ComposerRepository) {
                foreach ($repository->getPackageNames() as $name) {
                    $links[] = new Link('__root__', $name, new MatchAllConstraint(), 'requires', '*');
                }
                continue;
            }

            try {
                if ($repository instanceof RepositoryInterface) {
                    $packages = $this->getPackages($repository);
                } else {
                    continue;
                }
            } catch (\Exception $exception) {
                if (!$this->skipErrors) {
                    throw $exception;
                }

                $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
                continue;
            }

            foreach ($packages as $package) {
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

        return $links;
    }

    /**
     * @param array<Link|PackageInterface> $links
     *
     * @return array<Link|PackageInterface>
     */
    private function selectLinks(RepositorySet $repositorySet, array $links, bool $isRoot, bool $verbose): array
    {
        $depsLinks = $isRoot ? [] : $links;

        reset($links);

        while (null !== key($links)) {
            $link = current($links);
            $matches = [];
            if (false !== $link && is_a($link, PackageInterface::class)) {
                $matches = [$link];
            } elseif (false !== $link && is_a($link, Link::class)) {
                $name = $link->getTarget();
                if (!$isRoot && $this->onlyBestCandidates) {
                    $selector = new VersionSelector($repositorySet);
                    $match = $selector->findBestCandidate($name, $link->getConstraint()->getPrettyString());
                    $matches = false !== $match ? [$match] : [];
                } elseif (PlatformRepository::isPlatformPackage($name)) {
                } else {
                    $matches = $repositorySet->createPoolForPackage($link->getTarget())->whatProvides($name, $link->getConstraint());
                }

                if (0 === \count($matches)) {
                    $this->output->writeln('<error>The ' . $name . ' ' . $link->getPrettyConstraint() . ' requirement did not match any package</error>');
                }
            }

            foreach ($matches as $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                $uniqueName = $package->getUniqueName();
                $prettyVersion = $package->getPrettyVersion();

                if (!is_string($prettyVersion)) {
                    $this->output->writeln('<notice>Skipping ' . $package->getPrettyName() . ' (' . $prettyVersion . ') due to invalid version type</notice>');

                    continue;
                }

                // Check if + character is present, only once according to Semver;
                // otherwise metadata will stripped as usual
                if (1 === substr_count($prettyVersion, '+')) {
                    // re-inject metadata because it has been stripped by the VersionParser
                    if (1 === preg_match('/.+(\+[0-9A-Za-z-]*)$/', $prettyVersion, $match)) {
                        $uniqueName .= $match[1];
                    }
                }

                // add matching package if not yet selected
                if (!isset($this->selected[$uniqueName])) {
                    if (false === $isRoot || false === $this->onlyDependencies) {
                        if ($verbose) {
                            $this->output->writeln('Selected ' . $package->getPrettyName() . ' (' . $prettyVersion . ')');
                        }
                        $this->selected[$uniqueName] = $package;
                    }

                    $required = $this->getRequired($package, $isRoot);
                    // append non-platform dependencies
                    foreach ($required as $dependencyLink) {
                        $target = $dependencyLink->getTarget();
                        if (!PlatformRepository::isPlatformPackage($target)) {
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
     * @return RepositoryInterface[]
     */
    private function getDepRepos(PartialComposer $composer): array
    {
        $repositories = [];

        if (\is_array($this->depRepositories)) {
            $repositoryManager = $composer->getRepositoryManager();

            foreach ($this->depRepositories as $index => $config) {
                $name = \is_int($index) && isset($config['url']) ? $config['url'] : $index;
                $type = $config['type'] ?? '';
                $repositories[$index] = $repositoryManager->createRepository($type, $config, $name);
            }
        }

        return $repositories;
    }

    /**
     * @return PackageInterface[]
     */
    private function getPackages(RepositoryInterface $repo): array
    {
        $packages = [];

        if (!$this->hasFilterForPackages()) {
            return $repo->getPackages();
        }

        foreach ($this->packagesFilter as $filter) {
            $packages += $repo->findPackages($filter);
        }

        return $packages;
    }

    /**
     * @return Link[]
     */
    private function getRequired(PackageInterface $package, bool $isRoot): array
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
     * @param RepositoryInterface[]|ConfigurableRepositoryInterface[] $repositories
     *
     * @return RepositoryInterface[]|ConfigurableRepositoryInterface[]
     */
    private function filterRepositories(array $repositories): array
    {
        return array_filter(
            $repositories,
            function ($repository) {
                if (!($repository instanceof ConfigurableRepositoryInterface)) {
                    return false;
                }

                $config = $repository->getRepoConfig();

                if (!isset($config['url'])) {
                    return false;
                }

                return in_array($config['url'], $this->repositoriesFilter ?? [], true);
            }
        );
    }

    /**
     * @param RepositoryInterface[]|ConfigurableRepositoryInterface[] $repositories
     *
     * @return RepositoryInterface[]|ConfigurableRepositoryInterface[]
     */
    private function filterPackages(array $repositories): array
    {
        $packages = $this->packagesFilter;

        return array_filter(
            $repositories,
            static function ($repository) use ($packages) {
                if (!($repository instanceof ConfigurableRepositoryInterface)) {
                    return false;
                }

                $config = $repository->getRepoConfig();

                // We need name to be set on repo config as it would otherwise be too slow on remote repos (VCS, ..)
                if (!isset($config['name']) || !in_array($config['name'], $packages, true)) {
                    return false;
                }

                return true;
            }
        );
    }
}
