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
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\Package;
use Composer\Semver\VersionParser;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author James Hautot <james@rezo.net>
 */
class PackagesBuilderDumpTest extends TestCase
{
    private const PUBLISHED_TIME = '2024-01-01T00:00:00+00:00';

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
                        'published-time' => self::PUBLISHED_TIME,
                    ],
                ],
            ];
        }

        $package = new Package('vendor/name', $versionNormalized, $version);
        $package->setPublishedDate(new \DateTimeImmutable(self::PUBLISHED_TIME));

        return [$package];
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
                $includeJson = str_replace(['%package%', '%hash%'], [(string) $packageName, (string) $hash], $packagesJson['providers-url']);
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
    public static function prettyPrintProvider(): array
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

    #[DataProvider('prettyPrintProvider')]
    public function testPrettyPrintOption(int $jsonOptions, bool $shouldPrettyPrint = true): void
    {
        $expected = [
            'packages' => [
                'vendor/name' => [
                    '1.0' => [
                        'name' => 'vendor/name',
                        'version' => '1.0',
                        'version_normalized' => '1.0.0.0',
                        'published-time' => self::PUBLISHED_TIME,
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
                        'published-time' => self::PUBLISHED_TIME,
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

    public function testNewVersionIsPublishedAtBuildTime(): void
    {
        $before = time();
        $this->dumpPackages([self::createPackage('1.0', 'a')]);

        $publishedTime = $this->getPublishedTimes()['1.0'];
        self::assertIsString($publishedTime);
        self::assertGreaterThanOrEqual($before, strtotime($publishedTime));
        self::assertLessThanOrEqual(time(), strtotime($publishedTime));
    }

    public function testStableVersionKeepsFirstPublishedTime(): void
    {
        $this->dumpPackages([self::createPackage('1.0', 'a', self::PUBLISHED_TIME)]);
        $this->dumpPackages([self::createPackage('1.0', 'a')]);
        self::assertSame(self::PUBLISHED_TIME, $this->getPublishedTimes()['1.0']);

        // a moved tag keeps its date, as on Packagist
        $this->dumpPackages([self::createPackage('1.0', 'b')]);
        self::assertSame(self::PUBLISHED_TIME, $this->getPublishedTimes()['1.0']);
    }

    public function testVersionIsMatchedByNormalizedVersion(): void
    {
        $this->dumpPackages([self::createPackage('v1.0', 'a', self::PUBLISHED_TIME)]);
        $this->dumpPackages([self::createPackage('1.0', 'a')]);

        self::assertSame(self::PUBLISHED_TIME, $this->getPublishedTimes()['1.0']);
    }

    public function testUnreadablePreviousBuildPublishesVersionsAnew(): void
    {
        $this->dumpPackages([self::createPackage('1.0', 'a', self::PUBLISHED_TIME)]);
        file_put_contents(vfsStream::url('build/p2/vendor/name.json'), '{"packages":');

        $before = time();
        $this->dumpPackages([self::createPackage('1.0', 'a')]);

        self::assertGreaterThanOrEqual($before, strtotime($this->getPublishedTimes()['1.0'] ?? ''));
    }

    public function testDevVersionIsRepublishedWhenItsReferenceChanges(): void
    {
        $this->dumpPackages([self::createPackage('dev-main', 'a', self::PUBLISHED_TIME)]);
        $this->dumpPackages([self::createPackage('dev-main', 'a')]);
        self::assertSame(self::PUBLISHED_TIME, $this->getPublishedTimes('~dev')['dev-main']);

        $before = time();
        $this->dumpPackages([self::createPackage('dev-main', 'b')]);
        self::assertGreaterThanOrEqual($before, strtotime($this->getPublishedTimes('~dev')['dev-main'] ?? ''));
    }

    public function testPublishedTimeFromRepositoryIsKept(): void
    {
        $this->dumpPackages([self::createPackage('1.0', 'a', self::PUBLISHED_TIME)]);
        $this->dumpPackages([self::createPackage('1.0', 'a', '2025-01-01T00:00:00+00:00')]);

        self::assertSame('2025-01-01T00:00:00+00:00', $this->getPublishedTimes()['1.0']);
    }

    public function testVersionOfBuildWithoutPublishedTimeIsPublishedAtBuildTime(): void
    {
        $this->dumpPackages([self::createPackage('1.0', 'a')]);
        $file = vfsStream::url('build/p2/vendor/name.json');
        $data = JsonFile::parseJson((string) file_get_contents($file));
        unset($data['packages']['vendor/name'][0]['published-time']);
        file_put_contents($file, json_encode($data));

        $before = time();
        $this->dumpPackages([self::createPackage('1.0', 'a')]);

        self::assertGreaterThanOrEqual($before, strtotime($this->getPublishedTimes()['1.0'] ?? ''));
    }

    public function testPublishedTimeIsKeptFromMinifiedBuild(): void
    {
        $this->dumpPackages([self::createPackage('1.0', 'a', self::PUBLISHED_TIME), self::createPackage('2.0', 'b', self::PUBLISHED_TIME)], true);
        $this->dumpPackages([self::createPackage('1.0', 'a'), self::createPackage('2.0', 'b')], true);

        self::assertSame(['1.0' => self::PUBLISHED_TIME, '2.0' => self::PUBLISHED_TIME], $this->getPublishedTimes());
    }

    private static function createPackage(string $version, string $reference, ?string $publishedTime = null): Package
    {
        $package = new Package('vendor/name', (new VersionParser())->normalize($version), $version);
        $package->setSourceType('git');
        $package->setSourceUrl('https://example.org/vendor/name.git');
        $package->setSourceReference($reference);
        if (null !== $publishedTime) {
            $package->setPublishedDate(new \DateTimeImmutable($publishedTime));
        }

        return $package;
    }

    /**
     * @param list<Package> $packages
     */
    private function dumpPackages(array $packages, bool $minify = false): void
    {
        $packagesBuilder = new PackagesBuilder(new NullOutput(), vfsStream::url('build'), [
            'repositories' => [['type' => 'composer', 'url' => 'http://localhost:54715']],
            'require' => ['vendor/name' => '*'],
        ], false, $minify);
        $packagesBuilder->dump($packages);
    }

    /**
     * @return array<string, string|null> The published-time of each version in the p2 file, by version
     */
    private function getPublishedTimes(string $suffix = ''): array
    {
        $data = JsonFile::parseJson((string) file_get_contents(vfsStream::url('build/p2/vendor/name' . $suffix . '.json')));
        $versions = $data['packages']['vendor/name'];
        if (isset($data['minified'])) {
            $versions = MetadataMinifier::expand($versions);
        }

        return array_column($versions, 'published-time', 'version');
    }
}
