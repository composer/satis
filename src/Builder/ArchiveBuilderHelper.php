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

class ArchiveBuilderHelper
{
    /** @var OutputInterface The output Interface. */
    private $output;
    /** @var array<string, mixed> The 'archive' part of a configuration file. */
    private $archiveConfig;

    /**
     * @param array<string, mixed> $archiveConfig
     */
    public function __construct(OutputInterface $output, array $archiveConfig)
    {
        $this->output = $output;
        $this->archiveConfig = $archiveConfig;
        $this->archiveConfig['skip-dev'] = (bool) ($archiveConfig['skip-dev'] ?? false);
        $this->archiveConfig['whitelist'] = (array) ($archiveConfig['whitelist'] ?? []);
        $this->archiveConfig['blacklist'] = (array) ($archiveConfig['blacklist'] ?? []);
    }

    public function getDirectory(string $outputDir): string
    {
        if (isset($this->archiveConfig['absolute-directory'])) {
            $directory = $this->archiveConfig['absolute-directory'];
        } else {
            $directory = sprintf('%s/%s', $outputDir, $this->archiveConfig['directory']);
        }

        return $directory;
    }

    public function isSkippable(PackageInterface $package): bool
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

        if (0 !== count($this->archiveConfig['whitelist']) && !$this->isOneOfNamesInList($names, $this->archiveConfig['whitelist'])) {
            $this->output->writeln(sprintf("<info>Skipping '%s' (is not in whitelist)</info>", $name));

            return true;
        }

        if (0 !== count($this->archiveConfig['blacklist']) && $this->isOneOfNamesInList($names, $this->archiveConfig['blacklist'])) {
            $this->output->writeln(sprintf("<info>Skipping '%s' (is in blacklist)</info>", $name));

            return true;
        }

        return false;
    }

    /**
     * @param list<string> $names
     * @param list<string> $list
     */
    protected function isOneOfNamesInList(array $names, array $list): bool
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
     * @param list<string> $patterns
     */
    protected function doesNameMatchOneOfPatterns(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    protected function convertListToRegexPatterns(array $list): array
    {
        $patterns = [];

        foreach ($list as $entry) {
            $pattern = explode('*', $entry);
            $pattern = array_map(static function ($value): string {
                return preg_quote($value, '/');
            }, $pattern);
            $pattern = '/^' . implode('.*', $pattern) . '$/';

            $patterns[] = $pattern;
        }

        return $patterns;
    }
}
