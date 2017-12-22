<?php

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\PackageSelection;

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackageSelectionTest extends TestCase
{
    /**
     * @return array
     */
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

        $data = [];

        $data['empty repository'] = [
            [],
            [],
            $emptyRepo,
        ];

        $data['empty repository with filter'] = [
            [],
            ['vendor/name'],
            $emptyRepo,
        ];

        $data['repository with one package'] = [
            [$package],
            [],
            $vendorRepo,
        ];

        $data['repository with one package and filter'] = [
            [],
            ['othervendor/othername'],
            $vendorRepo,
        ];

        $data['repository with two packages'] = [
            [$package2, $package3],
            [],
            $vendorRepo2,
        ];

        $data['repository with two packages and filter'] = [
            [$package2],
            ['vendor2/name'],
            $vendorRepo2,
        ];

        return $data;
    }

    /**
     * @dataProvider dataGetPackages
     *
     * @param array $expected
     * @param array $filter
     * @param ArrayRepository $repository
     */
    public function testGetPackages($expected, $filter, $repository)
    {
        $builder = new PackageSelection(new NullOutput(), 'build', [], false);
        if (!empty($filter)) {
            $builder->setPackagesFilter($filter);
        }

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('getPackages');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invokeArgs($builder, [$repository]));
    }

    /**
     * @return array
     */
    public function dataGetRequired()
    {
        $package = new Package('vendor/name', '1.0.0.0', '1.0');
        $link = new Link('test', 'name');
        $devLink = new Link('devTest', 'name');
        $package->setRequires([$link]);
        $package->setDevRequires([$devLink]);

        $data = [];

        $data['both require false'] = [
          [],
          $package,
          false,
          false,
        ];

        $data['require true'] = [
          [$link],
          $package,
          true,
          false,
        ];

        $data['requireDev true'] = [
          [$devLink],
          $package,
          false,
          true,
        ];

        $data['both require true'] = [
          [$link, $devLink],
          $package,
          true,
          true,
        ];

        return $data;
    }

    /**
     * @dataProvider dataGetRequired
     *
     * @param array $expected
     * @param Package $package
     * @param bool $requireDependencies
     * @param bool $requireDevDependencies
     */
    public function testGetRequired($expected, $package, $requireDependencies, $requireDevDependencies)
    {
        $builder = new PackageSelection(new NullOutput(), 'build', [], false);

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('getRequired');
        $method->setAccessible(true);

        $property = $reflection->getProperty('requireDependencies');
        $property->setAccessible(true);
        $property->setValue($builder, $requireDependencies);

        $property = $reflection->getProperty('requireDevDependencies');
        $property->setAccessible(true);
        $property->setValue($builder, $requireDevDependencies);

        $this->assertSame($expected, $method->invokeArgs($builder, [$package]));
    }

    /**
     * @return array
     */
    public function dataSetSelectedAsAbandoned()
    {
        $package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned1 = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned1->setAbandoned(true);
        $packageAbandoned2 = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned2->setAbandoned('othervendor/othername');

        $data = [];

        $data['Nothing Abandonned'] = [
            [$package->getUniqueName() => $package],
            [],
        ];

        $data['Package Abandonned without Replacement'] = [
            [$package->getUniqueName() => $packageAbandoned1],
            ['vendor/name' => true],
        ];

        $data['Package Abandonned with Replacement'] = [
            [$package->getUniqueName() => $packageAbandoned2],
            ['vendor/name' => 'othervendor/othername'],
        ];

        return $data;
    }

    /**
     * @dataProvider dataSetSelectedAsAbandoned
     *
     * @param array $expected
     * @param array $config
     */
    public function testSetSelectedAsAbandoned($expected, $config)
    {
        $package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');

        $builder = new PackageSelection(new NullOutput(), 'build', [
            'abandoned' => $config,
        ], false);

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('setSelectedAsAbandoned');
        $method->setAccessible(true);

        $property = $reflection->getProperty('selected');
        $property->setAccessible(true);
        $property->setValue($builder, [$package->getUniqueName() => $package]);

        $method->invokeArgs($builder, []);

        $this->assertEquals($expected, $property->getValue($builder));
    }
}
