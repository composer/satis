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

namespace Composer\Satis\Console\Command;

use Composer\Console\Application;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;

#[TestDox('PurgeCommand')]
#[CoversClass(PurgeCommand::class)]
class PurgeCommandTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        vfsStream::setup('satis');
        $this->configPath = vfsStream::url('satis/satis.json');
    }

    // --- Helpers ---

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->addCommand(new PurgeCommand());

        return new CommandTester($app->find('purge'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeSatisConfig(array $config): void
    {
        file_put_contents($this->configPath, json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $packagesByName
     */
    private function writeOutputRepository(string $outputDir, array $packagesByName, string $includeFile = 'include/packages.json'): void
    {
        $includePath = $outputDir . '/' . $includeFile;
        if (!is_dir(\dirname($includePath))) {
            mkdir(\dirname($includePath), 0777, true);
        }
        file_put_contents($includePath, json_encode(['packages' => $packagesByName], JSON_THROW_ON_ERROR));
        file_put_contents($outputDir . '/packages.json', json_encode([
            'includes' => [
                $includeFile => ['sha256' => 'dummy'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function packageEntry(string $name, string $version, string $distUrl, bool $withDist = true): array
    {
        $normalized = $version . '.0';
        if (1 === preg_match('/^\d+\.\d+\.\d+/', $version)) {
            $normalized = $version;
        }

        $data = [
            'name' => $name,
            'version' => $version,
            'version_normalized' => $normalized,
            'type' => 'library',
        ];
        if ($withDist) {
            $data['dist'] = [
                'type' => 'tar',
                'url' => $distUrl,
            ];
        }

        return [
            $name => [
                $version => $data,
            ],
        ];
    }

    /**
     * @param array<int, mixed> $parameters
     */
    private function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object::class);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($object, $parameters);
    }

    // --- Data providers ---

    /**
     * Missing `archive` and `archive` without `directory` hit the same validation branch in PurgeCommand.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidArchiveConfig(): iterable
    {
        yield 'archive key missing' => [[
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
        ]];

        yield 'archive.directory missing' => [[
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'archive' => [],
        ]];
    }

    /**
     * Data providers run before setUp(), so vfs paths must be built inside the test.
     *
     * @return iterable<string, array{0: bool}>
     */
    public static function provideOutputDirResolution(): iterable
    {
        yield 'from CLI argument' => [true];
        yield 'from config when argument omitted' => [false];
    }

    // --- Validation ---

    #[TestDox('execute() returns error when config file is not found')]
    public function testExecuteReturnsErrorWhenFileNotFound(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'file' => vfsStream::url('satis/nonexistent.json'),
            'output-dir' => vfsStream::url('satis/output'),
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('File not found', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideInvalidArchiveConfig')]
    #[TestDox('execute() returns error when archive config is invalid')]
    public function testExecuteReturnsErrorWhenArchiveConfigInvalid(array $config): void
    {
        $this->writeSatisConfig($config);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => vfsStream::url('satis/output'),
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('You must define "archive" parameter', $tester->getDisplay());
    }

    #[TestDox('execute() throws when output dir is not in config or arguments')]
    public function testExecuteThrowsWhenOutputDirMissing(): void
    {
        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'archive' => [
                'directory' => 'dist',
            ],
        ]);

        $tester = $this->createTester();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The output dir must be specified as second argument or be configured inside');
        $tester->execute([
            'file' => $this->configPath,
        ]);
    }

    // --- Output directory resolution ---

    #[DataProvider('provideOutputDirResolution')]
    #[TestDox('execute() resolves output-dir from CLI or config')]
    public function testExecuteResolvesOutputDir(bool $outputDirFromCliArgument): void
    {
        $suffix = $outputDirFromCliArgument ? 'arg' : 'cfg';
        $outputDir = vfsStream::url('satis/output-' . $suffix);
        mkdir($outputDir);
        mkdir($outputDir . '/dist');

        $baseConfig = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ];

        if ($outputDirFromCliArgument) {
            $this->writeSatisConfig($baseConfig);
            $executeArgs = [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ];
        } else {
            $this->writeSatisConfig(array_merge($baseConfig, ['output-dir' => $outputDir]));
            $executeArgs = [
                'file' => $this->configPath,
            ];
        }

        $tester = $this->createTester();
        $tester->execute($executeArgs);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No archives found', $tester->getDisplay());
    }

    // --- Prefix / env / homepage ---

    #[TestDox('execute() warns when no unreferenced archives are found')]
    public function testExecuteWarnsWhenNoUnreferencedArchivesFound(): void
    {
        $outputDir = vfsStream::url('satis/output-all-ref');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir, 0755, true);
        mkdir($archiveDir . '/vendor', 0755, true);

        $relativePath = 'vendor/kept.tar.gz';
        file_put_contents($archiveDir . '/' . $relativePath, 'content');

        $distUrl = 'https://example.com/dist/' . $relativePath;
        $this->writeOutputRepository($outputDir, $this->packageEntry('vendor/kept', '1.0.0', $distUrl));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No unreferenced archives found', $tester->getDisplay());
        self::assertFileExists($archiveDir . '/' . $relativePath);
    }

    /**
     * Wrong prefix-url would mark the on-disk file as unreferenced and change the outcome (purge vs this message).
     */
    #[TestDox('execute() uses archive prefix-url when set')]
    public function testExecuteUsesArchivePrefixUrl(): void
    {
        $outputDir = vfsStream::url('satis/output-prefix');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/p', 0755, true);

        $relativePath = 'p/archive.tar.gz';
        file_put_contents($archiveDir . '/' . $relativePath, 'x');

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'p/pkg',
            '1.0.0',
            'https://cdn.example.com/dist/' . $relativePath
        ));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://cdn.example.com',
            ],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No unreferenced archives found', $tester->getDisplay());
    }

    #[TestDox('execute() uses SATIS_HOMEPAGE when prefix-url is not defined')]
    public function testExecuteUsesSatisHomepageEnv(): void
    {
        $outputDir = vfsStream::url('satis/output-env');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $relativePath = 'vendor/file.tar.gz';
        file_put_contents($archiveDir . '/' . $relativePath, 'c');

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/pkg',
            '1.0.0',
            'https://custom.example.com/dist/' . $relativePath
        ));

        $original = getenv('SATIS_HOMEPAGE');
        putenv('SATIS_HOMEPAGE=https://custom.example.com');

        try {
            $this->writeSatisConfig([
                'name' => 'test/satis-repo',
                'homepage' => 'https://example.com',
                'output-dir' => $outputDir,
                'archive' => [
                    'directory' => 'dist',
                ],
            ]);

            $tester = $this->createTester();
            $tester->execute([
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ]);

            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('No unreferenced archives found', $tester->getDisplay());
        } finally {
            if (false === $original) {
                putenv('SATIS_HOMEPAGE');
            } else {
                putenv('SATIS_HOMEPAGE=' . $original);
            }
        }
    }

    #[TestDox('execute() uses homepage from config when SATIS_HOMEPAGE is unset and prefix-url is missing')]
    public function testExecuteUsesHomepageFromConfig(): void
    {
        $outputDir = vfsStream::url('satis/output-home');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $relativePath = 'vendor/file.tar.gz';
        file_put_contents($archiveDir . '/' . $relativePath, 'c');

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/pkg',
            '1.0.0',
            'https://config.example.com/dist/' . $relativePath
        ));

        $original = getenv('SATIS_HOMEPAGE');
        putenv('SATIS_HOMEPAGE');

        try {
            $this->writeSatisConfig([
                'name' => 'test/satis-repo',
                'homepage' => 'https://config.example.com',
                'output-dir' => $outputDir,
                'archive' => [
                    'directory' => 'dist',
                ],
            ]);

            $tester = $this->createTester();
            $tester->execute([
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ]);

            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('No unreferenced archives found', $tester->getDisplay());
        } finally {
            if (false === $original) {
                putenv('SATIS_HOMEPAGE');
            } else {
                putenv('SATIS_HOMEPAGE=' . $original);
            }
        }
    }

    // --- Purge behavior ---

    #[TestDox('execute() removes unreferenced archives when dry-run is disabled')]
    public function testExecuteRemovesUnreferencedArchives(): void
    {
        $outputDir = vfsStream::url('satis/output-purge');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $referencedFile = $archiveDir . '/vendor/referenced.tar.gz';
        $unreferencedFile = $archiveDir . '/vendor/unreferenced.tar.gz';
        file_put_contents($referencedFile, 'a');
        file_put_contents($unreferencedFile, 'b');

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/ref',
            '1.0.0',
            'https://example.com/dist/vendor/referenced.tar.gz'
        ));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed archive', $tester->getDisplay());
        self::assertStringContainsString('unreferenced.tar.gz', $tester->getDisplay());
        self::assertFileDoesNotExist($unreferencedFile);
        self::assertFileExists($referencedFile);
    }

    #[TestDox('execute() does not count packages without dist type as needed')]
    public function testExecuteTreatsPackagesWithoutDistTypeAsNotNeeded(): void
    {
        $outputDir = vfsStream::url('satis/output-nodist');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $relativePath = 'vendor/file.tar.gz';
        file_put_contents($archiveDir . '/' . $relativePath, 'c');

        $this->writeOutputRepository($outputDir, [
            'vendor/nodist' => [
                '1.0.0' => [
                    'name' => 'vendor/nodist',
                    'version' => '1.0.0',
                    'version_normalized' => '1.0.0.0',
                    'type' => 'library',
                ],
            ],
        ]);

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed archive', $tester->getDisplay());
        self::assertFileDoesNotExist($archiveDir . '/' . $relativePath);
    }

    #[TestDox('execute() removes empty directories after purging archives')]
    public function testExecuteRemovesEmptyDirectoriesAfterPurge(): void
    {
        $outputDir = vfsStream::url('satis/output-rmdir');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        $subdir = $archiveDir . '/vendor/branch';
        mkdir($subdir, 0755, true);

        file_put_contents($subdir . '/orphan.tar.gz', 'x');

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/keep',
            '1.0.0',
            'https://example.com/dist/vendor/branch/kept.tar.gz'
        ));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed archive', $tester->getDisplay());
        self::assertStringContainsString('Removed empty directory', $tester->getDisplay());
    }

    #[TestDox('execute() skips unreferenced archives that are already gone when they are about to be removed')]
    public function testExecuteSkipsArchivesThatDisappearDuringPurge(): void
    {
        $outputDir = vfsStream::url('satis/output-vanished');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $archives = [
            $archiveDir . '/vendor/first.tar.gz',
            $archiveDir . '/vendor/second.tar.gz',
        ];
        foreach ($archives as $archive) {
            file_put_contents($archive, 'x');
        }

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/other',
            '1.0.0',
            'https://example.com/dist/vendor/other.tar.gz'
        ));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        // Simulates a concurrent build or purge: once the first archive is removed, the others vanish too.
        $output = new class($archives) extends BufferedOutput {
            /**
             * @param list<string> $archives
             */
            public function __construct(private readonly array $archives)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                parent::doWrite($message, $newline);

                if (str_contains($message, 'Removed archive')) {
                    foreach ($this->archives as $archive) {
                        if (file_exists($archive)) {
                            unlink($archive);
                        }
                    }
                }
            }
        };

        $input = new ArrayInput([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);
        $input->setInteractive(false);

        $app = new Application();
        $app->addCommand(new PurgeCommand());

        $statusCode = $app->find('purge')->run($input, $output);

        $display = $output->fetch();
        self::assertSame(0, $statusCode);
        self::assertSame(1, substr_count($display, 'Removed archive'));
        self::assertStringContainsString('Skipped missing archive', $display);
        self::assertStringContainsString('Done.', $display);
        foreach ($archives as $archive) {
            self::assertFileDoesNotExist($archive);
        }
    }

    #[TestDox('execute() throws when an unreferenced archive exists but cannot be removed')]
    public function testExecuteThrowsWhenArchiveCannotBeRemoved(): void
    {
        $outputDir = vfsStream::url('satis/output-locked');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $unreferencedFile = $archiveDir . '/vendor/unreferenced.tar.gz';
        file_put_contents($unreferencedFile, 'x');
        chmod($archiveDir . '/vendor', 0555);

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/other',
            '1.0.0',
            'https://example.com/dist/vendor/other.tar.gz'
        ));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        $tester = $this->createTester();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not delete ' . $unreferencedFile);
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);
    }

    // --- Dry-run ---

    #[TestDox('execute() does not remove archives or empty dirs when dry-run is enabled')]
    public function testExecuteDoesNotRemoveArchivesInDryRun(): void
    {
        $outputDir = vfsStream::url('satis/output-dry');
        mkdir($outputDir);
        $archiveDir = $outputDir . '/dist';
        mkdir($archiveDir . '/vendor', 0755, true);

        $unreferencedFile = $archiveDir . '/vendor/unreferenced.tar.gz';
        file_put_contents($unreferencedFile, 'content');

        $this->writeOutputRepository($outputDir, $this->packageEntry(
            'vendor/other',
            '1.0.0',
            'https://example.com/dist/vendor/other.tar.gz'
        ));

        $this->writeSatisConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'output-dir' => $outputDir,
            'archive' => [
                'directory' => 'dist',
                'prefix-url' => 'https://example.com',
            ],
        ]);

        $tester = $this->createTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            'dry-run' => '1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($unreferencedFile);
        self::assertStringContainsString('Dry run enabled', $tester->getDisplay());
        self::assertStringContainsString('Removed archive', $tester->getDisplay());
        self::assertStringNotContainsString('Removed empty directory', $tester->getDisplay());
    }

    // --- removeEmptyDirectories() ---

    #[TestDox('removeEmptyDirectories() returns false for non-existent directory')]
    public function testRemoveEmptyDirectoriesReturnsFalseForNonExistentDirectory(): void
    {
        $command = new PurgeCommand();
        $result = $this->invokeMethod($command, 'removeEmptyDirectories', [
            new NullOutput(),
            vfsStream::url('satis/nonexistent'),
        ]);

        self::assertFalse($result);
    }

    #[TestDox('removeEmptyDirectories() does not remove the root directory passed in')]
    public function testRemoveEmptyDirectoriesLeavesRootDirectoryInPlace(): void
    {
        $root = vfsStream::url('satis/empty-root');
        mkdir($root);

        $command = new PurgeCommand();
        $result = $this->invokeMethod($command, 'removeEmptyDirectories', [
            new NullOutput(),
            $root,
        ]);

        self::assertTrue($result);
        self::assertDirectoryExists($root);
    }

    #[TestDox('removeEmptyDirectories() removes nested empty child directories')]
    public function testRemoveEmptyDirectoriesRemovesNestedEmptyDirectories(): void
    {
        $base = vfsStream::url('satis/nested-empty');
        mkdir($base);
        mkdir($base . '/a');
        mkdir($base . '/a/b');

        $out = new BufferedOutput();
        $command = new PurgeCommand();
        $this->invokeMethod($command, 'removeEmptyDirectories', [$out, $base, 2]);

        self::assertDirectoryDoesNotExist($base . '/a/b');
        self::assertDirectoryDoesNotExist($base . '/a');
        self::assertStringContainsString('Removed empty directory', $out->fetch());
    }

    #[TestDox('removeEmptyDirectories() does not remove non-empty directories')]
    public function testRemoveEmptyDirectoriesDoesNotRemoveNonEmptyDirectory(): void
    {
        $base = vfsStream::url('satis/non-empty');
        mkdir($base);
        $dir = $base . '/keep';
        mkdir($dir);
        file_put_contents($dir . '/file.txt', 'content');

        $command = new PurgeCommand();
        $result = $this->invokeMethod($command, 'removeEmptyDirectories', [
            new NullOutput(),
            $dir,
        ]);

        self::assertFalse($result);
        self::assertDirectoryExists($dir);
    }
}
