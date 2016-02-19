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
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\PackageSelection\PackageSelection;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackageSelectionLoadTest extends \PHPUnit_Framework_TestCase
{
    protected $tselection;

    protected $package;

    protected $root;

    protected function setUp()
    {
        $this->package = new Package('vendor/name', '1.0.0.0', '1.0');

        $this->root = $this->setFileSystem();

        $this->selection = new PackageSelection(new NullOutput(), vfsStream::url('build'), array(
            'repositories' => array(array('type' => 'composer', 'url' => 'http://localhost:54715')),
            'require' => array('vendor/name' => '*'),
        ), false);
        $this->selection->setPackagesFilter(array('vendor/name'));
    }

    protected function setFileSystem()
    {
        vfsStreamWrapper::register();
        $root = vfsStream::newDirectory('build');
        vfsStreamWrapper::setRoot($root);

        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), array(
            'repositories' => array(array('type' => 'composer', 'url' => 'http://localhost:54715')),
            'require' => array('vendor/name' => '*'),
        ), false);
        $packagesBuilder->dump(array($this->package));

        return $root;
    }

    public function testNoJsonFile()
    {
        /**
         * no json filename means empty $packages
         */
        $this->root->removeChild('packages.json');
        $this->assertEmpty($this->selection->load());
    }

    public function testNoIncludeFile()
    {
        /**
         * include file not found means output + empty $packages
         */
        $this->root->removeChild('include');
        $this->assertEmpty($this->selection->load());
    }

    public function testNoPackagesFilter()
    {
        /**
         * no filterPackages means all $packages
         */
        $this->selection->setPackagesFilter(array());
        $this->assertNotEmpty($this->selection->load());
    }

    public function testPackageInFilter()
    {
        /**
         * json filename + filterPackages :
         *   package in json + in filter => not selected (because it'll replaced/updated)
         */
        $this->assertEmpty($this->selection->load());
    }

    public function testPackageNotInFilter()
    {
        /**
         * json filename + filterPackages :
         *   package in json + not in filter => selected (to be merged as is)
         */
        $this->selection->setPackagesFilter(array('othervendor/othername'));
        $this->assertNotEmpty($this->selection->load());
    }
}
