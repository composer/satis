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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class ArchiveBuilderHelperTest extends TestCase
{
    /** @var NullOutput */
    protected $output;

    public function setUp(): void
    {
        $this->output = new NullOutput();
    }

    public function dataDirectories()
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
     */
    public function testDirectoryConfig(string $expected, string $outputDir, array $config)
    {
        $helper = new ArchiveBuilderHelper($this->output, $config);
        $this->assertEquals($helper->getDirectory($outputDir), $expected);
    }

    public function dataPackages()
    {
        $metapackage = new Package('vendor/name', '1.0.0.0', '1.0');
        $metapackage->setType('metapackage');
        $package1 = new Package('vendor/name', '1.0.0.0', '1.0');
        $package2 = new Package('vendor/name', 'dev-master', 'dev-master');
        $package3 = new Package('othervendor/othername', '1.0.0.0', '1.0');
        $package3->setProvides([new Link('', 'vendor/name')]);

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

        return $data;
    }

    /**
     * @dataProvider dataPackages
     */
    public function testSkipDump(bool $expected, Package $package, array $config)
    {
        $helper = new ArchiveBuilderHelper($this->output, $config);
        $this->assertEquals($helper->isSkippable($package), $expected);
    }
}
