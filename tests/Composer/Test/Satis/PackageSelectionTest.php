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

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Satis\PackageSelection\PackageSelection;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackageSelectionTest extends \PHPUnit_Framework_TestCase
{
    public function dataGetPackages()
    {
        $emptyRepo = new ArrayRepository();
        $vendorRepo = new ArrayRepository();
        $vendorRepo2 = new ArrayRepository();

        $package = new Package('vendor/name', '1.0.0.0', '1.0');
        $package2 = new Package('vendor2/name', '1.0.0.0', '1.0');
        $package3 = new Package('vendor2/name2', '1.0.0.0', '1.0');
        $vendorRepo->addPackage($package);
        $vendorRepo2->addPackage($package2);
        $vendorRepo2->addPackage($package3);

        $data = array();

        $data['empty repository'] = array(
            array(),
            array(),
            $emptyRepo,
        );

        $data['empty repository with filter'] = array(
            array(),
            array('vendor/name'),
            $emptyRepo,
        );

        $data['repository with one package'] = array(
            array($package),
            array(),
            $vendorRepo,
        );

        $data['repository with one package and filter'] = array(
            array(),
            array('othervendor/othername'),
            $vendorRepo,
        );

        $data['repository with two packages'] = array(
            array($package2, $package3),
            array(),
            $vendorRepo2,
        );

        $data['repository with two packages and filter'] = array(
            array($package2),
            array('vendor2/name'),
            $vendorRepo2,
        );

        return $data;
    }

    /**
     * @dataProvider dataGetPackages
     */
    public function testGetPackages($expected, $filter, $repository)
    {
        $builder = new PackageSelection(new NullOutput(), 'build', array(), false);
        if (!empty($filter)) {
            $builder->setPackagesFilter($filter);
        }

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('getPackages');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invokeArgs($builder, array($repository)));
    }

    public function dataGetRequired()
    {
        $package = new Package('vendor/name', '1.0.0.0', '1.0');
        $link = new Link('test', 'name');
        $devLink = new Link('devTest', 'name');
        $package->setRequires(array($link));
        $package->setDevRequires(array($devLink));

        $data = array();

        $data['both require false'] = array(
          array(),
          $package,
          false,
          false,
        );

        $data['require true'] = array(
          array($link),
          $package,
          true,
          false,
        );

        $data['requireDev true'] = array(
          array($devLink),
          $package,
          false,
          true,
        );

        $data['both require true'] = array(
          array($link, $devLink),
          $package,
          true,
          true,
        );

        return $data;
    }

    /**
     * @dataProvider dataGetRequired
     */
    public function testGetRequired($expected, $package, $requireDependencies, $requireDevDependencies)
    {
        $builder = new PackageSelection(new NullOutput(), 'build', array(), false);

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('getRequired');
        $method->setAccessible(true);

        $property = $reflection->getProperty('requireDependencies');
        $property->setAccessible(true);
        $property->setValue($builder, $requireDependencies);

        $property = $reflection->getProperty('requireDevDependencies');
        $property->setAccessible(true);
        $property->setValue($builder, $requireDevDependencies);

        $this->assertSame($expected, $method->invokeArgs($builder, array($package)));
    }

    public function dataSetSelectedAsAbandoned()
    {
        $package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned1 = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned1->setAbandoned(true);
        $packageAbandoned2 = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned2->setAbandoned('othervendor/othername');

        $data = array();

        $data['Nothing Abandonned'] = array(
            array($package->getUniqueName() => $package),
            array(),
        );

        $data['Package Abandonned without Replacement'] = array(
            array($package->getUniqueName() => $packageAbandoned1),
            array('vendor/name' => true),
        );

        $data['Package Abandonned with Replacement'] = array(
            array($package->getUniqueName() => $packageAbandoned2),
            array('vendor/name' => 'othervendor/othername'),
        );

        return $data;
    }

    /**
     * @dataProvider dataSetSelectedAsAbandoned
     */
    public function testSetSelectedAsAbandoned($expected, $config)
    {
        $package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');

        $builder = new PackageSelection(new NullOutput(), 'build', array(
            'abandoned' => $config,
        ), false);

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('setSelectedAsAbandoned');
        $method->setAccessible(true);

        $property = $reflection->getProperty('selected');
        $property->setAccessible(true);
        $property->setValue($builder, array($package->getUniqueName() => $package));

        $method->invokeArgs($builder, array());

        $this->assertEquals($expected, $property->getValue($builder));
    }
}
