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

use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Semver\Constraint\MatchAllConstraint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class ArchiveBuilderHelperTest extends TestCase
{
    protected NullOutput $output;

    public function setUp(): void
    {
        $this->output = new NullOutput();
    }

    /**
     * @return array<string, mixed>
     */
    public function dataDirectories(): array
    {
        $data = [];

        $data['absolute-directory configured'] = [
            '/home/satis/build/dist',
            '.',
            ['absolute-directory' => '/home/satis/build/dist'],
        ];

        $data['absolute-directory not configured'] = [
            'build/dist',
            'build',
            ['directory' => 'dist'],
        ];

        return $data;
    }

    /**
     * @dataProvider dataDirectories
     *
     * @param array<string, mixed> $config
     */
    public function testDirectoryConfig(string $expected, string $outputDir, array $config): void
    {
        $helper = new ArchiveBuilderHelper($this->output, $config);
        self::assertEquals($helper->getDirectory($outputDir), $expected);
    }

    /**
     * @return array<string, mixed>
     */
    public function dataPackages(): array
    {
        $metapackage = new Package('vendor/name', '1.0.0.0', '1.0');
        $metapackage->setType('metapackage');
        
        // Normal package with source information (typical case)
        $package1 = new Package('vendor/name', '1.0.0.0', '1.0');
        $package1->setSourceType('git');
        $package1->setSourceUrl('https://github.com/vendor/name.git');
        $package1->setSourceReference('abc123');
        
        $package2 = new Package('vendor/name', 'dev-master', 'dev-master');
        $package2->setSourceType('git');
        $package2->setSourceUrl('https://github.com/vendor/name.git');
        $package2->setSourceReference('def456');
        
        $package3 = new Package('othervendor/othername', '1.0.0.0', '1.0');
        $package3->setSourceType('git');
        $package3->setSourceUrl('https://github.com/othervendor/othername.git');
        $package3->setSourceReference('ghi789');
        $link = new Link('', 'vendor/name', new MatchAllConstraint());
        $package3->setProvides([$link->getTarget() => $link]);

        $data = [];

        $data['metapackage'] = [
            true,
            $metapackage,
            [],
        ];

        $data['skipDev is true, but package is not'] = [
            false,
            $package1,
            ['skip-dev' => 1],
        ];

        $data['skipDev is true, package isDev'] = [
            true,
            $package2,
            ['skip-dev' => 1],
        ];

        $data['package in whitelist'] = [
            false,
            $package1,
            ['whitelist' => ['vendor/name']],
        ];

        $data['package not in whitelist'] = [
            true,
            $package1,
            ['whitelist' => ['othervendor/othername']],
        ];

        $data['package in blacklist'] = [
            true,
            $package1,
            ['blacklist' => ['vendor/name']],
        ];

        $data['package not in blacklist'] = [
            false,
            $package1,
            ['blacklist' => ['othervendor/othername']],
        ];

        $data['package provides a virtual package in blacklist'] = [
            true,
            $package3,
            ['blacklist' => ['vendor/name']],
        ];

        // Package with empty source fields (like phpstan/phpstan)
        $packageWithEmptySource = new Package('phpstan/phpstan', '1.12.32.0', '1.12.32');
        $packageWithEmptySource->setSourceType('');
        $packageWithEmptySource->setSourceUrl('');
        $packageWithEmptySource->setSourceReference('');
        $packageWithEmptySource->setDistType('');
        $packageWithEmptySource->setDistUrl('');
        $packageWithEmptySource->setDistReference('');

        $data['package with empty source and dist fields'] = [
            true,
            $packageWithEmptySource,
            [],
        ];

        return $data;
    }

    /**
     * @dataProvider dataPackages
     *
     * @param array<string, mixed> $config
     */
    public function testSkipDump(bool $expected, Package $package, array $config): void
    {
        $helper = new ArchiveBuilderHelper($this->output, $config);
        self::assertEquals($helper->isSkippable($package), $expected);
    }
}
