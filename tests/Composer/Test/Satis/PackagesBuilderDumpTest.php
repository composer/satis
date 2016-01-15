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
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilderDumpTest extends \PHPUnit_Framework_TestCase
{
    /** @var vfsStreamDirectory */
    protected $package;

    /**
     * @var \org\bovigo\vfs\vfsStreamDirectory
     */
    protected $root;

    protected function setUp()
    {
        $this->root = vfsStream::setup('build');
    }

    protected static function createPackages($majorVersionNumber, $asArray = false)
    {
        $version = $majorVersionNumber.'.0';
        $versionNormalized = $majorVersionNumber.'.0.0.0';
        if ($asArray) {
            return array(
                "vendor/name" => array(
                    $version => array(
                        "name" => "vendor/name",
                        "version" => $version,
                        "version_normalized" => $versionNormalized,
                        "type" => "library",
                    ),
                ),
            );
        }
        return array(new Package('vendor/name', $versionNormalized, $version));
    }

    /**
     * @param bool $providers
     */
    public function testNominalCase($providers = false)
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), array(
            'providers' => $providers,
            'repositories' => array(array('type' => 'composer', 'url' => 'http://localhost:54715')),
            'require' => array('vendor/name' => '*'),
        ), false);

        foreach (array(1, 2, 2) as $i) {
            $packages = self::createPackages($i);
            $arrayPackages = self::createPackages($i, true);

            $packagesBuilder->dump($packages);

            $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());
            $this->assertArrayNotHasKey('notify-batch', $packagesJson);

            if ($providers) {
                $packageName = key($arrayPackages);
                $hash = current($packagesJson['providers'][$packageName]);
                $includeJson = str_replace(array('%package%', '%hash%'), array($packageName, $hash), $packagesJson['providers-url']);
            } else {
                $includes = array_keys($packagesJson['includes']);
                $includeJson = end($includes);
            }

            $includeJsonFile = 'build/'.$includeJson;
            $this->assertTrue(is_file(vfsStream::url($includeJsonFile)));

            $packagesIncludeJson = JsonFile::parseJson($this->root->getChild($includeJsonFile)->getContent());
            $this->assertEquals($arrayPackages, $packagesIncludeJson['packages']);
        }
    }

    public function testProviders()
    {
        $this->testNominalCase(true);
    }

    public function testNotifyBatch()
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), array(
            'notify-batch' => 'http://localhost:54715/notify',
            'repositories' => array(array('type' => 'composer', 'url' => 'http://localhost:54715')),
            'require' => array('vendor/name' => '*'),
        ), false);

        $packagesBuilder->dump(self::createPackages(1));

        $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());

        $this->assertEquals('http://localhost:54715/notify', $packagesJson['notify-batch']);
    }
}
