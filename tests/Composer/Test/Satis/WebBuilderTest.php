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

use Composer\Package\Package;
use Composer\Satis\Builder\WebBuilder;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderTest extends \PHPUnit_Framework_TestCase
{
    protected $webBuilder;

    public function setUp()
    {
        $this->webBuilder = new WebBuilder(new NullOutput(), 'build', array(), false);
    }

    public function dataGetDescSortedVersions()
    {
        $data = array();

        $data['test1 stable versions'] = array(
            array(
                new Package('vendor/name', '2.0.1.0', '2.0.1'),
                new Package('vendor/name', '2.0.0.0', '2.0'),
                new Package('vendor/name', '1.1.0.0', '1.1'),
                new Package('vendor/name', '1.0.0.0', '1.0'),
            ),
            array(
                array(
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                    new Package('vendor/name', '1.1.0.0', '1.1'),
                    new Package('vendor/name', '2.0.1.0', '2.0.1'),
                ),
            ),
        );

        return $data;
    }

    /**
     * @dataProvider dataGetDescSortedVersions
     */
    public function testGetDescSortedVersions($expected, $packages)
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('getDescSortedVersions');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }

    public function dataGetHighestVersion()
    {
        $data = array();

        $data['test1 stable versions'] = array(
            new Package('vendor/name', '2.0.1.0', '2.0.1'),
            array(
                array(
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                    new Package('vendor/name', '1.1.0.0', '1.1'),
                    new Package('vendor/name', '2.0.1.0', '2.0.1'),
                ),
            ),
        );

        return $data;
    }

    /**
     * @dataProvider dataGetHighestVersion
     */
    public function testGetHighestVersion($expected, $packages)
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('getHighestVersion');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }

    public function dataGroupPackagesByName()
    {
        $data = array();

        $data['test1 stable versions'] = array(
            array(
                'vendor/name' => array(
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                ),
                'othervendor/othername' => array(
                    new Package('othervendor/othername', '1.1.0.0', '1.1'),
                    new Package('othervendor/othername', '2.0.1.0', '2.0.1'),
                ),
            ),
            array(
                array(
                    new Package('vendor/name', '1.0.0.0', '1.0'),
                    new Package('othervendor/othername', '1.1.0.0', '1.1'),
                    new Package('vendor/name', '2.0.0.0', '2.0'),
                    new Package('othervendor/othername', '2.0.1.0', '2.0.1'),
                ),
            ),
        );

        return $data;
    }

    /**
     * @dataProvider dataGroupPackagesByName
     */
    public function testGroupPackagesByName($expected, $packages)
    {
        $reflection = new \ReflectionClass(get_class($this->webBuilder));
        $method = $reflection->getMethod('groupPackagesByName');
        $method->setAccessible(true);

        $this->assertEquals($expected, $method->invokeArgs($this->webBuilder, $packages));
    }
}
