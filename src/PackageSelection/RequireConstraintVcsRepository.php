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

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\VcsRepository;
use Composer\Repository\VersionCacheInterface;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;

/**
 * A VcsRepository skipping the tags of a required package that do not match the required constraint.
 *
 * Tags are only skipped once the composer.json of the default branch declares the required package. The repository
 * then gives all its versions that name, so a skipped tag could never be selected. Without that composer.json, every
 * tag keeps the name of its own composer.json, and all tags are read.
 */
final class RequireConstraintVcsRepository extends VcsRepository implements VersionCacheInterface
{
    private RequireConstraintVersionCache $tagFilter;

    private bool $skippedTags = false;

    /**
     * @param array{url: string, type?: string}&array<string, mixed> $repoConfig
     */
    public function __construct(
        array $repoConfig,
        private string $requiredName,
        ConstraintInterface $constraint,
        IOInterface $io,
        Config $config,
        HttpDownloader $httpDownloader,
        ?EventDispatcher $dispatcher,
        ?ProcessExecutor $process,
    ) {
        $this->tagFilter = new RequireConstraintVersionCache($constraint);

        parent::__construct($repoConfig, $io, $config, $httpDownloader, $dispatcher, $process, null, $this);
    }

    /**
     * @return false|null False to skip the version, null to let the repository read it as usual
     */
    public function getVersionPackage(string $version, string $identifier): ?false
    {
        if (null === $this->packageName || strtolower($this->packageName) !== $this->requiredName) {
            return null;
        }

        $versionPackage = $this->tagFilter->getVersionPackage($version, $identifier);
        $this->skippedTags = $this->skippedTags || false === $versionPackage;

        return $versionPackage;
    }

    protected function initialize(): void
    {
        try {
            parent::initialize();
        } catch (InvalidRepositoryException $exception) {
            // Without the skipped tags, which could never be selected, the repository has no package left. Without
            // the filter, it would provide no selectable package either.
            if (!$this->skippedTags) {
                throw $exception;
            }
        }
    }
}
