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
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderDumpTest extends \PHPUnit_Framework_TestCase
{
    /** @var RootPackage */
    protected $rootPackage;

    /** @var CompletePackage */
    protected $package;

    /** @var vfsStreamDirectory */
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
        $this->assertRegExp('/<h3 id="vendor\/name" class="package-title"><a href="#vendor\/name" class="anchor"><svg class="octicon-link" aria-hidden="true" height="16" version="1.1" viewBox="0 0 16 16" width="16"><path d="M4 9h1v1H4c-1.5 0-3-1.69-3-3.5S2.55 3 4 3h4c1.45 0 3 1.69 3 3.5 0 1.41-.91 2.72-2 3.25V8.59c.58-.45 1-1.27 1-2.09C10 5.22 8.98 4 8 4H4c-.98 0-2 1.22-2 2.5S3 9 4 9zm9-3h-1v1h1c1 0 2 1.22 2 2.5S13.98 12 13 12H9c-.98 0-2-1.22-2-2.5 0-.83.42-1.64 1-2.09V6.25c-1.09.53-2 1.84-2 3.25C6 11.31 7.55 13 9 13h4c1.45 0 3-1.69 3-3.5S14.5 6 13 6z"><\/path><\/svg><\/a>vendor\/name<\/h3>/', $html);
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
