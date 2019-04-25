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

namespace Composer\Satis\Builder;

use Composer\Json\JsonFile;
use Composer\Package\Package;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilderDumpTest extends TestCase
{
    /** @var vfsStreamDirectory */
    protected $package;

    /**
     * @var \org\bovigo\vfs\vfsStreamDirectory
     */
    protected $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('build');
    }

    protected static function createPackages(int $majorVersionNumber, bool $asArray = false)
    {
        $version = $majorVersionNumber . '.0';
        $versionNormalized = $majorVersionNumber . '.0.0.0';
        if ($asArray) {
            return [
                'vendor/name' => [
                    $version => [
                        'name' => 'vendor/name',
                        'version' => $version,
                        'version_normalized' => $versionNormalized,
                        'type' => 'library',
                    ],
                ],
            ];
        }

        return [new Package('vendor/name', $versionNormalized, $version)];
    }

    public function testNominalCase(bool $providers = false)
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'providers' => $providers,
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
        ], false);
        $lastIncludedJsonFile = null;

        foreach ([1, 2, 2] as $i) {
            $packages = self::createPackages($i);
            $arrayPackages = self::createPackages($i, true);

            $packagesBuilder->dump($packages);

            $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());
            $this->assertArrayNotHasKey('notify-batch', $packagesJson);

            if ($providers) {
                $packageName = key($arrayPackages);
                $arrayPackages[$packageName][$i . '.0']['uid'] = 1;
                $hash = current($packagesJson['providers'][$packageName]);
                $includeJson = str_replace(['%package%', '%hash%'], [$packageName, $hash], $packagesJson['providers-url']);
            } else {
                $includes = array_keys($packagesJson['includes']);
                $includeJson = end($includes);
            }

            $includeJsonFile = 'build/' . $includeJson;
            $this->assertTrue(is_file(vfsStream::url($includeJsonFile)));

            $packagesIncludeJson = JsonFile::parseJson($this->root->getChild($includeJsonFile)->getContent());
            $this->assertEquals($arrayPackages, $packagesIncludeJson['packages']);

            if ($lastIncludedJsonFile && $lastIncludedJsonFile !== $includeJsonFile) {
                $this->assertFalse(is_file(vfsStream::url($lastIncludedJsonFile)), 'Previous files not pruned');
            }

            $lastIncludedJsonFile = $includeJsonFile;
        }
    }

    public function testProviders()
    {
        $this->testNominalCase(true);
    }

    public function testProvidersUrl()
    {
        $urlToBaseUrlMap = [
            null,
            'http://localhost:1234/' => '/',
            'http://localhost:1234' => '/',
            'http://localhost:1234/sub-dir' => '/sub-dir/',
            'http://localhost:1234/sub-dir/' => '/sub-dir/',
        ];
        $providersUrlWithoutBase = null;
        foreach ($urlToBaseUrlMap as $url => $basePath) {
            $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
                'providers' => true,
                'homepage' => $url,
                'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
                'require' => ['vendor/name' => '*'],
            ], false);
            $packagesBuilder->dump(self::createPackages(1));
            $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());
            if (!$basePath) {
                $providersUrlWithoutBase = $packagesJson['providers-url'];
            } else {
                $this->assertEquals($basePath . $providersUrlWithoutBase, $packagesJson['providers-url']);
            }
        }
    }

    public function testNotifyBatch()
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'notify-batch' => 'http://localhost:54715/notify',
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
        ], false);

        $packagesBuilder->dump(self::createPackages(1));

        $packagesJson = JsonFile::parseJson($this->root->getChild('build/packages.json')->getContent());

        $this->assertEquals('http://localhost:54715/notify', $packagesJson['notify-batch']);
    }

    public function prettyPrintProvider(): array
    {
        return [
            'test pretty print enabled' => [
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                true,
            ],
            'test pretty print disabled' => [
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                false,
            ],
        ];
    }

    /**
     * @dataProvider prettyPrintProvider
     */
    public function testPrettyPrintOption(int $jsonOptions, bool $shouldPrettyPrint = true)
    {
        $expected = [
            'packages' => [
                'vendor/name' => [
                    '1.0' => [
                        'name' => 'vendor/name',
                        'version' => '1.0',
                        'version_normalized' => '1.0.0.0',
                        'type' => 'library',
                    ],
                ],
            ],
        ];

        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
            'pretty-print' => $shouldPrettyPrint,
            'include-filename' => 'out.json',
        ], false);
        $packages = self::createPackages(1);
        $packagesBuilder->dump($packages);
        $content = $this->root->getChild('build/out.json')->getContent();

        self::assertEquals(trim(json_encode($expected, $jsonOptions)), trim($content));
    }
}
