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

use Composer\Semver\VersionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequireConstraintVersionCacheTest extends TestCase
{
    /**
     * @return array<string, array{string, string, false|null}>
     */
    public static function getVersions(): array
    {
        return [
            'tag matching the constraint' => ['^10.4 || ^11.5', 'v10.4.12', null],
            'tag matching another part of the constraint' => ['^10.4 || ^11.5', '11.5.3', null],
            'tag below the constraint' => ['^10.4 || ^11.5', 'v9.5.31', false],
            'tag between the parts of the constraint' => ['^10.4 || ^11.5', 'v11.4.0', false],
            'tag above the constraint' => ['^10.4 || ^11.5', 'v12.4.0', false],
            'tag with stability flag constraint' => ['3.4.1@stable', 'v3.4.1', null],
            'pre-release tag outside the constraint' => ['^10.4', 'v9.0.0-RC1', false],
            'branch is never skipped' => ['^10.4', 'dev-main', null],
            'version branch is never skipped' => ['^10.4', '9.5.x-dev', null],
            'invalid tag name is left to the repository' => ['^10.4', 'not-a-version', null],
        ];
    }

    #[DataProvider('getVersions')]
    public function testGetVersionPackage(string $constraint, string $version, ?false $expected): void
    {
        $versionCache = new RequireConstraintVersionCache((new VersionParser())->parseConstraints($constraint));

        self::assertSame($expected, $versionCache->getVersionPackage($version, 'c41c218e239b50446fd883acb1ecfd4b770caeae'));
    }
}
