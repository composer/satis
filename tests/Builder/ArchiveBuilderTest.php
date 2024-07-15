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

use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Downloader\DownloadManager;
use Composer\Json\JsonFile;
use Composer\Package\Archiver\ArchiveManager;
use Composer\Package\CompletePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Michael Lee <michael.lee@zerustech.com>
 */
class ArchiveBuilderTest extends TestCase
{
    protected Composer $composer;

    protected NullOutput $output;

    protected InputInterface $input;

    protected string $outputDir;

    /**
     * @var array<string, mixed>
     */
    protected array $satisConfig;

    protected string $root;

    protected string $home;

    protected string $target;

    public function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->root);
    }

    public function setUp(): void
    {
        $this->root = __DIR__ . '/vfs';
        $this->home = $this->root . '/home/ubuntu';
        $this->target = $this->home . '/satis.server/dist/monolog/monolog';

        $composerConfig = new Config(true, $this->home . '/satis.server');
        $composerConfig->merge([
            'cache-dir' => $this->home . '/.cache/composer',
            'data-dir' => $this->home . '/.local/share/composer',
            'home' => $this->home . '/.config/composer',
        ]);
        $composerConfig->setConfigSource(new JsonConfigSource(new JsonFile($this->home . '/.config/composer/config.json')));
        $composerConfig->setAuthConfigSource(new JsonConfigSource(new JsonFile($this->home . '/.config/composer/auth.json')));

        $downloadManager = $this->getMockBuilder(DownloadManager::class)->disableOriginalConstructor()->getMock();
        $downloadManager->method('download')->will(
            self::returnCallback(
                function ($package, $source) {
                    $filesystem = new Filesystem();
                    $filesystem->dumpFile(realpath($source) . '/README.md', '# The demo archive.');
                }
            )
        );

        $archiveManager = new class() extends ArchiveManager {
            public function __construct()
            {
            }

            public function archive(CompletePackageInterface $package, string $format, string $targetDir, string $fileName = null, bool $ignoreFilters = false): string
            {
                $target = $targetDir.'/'.$this->getPackageFilename($package).'.'.$format;
                touch($target);

                return $target;
            }
        };

        $this->composer = new Composer();
        $this->composer->setConfig($composerConfig);
        $this->composer->setDownloadManager($downloadManager);
        $this->composer->setArchiveManager($archiveManager);

        $this->input = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->disableOriginalConstructor()->getMock();
        $this->input->method('getOption')->with('stats')->willReturn(self::returnValue(false));

        $this->output = new NullOutput();

        $this->outputDir = $this->home . '/satis.server';

        $this->satisConfig = [
            'name' => 'monolog/monolog',
            'homepage' => 'https://github.com/Seldaek/monolog.git',
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://packagist.org'],
            ],
            'require' => [
                'monolog/monolog' => '1.13.0',
            ],
            'config' => [
                'secure-http' => false,
            ],
            'require-dependencies' => false,
            'require-dev-dependencies' => false,
            'archive' => [
                'directory' => 'dist',
                'format' => 'tar',
                'prefix-url' => 'http://satis.localhost:4680',
                'skip-dev' => false,
            ],
        ];
    }

    /**
     * @dataProvider getDataForTestDump
     *
     * @param array<string, mixed> $customConfig
     * @param array<PackageInterface> $packages
     */
    public function testDumpWithDownloadedArchives(array $customConfig, array $packages, string $expectedFileName): void
    {
        $this->initArchives();

        $config = array_merge_recursive($this->satisConfig, $customConfig);

        $builder = new ArchiveBuilder($this->output, $this->outputDir, $config, true);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);
        $builder->dump($packages);

        self::assertSame($expectedFileName, basename((string) $packages[0]->getDistUrl()));
    }

    /**
     * @return array<CompletePackage>
     */
    private function getPackages(): array
    {
        $package = new CompletePackage('monolog/monolog', '1.13.0.0', '1.13.0');
        $package->setId(9);
        $package->setType('library');
        $package->setSourceType('git');
        $package->setSourceUrl('https://github.com/Seldaek/monolog.git');
        $package->setSourceReference('c41c218e239b50446fd883acb1ecfd4b770caeae');
        $package->setDistType('zip');
        $package->setDistUrl('https://api.github.com/repos/Seldaek/monolog/zipball/');
        $package->setDistReference('c41c218e239b50446fd883acb1ecfd4b770caeae');
        $package->setReleaseDate(new \DateTime('2015-03-05T01:12:12+00:00'));
        $package->setExtra(
            [
                'branch-alias' => [
                    'dev-master' => '1.13.x-dev',
                ],
            ]
        );
        $package->setNotificationUrl('https://packagist.org/downloads/');

        return [$package];
    }

    /**
     * @return array<mixed>
     */
    public function getDataForTestDump(): array
    {
        return [
            [[], $this->getPackages(), 'monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar'],
            [['archive' => ['override-dist-type' => false]], $this->getPackages(), 'monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar'],
            [['archive' => ['override-dist-type' => true]], $this->getPackages(), 'monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-tar-d4a976.tar'],
        ];
    }

    /**
     * @dataProvider getDataForTestDump
     *
     * @param array<string, mixed> $customConfig
     * @param array<PackageInterface> $packages
     */
    public function testDumpWithoutDownloadedArchives(array $customConfig, array $packages, string $expectedFileName): void
    {
        $this->removeArchives();

        $config = array_merge_recursive($this->satisConfig, $customConfig);
        $builder = new ArchiveBuilder($this->output, $this->outputDir, $config, true);
        $builder->setInput($this->input);
        $builder->setComposer($this->composer);
        $builder->dump($packages);

        self::assertSame($expectedFileName, basename((string) $packages[0]->getDistUrl()));
    }

    private function initArchives(): void
    {
        $fs = new Filesystem();
        $fs->mkdir($this->target, 0777);
        $fs->dumpFile($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar', 'the package archive.');
        $fs->dumpFile($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-tar-d4a976.tar', 'the package archive.');
    }

    private function removeArchives(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-zip-d4a976.tar');
        $fs->remove($this->target . '/monolog-monolog-c41c218e239b50446fd883acb1ecfd4b770caeae-tar-d4a976.tar');
    }
}
