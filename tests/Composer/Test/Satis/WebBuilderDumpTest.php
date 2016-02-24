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

    protected $package;

    protected $root;

    protected function setUp()
    {
        $this->rootPackage = new RootPackage("dummy root package", 0, 0);

        $this->package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');

        $this->root = $this->setFileSystem();
    }

    protected function setFileSystem()
    {
        vfsStreamWrapper::register();
        $root = vfsStream::newDirectory('build');
        vfsStreamWrapper::setRoot($root);

        return $root;
    }

    public function testNominalCase()
    {
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), array(), false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump(array($this->package));

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<title>dummy root package Composer Repository<\/title>/', $html);
        $this->assertRegExp('/<h3 id="vendor\/name">vendor\/name<a href="#vendor\/name"><small>¶<\/small><\/a><\/h3>/', $html);
        $this->assertFalse((bool) preg_match('/<p class="abandoned">/', $html));
    }

    public function testRepositoryWithNoName()
    {
        $this->rootPackage = new RootPackage("__root__", 0, 0);
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), array(), false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump(array($this->package));

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<title>A Composer Repository<\/title>/', $html);
    }

    public function testDependencies()
    {
        $link = new Link('dummytest', 'vendor/name');
        $this->package->setRequires(array($link));
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), array(), false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump(array($this->package));

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<a href="#dummytest">dummytest<\/a>/', $html);
    }

    public function dataAbandoned()
    {
        $data = array();

        $data['Abandoned not replaced'] = array(
            true,
            '/No replacement was suggested/',
        );

        $data['Abandoned and replaced'] = array(
            'othervendor/othername',
            '/Use othervendor\/othername instead/',
        );

        return $data;
    }

    /**
     * @dataProvider dataAbandoned
     */
    public function testAbandoned($abandoned, $expected)
    {
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), array(), false);
        $webBuilder->setRootPackage($this->rootPackage);
        $this->package->setAbandoned($abandoned);
        $webBuilder->dump(array($this->package));

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/Package vendor\/name is abandoned, you should avoid using it/', $html);
        $this->assertRegExp($expected, $html);
    }
}
