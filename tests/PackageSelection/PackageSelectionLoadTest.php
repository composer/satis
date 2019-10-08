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

use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Satis\Builder\PackagesBuilder;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackageSelectionLoadTest extends TestCase
{
    /** @var PackageSelection */
    protected $selection;

    /** @var Package */
    protected $package;

    /** @var Package */
    protected $devPackage;

    /** @var vfsStreamDirectory */
    protected $root;

    protected function setUp(): void
    {
        static $extra = [
            'branch-alias' => [
                'dev-master' => '1.0-dev',
            ],
        ];

        $this->package = new Package('vendor/name', '1.0.0.0', '1.0');
        $this->package->setExtra($extra);

        $this->devPackage = new Package('vendor/name', '9999999-dev', 'dev-master');
        $this->devPackage->setExtra($extra);

        $this->root = $this->setFileSystem();

        $this->selection = new PackageSelection(
            new NullOutput(),
            vfsStream::url('build'),
            [
                'repositories' => [
                    ['type' => 'composer', 'url' => 'http://localhost:54715']
                ],
                'require' => ['vendor/name' => '*'],
            ],
            false
        );

        $this->selection->setPackagesFilter(['vendor/name']);
    }

    protected function setFileSystem(): vfsStreamDirectory
    {
        vfsStreamWrapper::register();
        $root = vfsStream::newDirectory('build');
        vfsStreamWrapper::setRoot($root);

        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
        ], false);
        $packagesBuilder->dump([$this->package, $this->devPackage]);

        return $root;
    }

    public function testNoJsonFile()
    {
        /*
         * no json filename means empty $packages
         */
        $this->root->removeChild('packages.json');
        $this->assertEmpty($this->selection->load());
    }

    public function testNoIncludeFile()
    {
        /*
         * include file not found means output + empty $packages
         */
        $this->root->removeChild('include');
        $this->assertEmpty($this->selection->load());
    }

    public function testNoPackagesFilter()
    {
        /*
         * no filterPackages means all $packages
         */
        $this->selection->setPackagesFilter([]);
        $this->assertNotEmpty($this->selection->load());
    }

    public function testPackageInFilter()
    {
        /*
         * json filename + filterPackages :
         *   package in json + in filter => not selected (because it'll replaced/updated)
         */
        $this->assertEmpty($this->selection->load());
    }

    public function testPackageNotInFilter()
    {
        /*
         * json filename + filterPackages :
         *   package in json + not in filter => selected (to be merged as is)
         */
        $this->selection->setPackagesFilter(['othervendor/othername']);
        $this->assertNotEmpty($this->selection->load());
    }

    public function testAliasNotSelected()
    {
        $this->selection->setPackagesFilter(['othervendor/othername']);
        $packages = $this->selection->load();
        $this->assertNotEmpty($packages);

        foreach ($packages as $package) {
            $this->assertNotInstanceOf(AliasPackage::class, $package);

            if ($package->isDev()) {
                $this->assertSame('dev-master', $package->getPrettyVersion());
            } else {
                $this->assertSame('1.0', $package->getPrettyVersion());
            }
        }
    }
}
