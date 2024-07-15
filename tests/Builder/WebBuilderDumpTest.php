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
use Composer\Semver\Constraint\MatchAllConstraint;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamFile;
use org\bovigo\vfs\vfsStreamWrapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilderDumpTest extends TestCase
{
    protected RootPackage $rootPackage;

    protected CompletePackage $package;

    protected vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->rootPackage = new RootPackage('dummy root package', '0', '0');

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

    public function testNominalCase(): void
    {
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump([$this->package]);

        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/index.html');
        $html = $file->getContent();

        self::assertMatchesRegularExpression('/<title>dummy root package<\/title>/', $html);
        self::assertMatchesRegularExpression('{<div id="[^"]+" class="card-header[^"]+">\s*<a href="#vendor/name" class="[^"]+">\s*<svg[^>]*>.+</svg>\s*vendor/name\s*</a>\s*</div>}si', $html);
        self::assertFalse((bool) preg_match('/<p class="abandoned">/', $html));
    }

    public function testRepositoryWithNoName(): void
    {
        $this->rootPackage = new RootPackage('__root__', '0', '0');
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump([$this->package]);

        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/index.html');
        $html = $file->getContent();

        self::assertMatchesRegularExpression('/<title>A<\/title>/', $html);
    }

    public function testDependencies(): void
    {
        $link = new Link('dummytest', 'vendor/name', new MatchAllConstraint());
        $this->package->setRequires([$link->getTarget() => $link]);
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $webBuilder->dump([$this->package]);

        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/index.html');
        $html = $file->getContent();

        self::assertMatchesRegularExpression('/<a href="#dummytest">dummytest<\/a>/', $html);
    }

    /**
     * @return array<string, mixed>
     */
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
     */
    public function testAbandoned($abandoned, string $expected): void
    {
        $webBuilder = new WebBuilder(new NullOutput(), vfsStream::url('build'), [], false);
        $webBuilder->setRootPackage($this->rootPackage);
        $this->package->setAbandoned($abandoned);
        $webBuilder->dump([$this->package]);

        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/index.html');
        $html = $file->getContent();

        self::assertMatchesRegularExpression('/Package is abandoned, you should avoid using it/', $html);
        self::assertMatchesRegularExpression($expected, $html);
    }
}
