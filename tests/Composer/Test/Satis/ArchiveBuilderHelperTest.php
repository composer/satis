<?php

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Test\Satis;

use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Satis\Builder\ArchiveBuilderHelper;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class ArchiveBuilderHelperTest extends \PHPUnit_Framework_TestCase
{
    protected $output;

    public function setUp()
    {
        $this->output = new NullOutput();
    }

    public function dataDirectories()
    {
        $data = array();

        $data['absolute-directory configured'] = array(
            '/home/satis/build/dist',
            '.',
            array('absolute-directory' => '/home/satis/build/dist'),
        );

        $data['absolute-directory not configured'] = array(
            'build/dist',
            'build',
            array('directory' => 'dist'),
        );

        return $data;
    }

    /**
     * @dataProvider dataDirectories
     */
    public function testDirectoryConfig($expected, $outputDir, $config)
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
        $package3->setProvides(array(new Link('', 'vendor/name')));

        $data = array();

        $data['metapackage'] = array(
            true,
            $metapackage,
            array(),
        );

        $data['skipDev is true, but package is not'] = array(
            false,
            $package1,
            array('skip-dev' => 1),
        );

        $data['skipDev is true, package isDev'] = array(
            true,
            $package2,
            array('skip-dev' => 1),
        );

        $data['package in whitelist'] = array(
            false,
            $package1,
            array('whitelist' => array('vendor/name')),
        );

        $data['package not in whitelist'] = array(
            true,
            $package1,
            array('whitelist' => array('othervendor/othername')),
        );

        $data['package in blacklist'] = array(
            true,
            $package1,
            array('blacklist' => array('vendor/name')),
        );

        $data['package not in blacklist'] = array(
            false,
            $package1,
            array('blacklist' => array('othervendor/othername')),
        );

        $data['package provides a virtual package in blacklist'] = array(
            true,
            $package3,
            array('blacklist' => array('vendor/name')),
        );

        return $data;
    }

    /**
     * @dataProvider dataPackages
     */
    public function testSkipDump($expected, $package, $config)
    {
        $helper = new ArchiveBuilderHelper($this->output, $config);
        $this->assertEquals($helper->isSkippable($package), $expected);
    }
}
