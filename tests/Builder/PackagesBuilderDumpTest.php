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
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilderDumpTest extends TestCase
{
    protected vfsStreamDirectory $package;

    protected vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('build');
    }

    /**
     * @return array<Package>|array<string, mixed>
     */
    protected static function createPackages(int $majorVersionNumber, bool $asArray = false): array
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

    public function testNominalCase(bool $providers = false): void
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

            /** @var vfsStreamFile $file */
            $file = $this->root->getChild('build/packages.json');
            $packagesJson = JsonFile::parseJson($file->getContent());
            self::assertArrayNotHasKey('notify-batch', $packagesJson);

            if ($providers) {
                $packageName = key($arrayPackages);
                $arrayPackages[$packageName][$i . '.0']['uid'] = 1;
                $hash = current($packagesJson['providers'][$packageName]);
                $includeJson = str_replace(['%package%', '%hash%'], [$packageName, $hash], $packagesJson['providers-url']);
            } else {
                $includes = array_keys($packagesJson['includes']);
                $includeJson = end($includes);
            }

            // Skip if there is no valid json file
            if (!is_string($includeJson)) {
                continue;
            }

            $includeJsonFile = 'build/' . $includeJson;
            self::assertTrue(is_file(vfsStream::url($includeJsonFile)));

            /** @var vfsStreamFile $file */
            $file = $this->root->getChild($includeJsonFile);
            $packagesIncludeJson = JsonFile::parseJson($file->getContent());
            self::assertEquals($arrayPackages, $packagesIncludeJson['packages']);

            if (!is_null($lastIncludedJsonFile) && $lastIncludedJsonFile !== $includeJsonFile) {
                self::assertFalse(is_file(vfsStream::url($lastIncludedJsonFile)), 'Previous files not pruned');
            }

            $lastIncludedJsonFile = $includeJsonFile;

            self::assertArrayHasKey('metadata-url', $packagesJson);
            $packageName = key($arrayPackages);
            foreach (['', '~dev'] as $suffix) {
                $includeJson = str_replace('%package%', $packageName.$suffix, $packagesJson['metadata-url']);
                $includeJsonFile = 'build/' . $includeJson;
                self::assertTrue(is_file(vfsStream::url($includeJsonFile)), $includeJsonFile.' file must be created');
            }
        }
    }

    public function testProviders(): void
    {
        $this->testNominalCase(true);
    }

    public function testProvidersUrl(): void
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
            /** @var vfsStreamFile $file */
            $file = $this->root->getChild('build/packages.json');
            $packagesJson = JsonFile::parseJson($file->getContent());
            if (is_null($basePath)) {
                $providersUrlWithoutBase = $packagesJson['providers-url'];
            } else {
                self::assertEquals($basePath . $providersUrlWithoutBase, $packagesJson['providers-url']);
            }
        }
    }

    public function testNotifyBatch(): void
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'notify-batch' => 'http://localhost:54715/notify',
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
        ], false);

        $packagesBuilder->dump(self::createPackages(1));

        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/packages.json');
        $packagesJson = JsonFile::parseJson($file->getContent());

        self::assertEquals('http://localhost:54715/notify', $packagesJson['notify-batch']);
    }

    /**
     * @return array<string, mixed>
     */
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
    public function testPrettyPrintOption(int $jsonOptions, bool $shouldPrettyPrint = true): void
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
        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/out.json');
        $content = $file->getContent();

        $jsonEncodedObject = json_encode($expected, $jsonOptions);
        self::assertIsString($jsonEncodedObject);
        self::assertEquals(trim($jsonEncodedObject), trim($content));
    }

    public function testComposer2MinifiedProvider(): void
    {
        $expected = [
            'packages' => [
                'vendor/name' => [
                    [
                        'name' => 'vendor/name',
                        'version' => '1.0',
                        'version_normalized' => '1.0.0.0',
                        'type' => 'library',
                    ],
                    [
                        'version' => '2.0',
                        'version_normalized' => '2.0.0.0',
                    ],
                ],
            ],
            'minified' => PackagesBuilder::MINIFY_ALGORITHM_V2,
        ];

        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
        ], false, true);
        $packagesBuilder->dump(array_merge(self::createPackages(1), self::createPackages(2)));
        /** @var vfsStreamFile $file */
        $file = $this->root->getChild('build/p2/vendor/name.json');
        $content = $file->getContent();

        self::assertEquals($expected, json_decode($content, true));
    }
}
