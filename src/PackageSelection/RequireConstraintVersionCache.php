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

use Composer\Repository\VersionCacheInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;

/**
 * Lets a VcsRepository skip tags not matching the required constraint, before their composer.json is read.
 *
 * This is exact for tags: VcsRepository discards every tag whose composer.json declares a version differing from the
 * tag name, so a tag outside the constraint could never be selected anyway. Branches are never skipped, as their
 * branch-alias in composer.json may map them into the constraint.
 */
final readonly class RequireConstraintVersionCache implements VersionCacheInterface
{
    private VersionParser $versionParser;

    public function __construct(private ConstraintInterface $constraint)
    {
        $this->versionParser = new VersionParser();
    }

    /**
     * The repository treats false as a reference without composer.json: it lists the reference in
     * getEmptyReferences() and, if very verbose, logs "Skipped <tag>, no composer file". The interface offers no other
     * way to skip a version.
     *
     * @return false|null False to skip the version, null to let the repository read it as usual
     */
    public function getVersionPackage(string $version, string $identifier): ?false
    {
        try {
            $normalizedVersion = $this->versionParser->normalize($version);
        } catch (\UnexpectedValueException) {
            // Invalid tag names are handled by the repository
            return null;
        }

        if ('dev' === VersionParser::parseStability($normalizedVersion)) {
            return null;
        }

        return $this->constraint->matches(new Constraint('==', $normalizedVersion)) ? null : false;
    }
}
