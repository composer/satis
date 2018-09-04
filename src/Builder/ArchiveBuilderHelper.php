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

use Composer\Package\PackageInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the archives of the repository.
 *
 * @author James Hautot <james@rezo.net>
 */
class ArchiveBuilderHelper
{
    /** @var OutputInterface The output Interface. */
    private $output;

    /** @var array The 'archive' part of a configuration file. */
    private $archiveConfig;

    /**
     * Helper Constructor.
     *
     * @param OutputInterface $output        The output Interface
     * @param array           $archiveConfig The 'archive' part of a configuration file
     */
    public function __construct(OutputInterface $output, array $archiveConfig)
    {
        $this->output = $output;
        $this->archiveConfig = $archiveConfig;
        $this->archiveConfig['skip-dev'] = (bool) ($archiveConfig['skip-dev'] ?? false);
        $this->archiveConfig['whitelist'] = (array) ($archiveConfig['whitelist'] ?? []);
        $this->archiveConfig['blacklist'] = (array) ($archiveConfig['blacklist'] ?? []);
    }

    /**
     * Gets the directory where to dump archives.
     *
     * @param string $outputDir The directory where to build
     *
     * @return string $directory The directory where to dump archives
     */
    public function getDirectory($outputDir)
    {
        if (isset($this->archiveConfig['absolute-directory'])) {
            $directory = $this->archiveConfig['absolute-directory'];
        } else {
            $directory = sprintf('%s/%s', $outputDir, $this->archiveConfig['directory']);
        }

        return $directory;
    }

    /**
     * Tells if a package has to be dumped or not.
     *
     * @param PackageInterface $package The package to be dumped
     *
     * @return bool false if the package has to be dumped
     */
    public function isSkippable(PackageInterface $package)
    {
        if ('metapackage' === $package->getType()) {
            return true;
        }

        $name = $package->getPrettyString();

        if (true === $this->archiveConfig['skip-dev'] && true === $package->isDev()) {
            $this->output->writeln(sprintf("<info>Skipping '%s' (is dev)</info>", $name));

            return true;
        }

        $names = $package->getNames();

        if ($this->archiveConfig['whitelist'] && !$this->isOneOfNamesInList($names, $this->archiveConfig['whitelist'])) {
            $this->output->writeln(sprintf("<info>Skipping '%s' (is not in whitelist)</info>", $name));

            return true;
        }

        if ($this->archiveConfig['blacklist'] && $this->isOneOfNamesInList($names, $this->archiveConfig['blacklist'])) {
            $this->output->writeln(sprintf("<info>Skipping '%s' (is in blacklist)</info>", $name));

            return true;
        }

        return false;
    }

    /**
     * Check if any of the names is in the list.
     *
     * Any * in the list is treated as a wildcard.
     *
     * @param array $names Names to check
     * @param array $list  List to check the names against
     *
     * @return bool true if any of the names is in the list
     */
    protected function isOneOfNamesInList(array $names, array $list)
    {
        $patterns = $this->convertListToRegexPatterns($list);

        foreach ($names as $name) {
            if ($this->doesNameMatchOneOfPatterns($name, $patterns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the name matches any of the patterns.
     *
     * @param string $name     Name to check
     * @param array  $patterns Patterns to check the name against
     *
     * @return bool true if the name matches any of the patterns
     */
    protected function doesNameMatchOneOfPatterns($name, array $patterns)
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert a list to regex patterns for use in preg_ functions.
     *
     * Any * is replaced with .* and the rest is escaped.
     *
     * @param array $list List to convert to patterns
     *
     * @return array array of patterns
     */
    protected function convertListToRegexPatterns(array $list)
    {
        $patterns = [];

        foreach ($list as $entry) {
            $pattern = explode('*', $entry);
            $pattern = array_map(function ($value) { return preg_quote($value, '/'); }, $pattern);
            $pattern = '/^' . implode('.*', $pattern) . '$/';

            $patterns[] = $pattern;
        }

        return $patterns;
    }
}
