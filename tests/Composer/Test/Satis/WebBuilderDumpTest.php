<?php

/*
 * This file is part of composer/statis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Test\Satis;

use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Satis\Builder\WebBuilder;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderDumpTest extends \PHPUnit_Framework_TestCase
{
    protected $rootPackage;

    protected function setUp()
    {
        $this->rootPackage = new RootPackage("dummy root package", 0, 0);
    }

    public function testNominalCase()
    {
        vfsStreamWrapper::register();
        $root = vfsStream::newDirectory('build');
        vfsStreamWrapper::setRoot($root);
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), array(), false);
        $webBuilder->setRootPackage($this->rootPackage);
        $packages = array(
            new Package('vendor/name', '1.0.0.0', '1.0'),
        );

        $webBuilder->dump($packages);

        $html = $root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<h3 id="vendor\/name">vendor\/name<\/h3>/', $html);
    }
}
