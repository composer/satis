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

use Composer\Config;
use Composer\Factory;
use Composer\IO\NullIO;
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

        $this->assertSame($expected, $method->invokeArgs($builder, [$package, true]));
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

    /**
     * @return array
     */
    public function dataSelect()
    {
        $packages = [
            'alpha' => [
                'name' => 'vendor/project-alpha',
                'version' => '1.2.3.0',
            ],
            'beta' => [
                'name' => 'vendor/project-beta',
                'version' => '1.2.3.0',
            ],
            'gamma1' => [
                'name' => 'vendor/project-gamma',
                'version' => '1.2.3.0',
            ],
            'gamma2' => [
                'name' => 'vendor/project-gamma',
                'version' => '2.3.4.0',
            ],
            'gamma3' => [
                'name' => 'vendor/project-gamma',
                'version' => '3.4.5.0',
            ],
            'gamma4' => [
                'name' => 'vendor/project-gamma',
                'version' => '4.5.6.0',
            ],
            'delta' => [
                'name' => 'vendor/project-delta',
                'version' => '1.2.3.0',
                'require' => [
                    'vendor/project-alpha' =>  '^1',
                    'vendor/project-gamma' => '^1',
                ],
            ],
            'epsilon' => [
                'name' => 'vendor/project-epsilon',
                'version' => '1.2.3.0',
                'require' => [
                    'vendor/project-alpha' => '^1',
                ],
                'require-dev' => [
                    'vendor/project-gamma' => '^4',
                ]
            ],
            'zeta' => [
                'name' => 'vendor/project-zeta',
                'version' => '1.2.3.0',
                'require' => [
                    'vendor/project-epsilon' => '^1',
                ],
            ],
            'eta' => [
                'name' => 'vendor/project-eta',
                'version' => '1.2.3.0',
                'require' => [
                    'vendor/project-gamma' => '>=1',
                ],
            ],
        ];

        $repo['everything'] = [
            'type' => 'package',
            'url' => 'example.org/everything',
            'package' => \array_values($packages),
        ];
        $repo['gamma'] = [
            'type' => 'package',
            'url' => 'example.org/project-gamma',
            'package' => [
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ]
        ];
        $repo['delta'] = [
            'type' => 'package',
            'url' => 'example.org/project-delta',
            'package' => $packages['delta']
        ];

        foreach ($packages as &$p) {
            $p = $p['name'] . '-' . $p['version'];
        }

        $data = [];
        
        $data['Require-all'] = [
            $packages,
            [
                'repositories' => [
                    $repo['everything'],
                ],
            ],
        ];

        $data['Require'] = [
            [
                $packages['alpha'],
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
            [
                'repositories' => [
                    $repo['everything'],
                ],
                'require' => [
                    'vendor/project-alpha' => '>=1',
                    'vendor/project-gamma' => '>=1',
                ],
            ],
        ];

        $data['Require dependencies'] = [
            [
                $packages['delta'],
                $packages['alpha'],
                $packages['gamma1'],
            ],
            [
                'repositories' => [
                    $repo['everything'],
                ],
                'require' => [
                    'vendor/project-delta' => '>=1',
                ],
                'require-dependencies' => true,
            ],
        ];

        $data['Require dependencies and dev-dependencies'] = [
            [
                $packages['epsilon'],
                $packages['alpha'],
                $packages['gamma4'],
            ],
            [
                'repositories' => [
                    $repo['everything'],
                ],
                'require' => [
                    'vendor/project-epsilon' => '>=1',
                ],
                'require-dependencies' => true,
                'require-dev-dependencies' => true,
            ],
        ];

        $data['Traverse dependencies but not dev-dependencies'] = [
            [
                $packages['zeta'],
                $packages['epsilon'],
                $packages['alpha'],
            ],
            [
                'repositories' => [
                    $repo['everything'],
                ],
                'require' => [
                    'vendor/project-zeta' => '>=1',
                ],
                'require-dependencies' => true,
                'require-dev-dependencies' => true,
            ],
        ];

        $data['Reduce required dependencies'] = [
            [
                $packages['eta'],
                $packages['gamma1'],
                $packages['gamma4'],
            ],
            [
                'repositories' => [
                    $repo['everything'],
                ],
                'require' => [
                    'vendor/project-eta' => '>=1',
                ],
                'require-dependencies' => true,
            ],
        ];

        $data['Load from repositories-dep'] = [
            [
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
                $packages['delta'],
                $packages['alpha'],
            ],
            [
                'repositories' => [
                    $repo['gamma'],
                    $repo['delta'],
                ],
                'repositories-dep' => [
                    $repo['everything'],
                ],
                'require-dependencies' => true,
            ],
        ];

        return $data;
    }

    /**
     * @dataProvider dataSelect
     *
     * @param array $config
     * @param array $expected
     */
    public function testSelect($expected, $config, $filterRepo = null, $filterPackages = null)
    {
        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);

        $selection = new PackageSelection(new NullOutput(), 'build', $config, false);
        $selection->setRepositoryFilter($filterRepo);
        $selection->setPackagesFilter($filterPackages ?? []);

        $selectionRef = new \ReflectionClass(\get_class($selection));

        $select = $selectionRef->getMethod('select');
        $select->setAccessible(true);
        $select->invokeArgs($selection, [$composer, true]);

        $selected = $selectionRef->getProperty('selected');
        $selected->setAccessible(true);

        \sort($expected, \SORT_STRING);
        $this->assertEquals($expected, \array_keys($selected->getValue($selection)));
    }
}
