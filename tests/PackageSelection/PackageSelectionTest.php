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
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\PackageRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackageSelectionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public function dataGetPackages(): array
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
     * @param string[] $expected
     * @param string[] $filter
     */
    public function testGetPackages(array $expected, array $filter, ArrayRepository $repository): void
    {
        $builder = new PackageSelection(new NullOutput(), 'build', [], false);
        if (count($filter) > 0) {
            $builder->setPackagesFilter($filter);
        }

        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('getPackages');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invokeArgs($builder, [$repository]));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataGetRequired(): array
    {
        $package = new Package('vendor/name', '1.0.0.0', '1.0');
        $link = new Link('test', 'name', new MatchAllConstraint());
        $devLink = new Link('devTest', 'name', new MatchAllConstraint());
        $package->setRequires([$link->getTarget() => $link]);
        $package->setDevRequires([$devLink->getTarget() => $devLink]);

        $data = [];

        $data['both require false'] = [
          [],
          $package,
          false,
          false,
        ];

        $data['require true'] = [
          [$link->getTarget() => $link],
          $package,
          true,
          false,
        ];

        $data['requireDev true'] = [
          [$devLink->getTarget() => $devLink],
          $package,
          false,
          true,
        ];

        $data['both require true'] = [
          [$link->getTarget() => $link, $devLink->getTarget() => $devLink],
          $package,
          true,
          true,
        ];

        return $data;
    }

    /**
     * @dataProvider dataGetRequired
     *
     * @param string[] $expected
     */
    public function testGetRequired(array $expected, Package $package, bool $requireDependencies, bool $requireDevDependencies): void
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

        self::assertSame($expected, $method->invokeArgs($builder, [$package, true]));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataSetSelectedAsAbandoned(): array
    {
        $package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned1 = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned1->setAbandoned(true);
        $packageAbandoned2 = new CompletePackage('vendor/name', '1.0.0.0', '1.0');
        $packageAbandoned2->setAbandoned('othervendor/othername');

        $data = [];

        $data['Nothing Abandoned'] = [
            [$package->getUniqueName() => $package],
            [],
        ];

        $data['Package Abandoned without Replacement'] = [
            [$package->getUniqueName() => $packageAbandoned1],
            ['vendor/name' => true],
        ];

        $data['Package Abandoned with Replacement'] = [
            [$package->getUniqueName() => $packageAbandoned2],
            ['vendor/name' => 'othervendor/othername'],
        ];

        return $data;
    }

    /**
     * @dataProvider dataSetSelectedAsAbandoned
     *
     * @param string[] $expected
     * @param array<string, mixed> $config
     */
    public function testSetSelectedAsAbandoned(array $expected, array $config): void
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

        self::assertEquals($expected, $property->getValue($builder));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataPruneBlacklisted(): array
    {
        $package0 = new Package('vendor/name', '1.0.0.0', '1.0');
        $package1 = new Package('vendor/name', '1.1.0.0', '1.1');
        $package2 = new Package('vendor/name', '1.2.0.0', '1.2');
        $packageOther = new Package('vendor/other', '1.0.0.0', '1.0');

        $data = [];
        $selected = [$package0, $package1, $package2, $packageOther];

        $data['Blacklist Whole Project'] = [
            [$packageOther],
            $selected,
            ['vendor/name' => '*'],
        ];

        $data['Blacklist One Package'] = [
            [$package0, $package2, $packageOther],
            $selected,
            ['vendor/name' => '1.1'],
        ];

        $data['Blacklist Newer Packages'] = [
            [$package0, $package1, $packageOther],
            $selected,
            ['vendor/name' => '>1.1'],
        ];

        return $data;
    }

    /**
     * @dataProvider dataPruneBlacklisted
     *
     * @param string[] $expected
     * @param string[] $selected
     * @param array<string, mixed> $config
     */
    public function testPruneBlacklisted(array $expected, array $selected, array $config): void
    {
        $repositorySet = new RepositorySet();
        $builder = new PackageSelection(new NullOutput(), 'build', [
            'blacklist' => $config,
        ], false);
        $reflection = new \ReflectionClass(get_class($builder));

        $property = $reflection->getProperty('selected');
        $property->setAccessible(true);
        $property->setValue($builder, $selected);

        $method = $reflection->getMethod('pruneBlacklisted');
        $method->setAccessible(true);
        $method->invokeArgs($builder, [$repositorySet, false]);

        self::assertEquals(array_values($expected), array_values($property->getValue($builder)));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataSelect(): array
    {
        $packages = [
            'alpha' => [
                'name' => 'vendor/project-alpha',
                'version' => '1.2.3.0',
            ],
            'alpha-dev' => [
                'name' => 'vendor/project-alpha',
                'version' => '1.2.3.1-dev',
            ],
            'beta' => [
                'name' => 'vendor/project-beta',
                'version' => '1.2.3.0',
                'type' => 'library',
            ],
            'beta-dev' => [
                'name' => 'vendor/project-beta',
                'version' => '1.2.3.1-dev',
                'type' => 'library',
            ],
            'gamma1' => [
                'name' => 'vendor/project-gamma',
                'version' => '1.2.3.0',
                'type' => 'project',
            ],
            'gamma2' => [
                'name' => 'vendor/project-gamma',
                'version' => '2.3.4.0',
                'type' => 'project',
            ],
            'gamma3' => [
                'name' => 'vendor/project-gamma',
                'version' => '3.4.5.0',
                'type' => 'library',
            ],
            'gamma4' => [
                'name' => 'vendor/project-gamma',
                'version' => '4.5.6.0',
                'type' => 'library',
            ],
            'delta' => [
                'name' => 'vendor/project-delta',
                'version' => '1.2.3.0',
                'type' => 'project',
                'require' => [
                    'vendor/project-alpha' => '^1',
                    'vendor/project-gamma' => '^1',
                ],
            ],
            'epsilon' => [
                'name' => 'vendor/project-epsilon',
                'version' => '1.2.3.0',
                'type' => 'custom-type',
                'require' => [
                    'vendor/project-alpha' => '^1',
                ],
                'require-dev' => [
                    'vendor/project-gamma' => '^4',
                ],
            ],
            'zeta' => [
                'name' => 'vendor/project-zeta',
                'version' => '1.2.3.0',
                'type' => 'another-custom-type',
                'require' => [
                    'vendor/project-epsilon' => '^1',
                ],
            ],
            'eta' => [
                'name' => 'vendor/project-eta',
                'version' => '1.2.3.0',
                'type' => 'another-custom-type',
                'require' => [
                    'vendor/project-gamma' => '>=1',
                ],
            ],
        ];

        $repo = [];
        $repo['alpha'] = [
            'type' => 'package',
            'url' => 'example.org/project-alpha',
            'package' => [
                $packages['alpha'],
                $packages['alpha-dev'],
            ],
        ];
        $repo['beta'] = [
            'type' => 'package',
            'url' => 'example.org/project-beta',
            'package' => [
                $packages['beta'],
                $packages['beta-dev'],
            ],
        ];
        $repo['gamma'] = [
            'type' => 'package',
            'url' => 'example.org/project-gamma',
            'package' => [
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
        ];
        $repo['delta'] = [
            'type' => 'package',
            'url' => 'example.org/project-delta',
            'package' => $packages['delta'],
        ];
        $repo['epsilon'] = [
            'type' => 'package',
            'url' => 'example.org/project-epsilon',
            'package' => [
                $packages['epsilon'],
            ],
        ];
        $repo['zeta'] = [
            'type' => 'package',
            'url' => 'example.org/project-zeta',
            'package' => [
                $packages['zeta'],
            ],
        ];
        $repo['eta'] = [
            'type' => 'package',
            'url' => 'example.org/project-eta',
            'package' => [
                $packages['eta'],
            ],
        ];

        foreach ($packages as &$p) {
            $p = $p['name'] . '-' . $p['version'];
        }

        $data = [];

        $data['Require-all'] = [
            $packages,
            [
                'repositories' => array_values($repo),
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
                'minimum-stability' => 'stable',
                'repositories' => array_values($repo),
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
                'minimum-stability' => 'stable',
                'repositories' => array_values($repo),
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
                'minimum-stability' => 'stable',
                'repositories' => array_values($repo),
                'require' => [
                    'vendor/project-epsilon' => '>=1',
                ],
                'require-dependencies' => true,
                'require-dev-dependencies' => true,
            ],
        ];

        $data['Require only dependencies'] = [
            [
                $packages['alpha'],
                $packages['epsilon'],
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
            [
                'minimum-stability' => 'stable',
                'repositories' => array_values($repo),
                'require' => [
                    'vendor/project-epsilon' => '*',
                    'vendor/project-zeta' => '*',
                    'vendor/project-eta' => '*',
                ],
                'require-dependencies' => true,
                'require-dev-dependencies' => true,
                'only-dependencies' => true,
            ],
        ];

        $data['Traverse dependencies but not dev-dependencies'] = [
            [
                $packages['zeta'],
                $packages['epsilon'],
                $packages['alpha'],
            ],
            [
                'minimum-stability' => 'stable',
                'repositories' => array_values($repo),
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
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
            [
                'minimum-stability' => 'stable',
                'repositories' => array_values($repo),
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
                'minimum-stability' => 'stable',
                'repositories' => [
                    $repo['gamma'],
                    $repo['delta'],
                ],
                'repositories-dep' => array_values($repo),
                'require-dependencies' => true,
            ],
        ];

        $data['Set minimum-stability for a specific package'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['beta'],
            ],
            [
                'minimum-stability' => 'stable',
                'minimum-stability-per-package' => [
                    'vendor/project-alpha' => 'dev',
                ],
                'repositories' => array_values($repo),
                'require' => [
                    'vendor/project-alpha' => '*',
                    'vendor/project-beta' => '*',
                ],
            ],
        ];

        $data['Filter just libraries'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['beta'],
                $packages['beta-dev'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
            [
                'include-types' => [
                    'library',
                ],
                'repositories' => array_values($repo),
            ],
        ];

        $data['Filter just libraries and projects'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['beta'],
                $packages['beta-dev'],
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
                $packages['delta'],
            ],
            [
                'include-types' => [
                    'library',
                    'project',
                ],
                'repositories' => array_values($repo),
            ],
        ];

        $data['Filter exclude projects'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['beta'],
                $packages['beta-dev'],
                $packages['gamma3'],
                $packages['gamma4'],
                $packages['epsilon'],
                $packages['zeta'],
                $packages['eta'],
            ],
            [
                'exclude-types' => [
                    'project',
                ],
                'repositories' => array_values($repo),
            ],
        ];

        $data['Filter exclude projects, custom'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['beta'],
                $packages['beta-dev'],
                $packages['gamma3'],
                $packages['gamma4'],
                $packages['zeta'],
                $packages['eta'],
            ],
            [
                'exclude-types' => [
                    'project',
                    'custom-type',
                ],
                'repositories' => array_values($repo),
            ],
        ];

        $data['Filter by one repository'] = [
            [
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
            [
                'repositories' => array_values($repo),
            ],
            ['example.org/project-gamma'],
        ];

        $data['Filter by two repositories'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['gamma1'],
                $packages['gamma2'],
                $packages['gamma3'],
                $packages['gamma4'],
            ],
            [
                'repositories' => array_values($repo),
            ],
            ['example.org/project-gamma', 'example.org/project-alpha'],
        ];

        $data['Filter by two repositories and one package name'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
            ],
            [
                'repositories' => array_values($repo),
            ],
            ['example.org/project-gamma', 'example.org/project-alpha'],
            ['vendor/project-alpha'],
        ];

        $data['Filter by one package name'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
            ],
            [
                'repositories' => array_values($repo),
            ],
            null,
            ['vendor/project-alpha'],
        ];

        $data['Filter by two package names'] = [
            [
                $packages['alpha'],
                $packages['alpha-dev'],
                $packages['eta'],
            ],
            [
                'repositories' => array_values($repo),
            ],
            null,
            ['vendor/project-alpha', 'vendor/project-eta'],
        ];

        return $data;
    }

    /**
     * @dataProvider dataSelect
     *
     * @param string[] $expected
     * @param array<string, mixed> $config
     * @param string[]|null $filterRepos
     * @param string[]|null $filterPackages
     */
    public function testSelect(array $expected, array $config, array $filterRepos = null, array $filterPackages = null): void
    {
        if (null !== $filterRepos || null !== $filterPackages) {
            // Need to be able to override the default package repository class to allow testing of the filter options.
            // basically the following needs to work...
            // $composer->getRepositoryManager()->setRepositoryClass('package', MockPackageSelectionPackageRepository::class);
            // Simplest solution is probably for the hash in RepositoryFactory::manager() to be moved in to a static property.

            self::markTestIncomplete('Test cannot be completed.');
        }

        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);

        $selection = new PackageSelection(new NullOutput(), 'build', $config, false);
        $selection->setRepositoriesFilter($filterRepos);
        $selection->setPackagesFilter([]);

        $selection->select($composer, true);

        $selectionRef = new \ReflectionClass(PackageSelection::class);
        $selected = $selectionRef->getProperty('selected');
        $selected->setAccessible(true);

        \sort($expected, \SORT_STRING);
        self::assertEquals($expected, \array_keys($selected->getValue($selection)));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataMetadataSupport(): array
    {
        $vendorRepo = new ArrayRepository();

        $vendorRepo->addPackage(new Package('vendor/name', '1.0.0.0', '1.0.0'));
        $vendorRepo->addPackage(new Package('vendor/name', '1.0.0.0', '1.0.0+metadata-0'));
        $vendorRepo->addPackage(new Package('vendor/name', '1.0.0.0', '1.0.0+metadata-1'));
        $vendorRepo->addPackage(new Package('vendor/name', '1.0.0.0', '1.0.0+malformed+metadata'));
        $vendorRepo->addPackage(new Package('vendor/name', '2.0.0.0', '2.0.0'));

        $data = [];

        $data['packages with metadata'] = [
            [
                'vendor/name-1.0.0.0',
                'vendor/name-1.0.0.0+metadata-0',
                'vendor/name-1.0.0.0+metadata-1',
                'vendor/name-2.0.0.0',
            ],
            $vendorRepo,
        ];

        return $data;
    }

    /**
     * @dataProvider dataMetadataSupport
     *
     * @param string[] $expected
     */
    public function testMetadataSupport(array $expected, ArrayRepository $repository): void
    {
        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        $composer = (new Factory())->createComposer(new NullIO(), [], true, null, false);
        $composer->getRepositoryManager()->addRepository($repository);
        $selection = new PackageSelection(new NullOutput(), 'build', [], false);

        $selectionRef = new \ReflectionClass(\get_class($selection));

        $select = $selectionRef->getMethod('select');
        $select->setAccessible(true);
        $result = $select->invokeArgs($selection, [$composer, true]);

        self::assertEquals($expected, array_keys($result));
    }

    /**
     * @return array<string, mixed>
     */
    public function dataClean(): array
    {
        $packages = [
            'alpha' => [
                'dist' => [
                    'type' => 'zip',
                    'url' => 'http://127.0.0.1/output/dist/alpha.zip',
                ],
                'source' => [
                    'type' => 'git',
                    'url' => './git-repo',
                ],
            ],
            'beta' => [
                'dist' => [
                    'type' => 'zip',
                    'url' => 'http://192.168.0.2/output/dist/beta.zip',
                ],
                'source' => [
                    'type' => 'git',
                    'url' => 'http://localhost/beta.git',
                ],
            ],
            'gamma' => [
                'dist' => [
                    'type' => 'zip',
                    'url' => 'http://192.168.0.1/output/dist/gamma.zip',
                ],
                'source' => [
                    'type' => 'git',
                    'url' => 'http://192.168.1.1/gamma.git',
                ],
            ],
            'delta' => [
                'dist' => [
                    'type' => 'zip',
                    'url' => 'http://example.org/output/dist/delta.zip',
                ],
                'source' => [
                    'type' => 'git',
                    'url' => 'http://source.example.org/delta.git',
                ],
            ],
            'epsilon' => [
                'dist' => [
                    'type' => 'zip',
                    'url' => 'http://[abcd::]/output/dist/epsilon.zip',
                ],
                'source' => [
                    'type' => 'git',
                    'url' => 'http://[::1]/epsilon.git',
                ],
            ],
        ];

        $data = [];
        $data['Keep everything'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', './git-repo'],
                'beta' => ['http://192.168.0.2/output/dist/beta.zip', 'http://localhost/beta.git'],
                'gamma' => ['http://192.168.0.1/output/dist/gamma.zip', 'http://192.168.1.1/gamma.git'],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', 'http://[::1]/epsilon.git'],
            ],
            [],
            $packages,
        ];

        $data['Remove local file URLs'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', null],
                'beta' => ['http://192.168.0.2/output/dist/beta.zip', 'http://localhost/beta.git'],
                'gamma' => ['http://192.168.0.1/output/dist/gamma.zip', 'http://192.168.1.1/gamma.git'],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', 'http://[::1]/epsilon.git'],
            ],
            [
                'strip-hosts' => true,
            ],
            $packages,
        ];

        $data['Remove local IPs'] = [
            [
                'beta' => ['http://192.168.0.2/output/dist/beta.zip', null],
                'gamma' => ['http://192.168.0.1/output/dist/gamma.zip', 'http://192.168.1.1/gamma.git'],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', null],
            ],
            [
                'strip-hosts' => ['/local'],
            ],
            $packages,
        ];

        $data['Remove private IPs'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', null],
                'beta' => [null, 'http://localhost/beta.git'],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', 'http://[::1]/epsilon.git'],
            ],
            [
                'strip-hosts' => ['/private'],
            ],
            $packages,
        ];

        $data['Remove IPv4 with CIDR notation'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', null],
                'beta' => [null, 'http://localhost/beta.git'],
                'gamma' => [null, 'http://192.168.1.1/gamma.git'],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', 'http://[::1]/epsilon.git'],
            ],
            [
                'strip-hosts' => ['192.168.0.0/24'],
            ],
            $packages,
        ];

        $data['Remove IPv6 address'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', null],
                'beta' => ['http://192.168.0.2/output/dist/beta.zip', 'http://localhost/beta.git'],
                'gamma' => ['http://192.168.0.1/output/dist/gamma.zip', 'http://192.168.1.1/gamma.git'],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => [null, 'http://[::1]/epsilon.git'],
            ],
            [
                'strip-hosts' => ['abcd::'],
            ],
            $packages,
        ];

        $data['Remove domain'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', null],
                'beta' => ['http://192.168.0.2/output/dist/beta.zip', 'http://localhost/beta.git'],
                'gamma' => ['http://192.168.0.1/output/dist/gamma.zip', 'http://192.168.1.1/gamma.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', 'http://[::1]/epsilon.git'],
            ],
            [
                'strip-hosts' => ['example.org'],
            ],
            $packages,
        ];

        $data['Preserve distURL from ArchiveBuilder'] = [
            [
                'alpha' => ['http://127.0.0.1/output/dist/alpha.zip', null],
                'beta' => [null, 'http://localhost/beta.git'],
                'gamma' => ['http://192.168.0.1/output/dist/gamma.zip', null],
                'delta' => ['http://example.org/output/dist/delta.zip', 'http://source.example.org/delta.git'],
                'epsilon' => ['http://[abcd::]/output/dist/epsilon.zip', 'http://[::1]/epsilon.git'],
            ],
            [
                'strip-hosts' => ['/private'],
                'archive' => [
                    'directory' => 'dist',
                    'prefix-url' => 'http://192.168.0.1',
                ],
            ],
            $packages,
        ];

        return $data;
    }

    /**
     * @dataProvider dataClean
     *
     * @param string[] $expected
     * @param array<string, mixed> $config
     * @param array<string, mixed> $packages
     */
    public function testClean(array $expected, array $config, array $packages): void
    {
        $selection = new PackageSelection(new NullOutput(), 'build', $config, false);
        $selectionRef = new \ReflectionClass(\get_class($selection));

        foreach ($packages as $i => $p) {
            $packages[$i] = new Package($i, '1.0.0.0', '1.0');
            $packages[$i]->setDistType($p['dist']['type']);
            $packages[$i]->setDistUrl($p['dist']['url']);
            $packages[$i]->setSourceType($p['source']['type']);
            $packages[$i]->setSourceUrl($p['source']['url']);
        }

        $selected = $selectionRef->getProperty('selected');
        $selected->setAccessible(true);
        $selected->setValue($selection, $packages);

        $clean = $selectionRef->getMethod('clean');
        $clean->setAccessible(true);

        $cleanPackages = $clean->invokeArgs($selection, []);
        $sources = [];
        foreach ($cleanPackages as $name => $package) {
            $sources[$name] = [
                (null !== $package->getDistType()) ? $package->getDistUrl() : null,
                (null !== $package->getSourceType()) ? $package->getSourceUrl() : null,
            ];
        }

        self::assertEquals($expected, $sources);
    }

    public function testOnlyBestCandidates(): void
    {
        $repository = new ArrayRepository();

        $packageA0 = new Package('vendor/a', '1.0.0.0', '1.0');
        $packageA1 = new Package('vendor/a', '1.1.0.0', '1.1');
        $packageB0 = new Package('vendor/b', '1.0.0.0', '1.0');
        $packageB1 = new Package('vendor/b', '1.1.0.0', '1.1');
        $packageB2 = new Package('vendor/b', '1.2.0.0', '1.2');
        $packageC0 = new Package('vendor/c', '1.0.0.0', '1.0');
        $packageC1 = new Package('vendor/c', '1.1.0.0', '1.1');

        $constraint1 = new Constraint('=', '1.1');
        $link1 = new Link('vendor/a', 'vendor/b', $constraint1, Link::TYPE_REQUIRE);
        $packageA0->setRequires([$link1->getTarget() => $link1]);

        $constraint2 = new Constraint('<=', '1.1');
        $link2 = new Link('vendor/b', 'vendor/c', $constraint2, Link::TYPE_REQUIRE);
        $packageB1->setRequires([$link2->getTarget() => $link2]);

        $repository->addPackage($packageA0);
        $repository->addPackage($packageA1);
        $repository->addPackage($packageB0);
        $repository->addPackage($packageB1);
        $repository->addPackage($packageB2);
        $repository->addPackage($packageC0);
        $repository->addPackage($packageC1);
        $repositorySet = new RepositorySet();
        $repositorySet->addRepository($repository);

        $rootConstraint = new Constraint('=', '1.0');
        $rootLink = new Link('top', 'vendor/a', $rootConstraint, Link::TYPE_REQUIRE);

        $config = [
          'only-best-candidates' => true,
          'require-dependencies' => true,
        ];
        $builder = new PackageSelection(new NullOutput(), 'build', $config, false);
        $reflection = new \ReflectionClass(get_class($builder));
        $method = $reflection->getMethod('selectLinks');
        $method->setAccessible(true);
        $method->invokeArgs($builder, [$repositorySet, [$rootLink], false, false]);

        $property = $reflection->getProperty('selected');
        $property->setAccessible(true);

        self::assertEquals(array_values([$packageA0, $packageB1, $packageC1]), array_values($property->getValue($builder)));
    }
}

final class MockPackageSelectionPackageRepository extends PackageRepository implements ConfigurableRepositoryInterface
{
    /** @var string */
    private $name;

    /** @var string */
    private $url;

    /*
     * Constructor.
     *
     * @param array{package: mixed[], ?url: string} $config package definition
     */
    public function __construct(array $config)
    {
        $this->name = $config['package']['name'] ?? $config['package'][0]['name'];
        // Composer\Repository\PackageRepository has a definition without the "url" key
        // phpstan does not pick the amended schema above
        $this->url = $config['url'] ?? '';

        parent::__construct($config);
    }

    /**
     * @return array<string, string>
     */
    public function getRepoConfig(): array
    {
        return ['name' => $this->name, 'url' => $this->url];
    }
}
