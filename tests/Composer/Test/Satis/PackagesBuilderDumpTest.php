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

use Composer\Json\JsonFile;
use Composer\Package\Package;
use Composer\Satis\Builder\PackagesBuilder;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilderDumpTest extends \PHPUnit_Framework_TestCase
{
    protected $package;

    protected $root;

    protected function setUp()
    {
        $this->package = new Package('vendor/name', '1.0.0.0', '1.0');

        $this->root = vfsStream::setup('build');
    }

    public function testNominalCase()
    {
        $arrayPackage = array(
            "vendor/name" => array(
                "1.0" => array(
                    "name" => "vendor/name",
                    "version" => "1.0",
                    "version_normalized" => "1.0.0.0",
                    "type" => "library",
                ),
            ),
        );

        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), array(
            'repositories' => array(array('type' => 'composer', 'url' => 'http://localhost:54715')),
            'require' => array('vendor/name' => '*'),
        ), false);
        $packages = array(
            $this->package,
        );

        $packagesBuilder->dump($packages);

        $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());
        $tmpArray = array_keys($packagesJson['includes']);
        $includeJson = array_shift($tmpArray);
        $includeJsonFile = 'build/'.$includeJson;
        $this->assertTrue(is_file(vfsStream::url($includeJsonFile)));

        $packagesIncludeJson = JsonFile::parseJson($this->root->getChild($includeJsonFile)->getContent());
        $this->assertEquals($arrayPackage, $packagesIncludeJson['packages']);
        $this->assertArrayNotHasKey('notify-batch', $packagesJson);
    }

    public function testNotifyBatch()
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), array(
            'notify-batch' => 'http://localhost:54715/notify',
            'repositories' => array(array('type' => 'composer', 'url' => 'http://localhost:54715')),
            'require' => array('vendor/name' => '*'),
        ), false);
        $packages = array(
            $this->package,
        );

        $packagesBuilder->dump($packages);

        $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());

        $this->assertEquals('http://localhost:54715/notify', $packagesJson['notify-batch']);
    }
}
