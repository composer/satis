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

namespace Composer\Test\Satis;

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\RootPackage;
use Composer\Satis\Builder\WebBuilder;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderDumpTest extends TestCase
{
    /** @var RootPackage */
    protected $rootPackage;

    /** @var CompletePackage */
    protected $package;

    /** @var vfsStreamDirectory */
    protected $root;

    protected function setUp(): void
    {
        $this->rootPackage = new RootPackage('dummy root package', 0, 0);

        $this->package = new CompletePackage('vendor/name', '1.0.0.0', '1.0');

        $this->root = $this->setFileSystem();
    }

    protected function setFileSystem(): vfsStreamDirectory
    {
        vfsStreamWrapper::register();
        $root = vfsStream::newDirectory('build');
        vfsStreamWrapper::setRoot($root);

        return $root;
    }

    public function testNominalCase()
    {
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump([$this->package]);

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<title>dummy root package<\/title>/', $html);
        $this->assertRegExp('{<div id="[^"]+" class="card-header[^"]+">\s*<a href="#vendor/name" class="[^"]+">\s*<svg[^>]*>.+</svg>\s*vendor/name\s*</a>\s*</div>}si', $html);
        $this->assertFalse((bool) preg_match('/<p class="abandoned">/', $html));
    }

    public function testRepositoryWithNoName()
    {
        $this->rootPackage = new RootPackage('__root__', 0, 0);
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump([$this->package]);

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<title>A<\/title>/', $html);
    }

    public function testDependencies()
    {
        $link = new Link('dummytest', 'vendor/name');
        $this->package->setRequires([$link]);
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump([$this->package]);

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/<a href="#dummytest">dummytest<\/a>/', $html);
    }

    public function dataAbandoned(): array
    {
        $data = [];

        $data['Abandoned not replaced'] = [
            true,
            '/No replacement was suggested/',
        ];

        $data['Abandoned and replaced'] = [
            'othervendor/othername',
            '/Use othervendor\/othername instead/',
        ];

        return $data;
    }

    /**
     * @dataProvider dataAbandoned
     *
     * @param bool|string $abandoned
     * @param string $expected
     */
    public function testAbandoned($abandoned, string $expected)
    {
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $this->package->setAbandoned($abandoned);
        $webBuilder->dump([$this->package]);

        $html = $this->root->getChild('build/index.html')->getContent();

        $this->assertRegExp('/Package is abandoned, you should avoid using it/', $html);
        $this->assertRegExp($expected, $html);
    }
}
