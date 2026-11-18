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

use Composer\Composer;
use Composer\Config;
use Composer\Console\Application;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonValidationException;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Satis\Console\Application as SatisApplication;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

#[TestDox('BuildCommand')]
class BuildCommandTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private static array $savedDefaultRepositories;

    private string $configPath;

    public static function setUpBeforeClass(): void
    {
        self::$savedDefaultRepositories = Config::$defaultRepositories;
    }

    protected function setUp(): void
    {
        vfsStream::setup('satis');
        $this->configPath = vfsStream::url('satis/satis.json');

        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);
    }

    protected function tearDown(): void
    {
        Config::$defaultRepositories = self::$savedDefaultRepositories;
    }

    /**
     * @return array<string, mixed>
     */
    public static function dataRepositoryDisableDirectives(): array
    {
        $data = [];

        $data['packagist.org disable only'] = [
            0,
            [
                ['packagist.org' => false],
            ],
        ];

        $data['packagist.org disable with valid repos'] = [
            2,
            [
                ['packagist.org' => false],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/alpha',
                        'version' => '1.0.0',
                        'dist' => ['url' => 'https://example.com/alpha.zip', 'type' => 'zip'],
                    ],
                ],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/beta',
                        'version' => '2.0.0',
                        'dist' => ['url' => 'https://example.com/beta.zip', 'type' => 'zip'],
                    ],
                ],
            ],
        ];

        $data['multiple disable directives'] = [
            1,
            [
                ['packagist.org' => false],
                ['my-private-repo' => false],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/gamma',
                        'version' => '1.0.0',
                        'dist' => ['url' => 'https://example.com/gamma.zip', 'type' => 'zip'],
                    ],
                ],
            ],
        ];

        $data['disable directive at end'] = [
            1,
            [
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/delta',
                        'version' => '1.0.0',
                        'dist' => ['url' => 'https://example.com/delta.zip', 'type' => 'zip'],
                    ],
                ],
                ['packagist.org' => false],
            ],
        ];

        $data['no disable directives'] = [
            2,
            [
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/alpha',
                        'version' => '1.0.0',
                        'dist' => ['url' => 'https://example.com/alpha.zip', 'type' => 'zip'],
                    ],
                ],
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/beta',
                        'version' => '2.0.0',
                        'dist' => ['url' => 'https://example.com/beta.zip', 'type' => 'zip'],
                    ],
                ],
            ],
        ];

        return $data;
    }

    /**
     * Reproduces the repository iteration logic from BuildCommand::execute()
     * to verify that disable directives are skipped and valid repos are added.
     *
     * Without the fix, configs containing {"packagist.org": false} would throw
     * "Undefined array key 'type'"
     *
     * @param array<int, mixed> $repositories
     */
    #[DataProvider('dataRepositoryDisableDirectives')]
    #[TestDox('Repository loop skips disable directives')]
    public function testRepositoryLoopSkipsDisableDirectives(int $expectedRepoCount, array $repositories): void
    {
        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => $repositories,
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        $initialCount = count($manager->getRepositories());

        $disabledRepoNames = [];
        foreach ($config['repositories'] as $repo) {
            if (is_array($repo) && !isset($repo['type']) && 1 === count($repo) && false === current($repo)) {
                $disabledRepoNames[] = (string) key($repo);
                continue;
            }
            if (!isset($repo['type'])) {
                continue;
            }
            $manager->addRepository($manager->createRepository($repo['type'], $repo));
        }
        if ([] !== $disabledRepoNames) {
            $this->invokeRemoveDisabledRepositories($manager, $disabledRepoNames);
        }

        $addedRepos = count($manager->getRepositories()) - $initialCount;
        self::assertSame($expectedRepoCount, $addedRepos);
    }

    /**
     * Proves the disable directive has a functional effect: when packagist is
     * in the RepositoryManager's defaults, {"packagist.org": false} removes it.
     *
     * The Composer instance is created WITHOUT the directive (so packagist
     * survives Factory initialization), then the directive is applied after
     * the fact -- matching how BuildCommand processes it in plugin mode.
     */
    #[TestDox('Disable directive removes packagist from RepositoryManager')]
    public function testDisableDirectiveRemovesPackagistFromRepositoryManager(): void
    {
        Config::$defaultRepositories = [
            'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
        ];

        $configWithoutDirective = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/test',
                        'version' => '1.0.0',
                        'dist' => ['url' => 'https://example.com/test.zip', 'type' => 'zip'],
                    ],
                ],
            ],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $configWithoutDirective, true, null, false);
        $manager = $composer->getRepositoryManager();

        self::assertTrue(
            $this->repositoryManagerContainsPackagist($manager),
            'Packagist should be present before processing disable directive'
        );

        $this->invokeRemoveDisabledRepositories($manager, ['packagist.org']);

        self::assertFalse(
            $this->repositoryManagerContainsPackagist($manager),
            'Packagist should be removed after processing disable directive'
        );
    }

    #[TestDox('Packagist remains when no disable directive is present')]
    public function testPackagistRemainsWithoutDisableDirective(): void
    {
        Config::$defaultRepositories = [
            'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
        ];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        'name' => 'vendor/test',
                        'version' => '1.0.0',
                        'dist' => ['url' => 'https://example.com/test.zip', 'type' => 'zip'],
                    ],
                ],
            ],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        foreach ($config['repositories'] as $repo) {
            $manager->addRepository($manager->createRepository($repo['type'], $repo));
        }

        self::assertTrue(
            $this->repositoryManagerContainsPackagist($manager),
            'Packagist should remain when no disable directive is present'
        );
    }

    #[TestDox('Removing disabled repositories produces verbose output')]
    public function testRemoveDisabledRepositoriesOutputsInVerboseMode(): void
    {
        Config::$defaultRepositories = [
            'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
        ];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        self::assertTrue($this->repositoryManagerContainsPackagist($manager));

        $output = new BufferedOutput();
        $this->invokeRemoveDisabledRepositories($manager, ['packagist.org'], $output, true);

        self::assertFalse($this->repositoryManagerContainsPackagist($manager));
        self::assertStringContainsString(
            'Removed repository packagist.org (disabled by config)',
            $output->fetch()
        );
    }

    #[TestDox('Removing disabled repositories is silent in non-verbose mode')]
    public function testRemoveDisabledRepositoriesSilentInNonVerboseMode(): void
    {
        Config::$defaultRepositories = [
            'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
        ];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        $output = new BufferedOutput();
        $this->invokeRemoveDisabledRepositories($manager, ['packagist.org'], $output, false);

        self::assertSame('', $output->fetch());
    }

    #[TestDox('Repositories registered several times are only kept once')]
    public function testDeduplicateRepositoriesRemovesDuplicates(): void
    {
        $repoConfig = ['type' => 'composer', 'url' => 'https://example.com/repo'];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [$repoConfig],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        // the same registration Factory::create(), BuildCommand and RootPackageLoader each perform
        $manager->addRepository($manager->createRepository($repoConfig['type'], $repoConfig));
        $manager->addRepository($manager->createRepository($repoConfig['type'], $repoConfig));

        self::assertSame(3, $this->countRepositoriesWithUrl($manager, 'https://example.com/repo'));

        $output = new BufferedOutput();
        $this->invokeDeduplicateRepositories($manager, $output, true);

        self::assertSame(1, $this->countRepositoriesWithUrl($manager, 'https://example.com/repo'));
        self::assertStringContainsString(
            'Removed 2 duplicate registration(s) of repository composer https://example.com/repo',
            $output->fetch()
        );
    }

    #[TestDox('The registration sequence of the build command yields a single repository')]
    public function testRepositoriesOfTheConfigAreRegisteredOnce(): void
    {
        $repoConfig = ['type' => 'composer', 'url' => 'https://example.com/repo'];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [$repoConfig],
        ];

        // 1. the Composer instance built from the Satis config, as in standalone mode
        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        // 2. the repositories the build command feeds into the manager
        $manager->addRepository($manager->createRepository($repoConfig['type'], $repoConfig));

        // 3. the root package load, which adds the repositories of the config once more
        $parser = new VersionParser();
        $process = new ProcessExecutor(new NullIO());
        $process->enableAsync();
        $guesser = new VersionGuesser($composer->getConfig(), $process, $parser);
        $loader = new RootPackageLoader($manager, $composer->getConfig(), $parser, $guesser);
        $loader->load($config);

        self::assertSame(3, $this->countRepositoriesWithUrl($manager, 'https://example.com/repo'));

        $this->invokeDeduplicateRepositories($manager);

        self::assertSame(1, $this->countRepositoriesWithUrl($manager, 'https://example.com/repo'));
    }

    #[TestDox('Deduplication keeps distinct repositories')]
    public function testDeduplicateRepositoriesKeepsDistinctRepositories(): void
    {
        $first = ['type' => 'composer', 'url' => 'https://example.com/first'];
        $second = ['type' => 'composer', 'url' => 'https://example.com/second'];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [$first, $second],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        $output = new BufferedOutput();
        $this->invokeDeduplicateRepositories($manager, $output, false);

        self::assertSame(1, $this->countRepositoriesWithUrl($manager, 'https://example.com/first'));
        self::assertSame(1, $this->countRepositoriesWithUrl($manager, 'https://example.com/second'));
        self::assertSame('', $output->fetch());
    }

    private function invokeDeduplicateRepositories(RepositoryManager $manager, ?OutputInterface $output = null, bool $verbose = false): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'deduplicateRepositories');
        $method->invokeArgs($command, [$manager, $output ?? new NullOutput(), $verbose]);
    }

    private function countRepositoriesWithUrl(RepositoryManager $manager, string $url): int
    {
        $count = 0;
        foreach ($manager->getRepositories() as $repo) {
            if ($repo instanceof ConfigurableRepositoryInterface && ($repo->getRepoConfig()['url'] ?? null) === $url) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param string[] $disabledRepoNames
     */
    private function invokeRemoveDisabledRepositories(RepositoryManager $manager, array $disabledRepoNames, ?OutputInterface $output = null, bool $verbose = false): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'removeDisabledRepositories');
        $method->invokeArgs($command, [$manager, $disabledRepoNames, $output ?? new NullOutput(), $verbose]);
    }

    private function repositoryManagerContainsPackagist(RepositoryManager $manager): bool
    {
        foreach ($manager->getRepositories() as $repo) {
            if ($repo instanceof ConfigurableRepositoryInterface) {
                $url = $repo->getRepoConfig()['url'] ?? '';
                if (1 === preg_match('{^https?://(?:[a-z0-9-.]+\.)?packagist\.org(/|$)}i', $url)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->addCommand(new BuildCommand());

        return new CommandTester($app->find('build'));
    }

    private function createSatisTester(): CommandTester
    {
        $app = new SatisApplication();
        $ioRef = new \ReflectionProperty($app, 'io');
        $ioRef->setValue($app, new NullIO());

        return new CommandTester($app->find('build'));
    }

    private function makeOutputDir(string $prefix = 'output'): string
    {
        $outputDir = vfsStream::url('satis/' . $prefix . '_' . uniqid());
        mkdir($outputDir);

        return $outputDir;
    }

    /** @return array<string, mixed> */
    private function packageRepo(string $name = 'vendor/test-pkg', string $version = '1.0.0', ?string $distUrl = null): array
    {
        return [
            'type' => 'package',
            'package' => [
                'name' => $name,
                'version' => $version,
                'dist' => [
                    'url' => $distUrl ?? 'https://example.com/test.zip',
                    'type' => 'zip',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function baseSatisConfig(array $overrides = []): array
    {
        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                $this->packageRepo(),
            ],
            'require-all' => true,
        ];

        return array_replace($config, $overrides);
    }

    /**
     * @param array<string, mixed> $buildArgs
     * @param array<string, mixed> $config
     */
    private function executeSatis(array $buildArgs, array $config): CommandTester
    {
        $this->writeConfig($config);

        $tester = $this->createSatisTester();
        $tester->execute($buildArgs);

        return $tester;
    }

    #[TestDox('check() returns true for valid JSON matching the schema')]
    public function testCheckReturnsTrueForValidConfig(): void
    {
        $this->writeConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ]);

        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'check');

        self::assertTrue($method->invoke($command, $this->configPath));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function dataRelativeUriReferences(): array
    {
        return [
            'relative homepage' => [['homepage' => '/satis']],
            'relative archive prefix-url' => [['archive' => ['directory' => 'dist', 'prefix-url' => '/satis']]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('dataRelativeUriReferences')]
    #[TestDox('check() accepts a relative URI reference for homepage and archive prefix-url')]
    public function testCheckAcceptsRelativeUriReferences(array $overrides): void
    {
        $this->writeConfig(array_replace([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ], $overrides));

        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'check');

        self::assertTrue($method->invoke($command, $this->configPath));
    }

    #[TestDox('check() still rejects an archive prefix-url that is not a URI reference')]
    public function testCheckRejectsInvalidPrefixUrl(): void
    {
        $this->writeConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
            'archive' => ['directory' => 'dist', 'prefix-url' => 'not a uri'],
        ]);

        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'check');

        $this->expectException(JsonValidationException::class);

        $method->invoke($command, $this->configPath);
    }

    #[TestDox('check() throws ParsingException for invalid JSON')]
    public function testCheckThrowsParsingExceptionForInvalidJson(): void
    {
        file_put_contents($this->configPath, '{invalid json!!!}');

        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'check');

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage('does not contain valid JSON');

        $method->invoke($command, $this->configPath);
    }

    #[TestDox('check() throws JsonValidationException when schema validation fails')]
    public function testCheckThrowsJsonValidationExceptionForSchemaFailure(): void
    {
        file_put_contents($this->configPath, json_encode(['repositories' => []]));

        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'check');

        $this->expectException(JsonValidationException::class);
        $this->expectExceptionMessage('does not match the expected JSON schema');

        $method->invoke($command, $this->configPath);
    }

    #[TestDox('check() throws ParsingException when file cannot be read')]
    public function testCheckThrowsParsingExceptionForUnreadableFile(): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'check');

        $this->expectException(ParsingException::class);
        $this->expectExceptionMessage('does not contain valid JSON');

        @$method->invoke($command, vfsStream::url('satis/nonexistent.json'));
    }

    #[TestDox('getComposerHome() returns COMPOSER_HOME when set')]
    public function testGetComposerHomeReturnsComposerHome(): void
    {
        $original = getenv('COMPOSER_HOME');
        putenv('COMPOSER_HOME=/custom/composer/home');

        try {
            $command = new BuildCommand();
            $method = new \ReflectionMethod($command, 'getComposerHome');

            self::assertSame('/custom/composer/home', $method->invoke($command));
        } finally {
            putenv(false === $original ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $original);
        }
    }

    #[TestDox('getComposerHome() falls back to HOME/.composer')]
    public function testGetComposerHomeFallsBackToHome(): void
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            self::markTestSkipped('Non-Windows test');
        }

        $originalComposerHome = getenv('COMPOSER_HOME');
        $originalHome = getenv('HOME');
        putenv('COMPOSER_HOME');
        putenv('HOME=/test/user');

        try {
            $command = new BuildCommand();
            $method = new \ReflectionMethod($command, 'getComposerHome');

            self::assertSame('/test/user/.composer', $method->invoke($command));
        } finally {
            putenv(false === $originalHome ? 'HOME' : 'HOME=' . $originalHome);
            putenv(false === $originalComposerHome ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $originalComposerHome);
        }
    }

    #[TestDox('getComposerHome() throws when HOME and COMPOSER_HOME are unset')]
    public function testGetComposerHomeThrowsWhenNoEnvVars(): void
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            self::markTestSkipped('Non-Windows test');
        }

        $originalComposerHome = getenv('COMPOSER_HOME');
        $originalHome = getenv('HOME');
        putenv('COMPOSER_HOME');
        putenv('HOME');

        try {
            $command = new BuildCommand();
            $method = new \ReflectionMethod($command, 'getComposerHome');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HOME or COMPOSER_HOME');

            $method->invoke($command);
        } finally {
            putenv(false === $originalHome ? 'HOME' : 'HOME=' . $originalHome);
            putenv(false === $originalComposerHome ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $originalComposerHome);
        }
    }

    #[TestDox('execute() returns exit code 1 when config file is not found')]
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

    #[TestDox('execute() throws when --repository-url and packages are both provided')]
    public function testExecuteThrowsOnRepositoryUrlWithPackages(): void
    {
        $this->writeConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ]);

        $tester = $this->createTester();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('can not be used together');

        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => vfsStream::url('satis/output'),
            'packages' => ['vendor/alpha'],
            '--repository-url' => ['https://example.com/repo.git'],
            '--skip-errors' => true,
        ]);
    }

    #[TestDox('execute() throws when output directory is not specified')]
    public function testExecuteThrowsWhenOutputDirMissing(): void
    {
        $this->writeConfig([
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ]);

        $tester = $this->createTester();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('output dir must be specified');

        $tester->execute([
            'file' => $this->configPath,
            '--skip-errors' => true,
        ]);
    }

    #[TestDox('shouldBuildHtml() returns false when --no-html-output is passed')]
    public function testShouldBuildHtmlReturnsFalseWithNoHtmlFlag(): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'shouldBuildHtml');

        $input = new \Symfony\Component\Console\Input\ArrayInput(
            ['--no-html-output' => true],
            $command->getDefinition()
        );

        self::assertFalse($method->invoke($command, $input, []));
    }

    #[TestDox('shouldBuildHtml() returns true by default')]
    public function testShouldBuildHtmlReturnsTrueByDefault(): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'shouldBuildHtml');

        $input = new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());

        self::assertTrue($method->invoke($command, $input, []));
    }

    #[TestDox('shouldBuildHtml() returns true when output-html is true in config')]
    public function testShouldBuildHtmlReturnsTrueWhenConfigEnabled(): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'shouldBuildHtml');

        $input = new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());

        self::assertTrue($method->invoke($command, $input, ['output-html' => true]));
    }

    #[TestDox('shouldBuildHtml() returns false when output-html is false in config')]
    public function testShouldBuildHtmlReturnsFalseWhenConfigDisabled(): void
    {
        $command = new BuildCommand();
        $method = new \ReflectionMethod($command, 'shouldBuildHtml');

        $input = new \Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());

        self::assertFalse($method->invoke($command, $input, ['output-html' => false]));
    }

    #[TestDox('execute() runs a full build with package repositories')]
    public function testExecuteRunsFullBuild(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ],
            $this->baseSatisConfig()
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($outputDir . '/packages.json');
    }

    #[TestDox('execute() reads output-dir from config when not passed as argument')]
    public function testExecuteReadsOutputDirFromConfig(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            ['file' => $this->configPath],
            $this->baseSatisConfig(['output-dir' => $outputDir])
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($outputDir . '/packages.json');
    }

    #[TestDox('execute() picks up SATIS_HOMEPAGE from environment')]
    public function testExecuteUsesSatisHomepageEnv(): void
    {
        $outputDir = $this->makeOutputDir();

        $original = getenv('SATIS_HOMEPAGE');
        putenv('SATIS_HOMEPAGE=https://custom.example.com');

        try {
            $tester = $this->executeSatis(
                [
                    'file' => $this->configPath,
                    'output-dir' => $outputDir,
                ],
                $this->baseSatisConfig()
            );

            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('SATIS_HOMEPAGE', $tester->getDisplay());
        } finally {
            putenv(false === $original ? 'SATIS_HOMEPAGE' : 'SATIS_HOMEPAGE=' . $original);
        }
    }

    #[TestDox('execute() processes disable directives during build')]
    public function testExecuteProcessesDisableDirectives(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ],
            $this->baseSatisConfig([
                'repositories' => [
                    ['packagist.org' => false],
                    $this->packageRepo(),
                ],
            ])
        );

        self::assertSame(0, $tester->getStatusCode());
    }

    #[TestDox('execute() builds HTML output by default')]
    public function testExecuteBuildsHtmlByDefault(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ],
            $this->baseSatisConfig()
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($outputDir . '/index.html');
    }

    #[TestDox('execute() suppresses HTML output with --no-html-output')]
    public function testExecuteSuppressesHtmlWithFlag(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
                '--no-html-output' => true,
            ],
            $this->baseSatisConfig()
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileDoesNotExist($outputDir . '/index.html');
    }

    #[TestDox('execute() suppresses HTML output when output-html is false in config')]
    public function testExecuteSuppressesHtmlWithConfig(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ],
            $this->baseSatisConfig(['output-html' => false])
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileDoesNotExist($outputDir . '/index.html');
    }

    #[TestDox('execute() continues on schema validation failure with --skip-errors')]
    public function testExecuteContinuesOnSchemaFailureWithSkipErrors(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
                '--skip-errors' => true,
            ],
            [
                // Intentionally missing "homepage" to trigger schema validation failure.
                'name' => 'test/satis-repo',
                'repositories' => [
                    $this->packageRepo(),
                ],
                'require-all' => true,
            ]
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('homepage', $tester->getDisplay());
    }

    #[TestDox('execute() throws schema validation error without --skip-errors')]
    public function testExecuteThrowsOnSchemaFailureWithoutSkipErrors(): void
    {
        $outputDir = $this->makeOutputDir();

        $this->expectException(JsonValidationException::class);

        $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
            ],
            [
                // Intentionally missing "homepage" to trigger schema validation failure.
                'name' => 'test/satis-repo',
                'repositories' => [],
            ]
        );
    }

    #[TestDox('execute() applies --repository-url filter')]
    public function testExecuteAppliesRepositoryUrlFilter(): void
    {
        $outputDir = $this->makeOutputDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('do not exist');

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--repository-url' => ['https://example.com/nonexistent.git'],
        ], $this->baseSatisConfig());
    }

    #[TestDox('removeDisabledRepositories retains non-matching ConfigurableRepositoryInterface repos')]
    public function testRemoveDisabledRepositoriesRetainsNonMatchingRepos(): void
    {
        Config::$defaultRepositories = [
            'packagist.org' => ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
            'private' => ['type' => 'composer', 'url' => 'https://private.example.com'],
        ];

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $config, true, null, false);
        $manager = $composer->getRepositoryManager();

        $countBefore = count($manager->getRepositories());
        $this->invokeRemoveDisabledRepositories($manager, ['packagist.org']);
        $countAfter = count($manager->getRepositories());

        self::assertFalse($this->repositoryManagerContainsPackagist($manager));
        self::assertSame($countBefore - 1, $countAfter);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function dataWorkingDirectoryHasComposerJson(): array
    {
        return [
            // Composer's BaseCommand::initialize() then creates a Composer instance from it, which
            // getComposerWithConfig() reuses and merges the Satis config into
            'working directory with composer.json' => [true],
            // getComposerWithConfig() then creates the Composer instance from the Satis config
            'working directory without composer.json' => [false],
        ];
    }

    #[DataProvider('dataWorkingDirectoryHasComposerJson')]
    #[TestDox('execute() loads the auth.json next to the config file, over the global auth.json and the config block ($_dataName)')]
    public function testExecuteLoadsAuthJsonNextToConfigFile(bool $workingDirectoryHasComposerJson): void
    {
        $homeDir = vfsStream::url('satis/composer-home');
        mkdir($homeDir);
        file_put_contents($homeDir . '/auth.json', json_encode([
            'http-basic' => [
                'example.org' => ['username' => 'global-user', 'password' => 'global-pass'],
                'global.example.org' => ['username' => 'global-only', 'password' => 'global-only-pass'],
            ],
        ]));
        file_put_contents(vfsStream::url('satis/auth.json'), json_encode([
            'http-basic' => [
                'example.org' => ['username' => 'project-user', 'password' => 'project-pass'],
                'config.example.org' => ['username' => 'project-config-user', 'password' => 'project-config-pass'],
            ],
        ]));

        $originalEnv = ['COMPOSER_HOME' => Platform::getEnv('COMPOSER_HOME'), 'COMPOSER' => Platform::getEnv('COMPOSER')];
        Platform::putEnv('COMPOSER_HOME', $homeDir);
        if (!$workingDirectoryHasComposerJson) {
            Platform::putEnv('COMPOSER', vfsStream::url('satis/missing/composer.json'));
        }

        try {
            $this->writeConfig($this->baseSatisConfig([
                'config' => [
                    'http-basic' => [
                        'config.example.org' => ['username' => 'config-user', 'password' => 'config-pass'],
                        'satis.example.org' => ['username' => 'satis-user', 'password' => 'satis-pass'],
                    ],
                ],
            ]));

            $io = new NullIO();
            $app = new SatisApplication();
            (new \ReflectionProperty($app, 'io'))->setValue($app, $io);
            $tester = new CommandTester($app->find('build'));
            $tester->execute(['file' => $this->configPath, 'output-dir' => $this->makeOutputDir()]);

            self::assertSame(0, $tester->getStatusCode());

            $expected = [
                'example.org' => ['username' => 'project-user', 'password' => 'project-pass'],
                'global.example.org' => ['username' => 'global-only', 'password' => 'global-only-pass'],
                'config.example.org' => ['username' => 'project-config-user', 'password' => 'project-config-pass'],
                'satis.example.org' => ['username' => 'satis-user', 'password' => 'satis-pass'],
            ];
            foreach ($expected as $host => $credentials) {
                self::assertSame($credentials, $io->getAuthentication($host), $host);
            }

            $composer = (new \ReflectionProperty($app, 'composer'))->getValue($app);
            self::assertInstanceOf(Composer::class, $composer);
            $httpBasic = $composer->getConfig()->get('http-basic');
            foreach ($expected as $host => $credentials) {
                self::assertSame($credentials, $httpBasic[$host] ?? null, $host);
            }
        } finally {
            foreach ($originalEnv as $name => $value) {
                false === $value ? Platform::clearEnv($name) : Platform::putEnv($name, $value);
            }
        }
    }

    #[TestDox('execute() rejects an auth.json next to the config file that does not match the auth schema')]
    public function testExecuteRejectsInvalidAuthJsonNextToConfigFile(): void
    {
        file_put_contents(vfsStream::url('satis/auth.json'), json_encode(['http-basic' => 'not-an-object']));

        $this->expectException(JsonValidationException::class);

        $this->executeSatis(['file' => $this->configPath, 'output-dir' => $this->makeOutputDir()], $this->baseSatisConfig());
    }

    #[TestDox('getConfiguration() loads auth.json when present')]
    public function testGetConfigurationLoadsAuthJson(): void
    {
        $homeDir = vfsStream::url('satis/composer-home');
        mkdir($homeDir);
        file_put_contents(
            $homeDir . '/auth.json',
            json_encode(['http-basic' => ['example.com' => ['username' => 'user', 'password' => 'pass']]])
        );

        $originalComposerHome = getenv('COMPOSER_HOME');
        putenv('COMPOSER_HOME=' . $homeDir);

        try {
            $command = new BuildCommand();
            $method = new \ReflectionMethod($command, 'getConfiguration');
            $config = $method->invoke($command);

            self::assertInstanceOf(Config::class, $config);
        } finally {
            putenv(false === $originalComposerHome ? 'COMPOSER_HOME' : 'COMPOSER_HOME=' . $originalComposerHome);
        }
    }

    #[TestDox('execute() works via ComposerApplication path')]
    public function testExecuteWorksViaComposerApplication(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->createTester();
        $this->writeConfig($this->baseSatisConfig());
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($outputDir . '/packages.json');
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function dataStrictOption(): array
    {
        return [
            'without --strict' => [false, 0],
            'with --strict' => [true, 1],
        ];
    }

    #[DataProvider('dataStrictOption')]
    #[TestDox('execute() exit code on an unmatched root requirement $_dataName')]
    public function testExecuteStrictFailsOnUnmatchedRootRequirement(bool $strict, int $expectedStatusCode): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
                '--strict' => $strict,
            ],
            $this->baseSatisConfig([
                'require-all' => false,
                'require' => [
                    'vendor/test-pkg' => '^1.0',
                    'vendor/missing' => '^2.0',
                ],
            ])
        );

        self::assertSame($expectedStatusCode, $tester->getStatusCode());
        self::assertFileExists($outputDir . '/packages.json');
        self::assertStringContainsString('The vendor/missing ^2.0 requirement did not match any package', $tester->getDisplay());
        if ($strict) {
            self::assertStringContainsString('Strict mode', $tester->getDisplay());
        }
    }

    #[TestDox('execute() with --strict succeeds when all root requirements match')]
    public function testExecuteStrictSucceedsWhenAllRootRequirementsMatch(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
                '--strict' => true,
            ],
            $this->baseSatisConfig([
                'require-all' => false,
                'require' => ['vendor/test-pkg' => '^1.0'],
            ])
        );

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('Strict mode', $tester->getDisplay());
    }

    #[TestDox('execute() with --strict ignores unmatched requirements of dependencies')]
    public function testExecuteStrictIgnoresUnmatchedDependencies(): void
    {
        $outputDir = $this->makeOutputDir();

        $package = $this->packageRepo();
        $package['package']['require'] = ['vendor/missing-dependency' => '^1.0'];

        $tester = $this->executeSatis(
            [
                'file' => $this->configPath,
                'output-dir' => $outputDir,
                '--strict' => true,
            ],
            $this->baseSatisConfig([
                'repositories' => [$package],
                'require-all' => false,
                'require' => ['vendor/test-pkg' => '^1.0'],
                'require-dependencies' => true,
            ])
        );

        self::assertStringContainsString('The vendor/missing-dependency ^1.0 requirement did not match any package', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
    }

    #[DataProvider('dataStrictOption')]
    #[TestDox('execute() with --skip-errors exit code on a repository that failed to load $_dataName')]
    public function testExecuteStrictFailsOnErrorSkippedBySkipErrors(bool $strict, int $expectedStatusCode): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            ['file' => $this->configPath, 'output-dir' => $outputDir, '--skip-errors' => true, '--strict' => $strict],
            $this->baseSatisConfig([
                'repositories' => [
                    $this->packageRepo(),
                    ['type' => 'vcs', 'url' => vfsStream::url('satis/missing-repository')],
                ],
            ])
        );

        self::assertSame($expectedStatusCode, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($outputDir . '/packages.json');
        self::assertStringContainsString('vendor/test-pkg', (string) file_get_contents($outputDir . '/packages.json'));
        $display = $tester->getDisplay();
        self::assertStringContainsString("Skipping Exception 'No driver found to handle VCS repository vfs://satis/missing-repository'.", $display);
        if ($strict) {
            self::assertStringContainsString("Errors skipped by --skip-errors:\n - No driver found to handle VCS repository vfs://satis/missing-repository\n", $display);
        } else {
            self::assertStringNotContainsString('Strict mode', $display);
        }
    }

    #[TestDox('execute() with --strict and require-all ignores packages of a composer repository without a version allowed by minimum-stability')]
    public function testExecuteStrictWithRequireAllIgnoresComposerRepositoryPackagesBelowMinimumStability(): void
    {
        $repoDir = sys_get_temp_dir() . '/satis-composer-repo-' . uniqid('', true);
        mkdir($repoDir);
        $dist = ['url' => 'https://example.com/test.zip', 'type' => 'zip'];
        file_put_contents($repoDir . '/packages.json', json_encode(['packages' => [
            'vendor/dev-only' => ['dev-main' => ['name' => 'vendor/dev-only', 'version' => 'dev-main', 'dist' => $dist]],
            'vendor/stable' => ['1.0.0' => ['name' => 'vendor/stable', 'version' => '1.0.0', 'dist' => $dist]],
        ]]));

        try {
            $outputDir = $this->makeOutputDir();
            $tester = $this->executeSatis(
                ['file' => $this->configPath, 'output-dir' => $outputDir, '--strict' => true],
                $this->baseSatisConfig([
                    'repositories' => [['type' => 'composer', 'url' => 'file://' . $repoDir]],
                    'minimum-stability' => 'stable',
                ])
            );

            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            self::assertStringNotContainsString('Strict mode', $tester->getDisplay());
            self::assertStringContainsString('vendor/stable', (string) file_get_contents($outputDir . '/packages.json'));
        } finally {
            (new SymfonyFilesystem())->remove($repoDir);
        }
    }

    #[DataProvider('dataStrictOption')]
    #[TestDox('execute() exit code on a VCS mirror that failed to update $_dataName')]
    public function testExecuteStrictFailsOnFailedMirrorUpdate(bool $strict, int $expectedStatusCode): void
    {
        $outputDir = $this->makeOutputDir();
        $this->writeConfig($this->baseSatisConfig());

        $app = new class extends SatisApplication {
            public function getComposerWithConfig(mixed $config, ?IOInterface $io = null): ?Composer
            {
                $composer = parent::getComposerWithConfig($config, $io);
                ($io ?? $this->io)->writeError('<error>Failed to update https://example.com/repo.git, package information from this repository may be outdated</error>');

                return $composer;
            }
        };
        $ioRef = new \ReflectionProperty($app, 'io');
        $ioRef->setValue($app, new NullIO());

        $tester = new CommandTester($app->find('build'));
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--strict' => $strict,
        ]);

        self::assertSame($expectedStatusCode, $tester->getStatusCode());
        self::assertFileExists($outputDir . '/packages.json');
        if ($strict) {
            self::assertStringContainsString('Failed to update https://example.com/repo.git', $tester->getDisplay());
        }
    }

    #[DataProvider('dataStrictOption')]
    #[TestDox('execute() via ComposerApplication exit code on a VCS mirror that failed to update $_dataName')]
    public function testExecuteStrictViaComposerApplicationFailsOnFailedMirrorUpdate(bool $strict, int $expectedStatusCode): void
    {
        $outputDir = $this->makeOutputDir();
        $this->writeConfig($this->baseSatisConfig([
            'repositories' => [
                $this->packageRepo(),
                ['type' => 'failing-update', 'url' => 'https://example.com/repo.git'],
            ],
        ]));

        $app = new class extends Application {
            public function getComposer(bool $required = true, ?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
            {
                $composer = parent::getComposer($required, $disablePlugins, $disableScripts);
                $composer?->getRepositoryManager()->setRepositoryClass('failing-update', FailingUpdateRepository::class);

                return $composer;
            }
        };
        $app->addCommand(new BuildCommand());

        $tester = new CommandTester($app->find('build'));
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--strict' => $strict,
        ]);

        self::assertSame($expectedStatusCode, $tester->getStatusCode());
        if ($strict) {
            self::assertStringContainsString('Failed to update https://example.com/repo.git', $tester->getDisplay());
        }
    }

    #[TestDox('Repository loop skips entries without a type key')]
    public function testRepositoryLoopSkipsTypelessEntries(): void
    {
        $validConfig = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [],
        ];

        $composer = (new Factory())->createComposer(new NullIO(), $validConfig, true, null, false);
        $manager = $composer->getRepositoryManager();

        $initialCount = count($manager->getRepositories());

        /** @var array<int, mixed> $repositories */
        $repositories = [
            ['url' => 'https://example.com/no-type-repo'],
            [
                'type' => 'package',
                'package' => [
                    'name' => 'vendor/test-pkg',
                    'version' => '1.0.0',
                    'dist' => ['url' => 'https://example.com/test.zip', 'type' => 'zip'],
                ],
            ],
        ];

        foreach ($repositories as $repo) {
            if (!is_array($repo) || !isset($repo['type'])) {
                continue;
            }
            $manager->addRepository($manager->createRepository($repo['type'], $repo));
        }

        $addedRepos = count($manager->getRepositories()) - $initialCount;
        self::assertSame(1, $addedRepos);
    }

    #[TestDox('execute() runs ArchiveBuilder when archive directory is configured')]
    public function testExecuteRunsArchiveBuilder(): void
    {
        $outputDir = $this->makeOutputDir();

        $this->writeConfig(
            $this->baseSatisConfig([
                'archive' => ['directory' => 'dist'],
            ])
        );

        $tester = $this->createSatisTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--skip-errors' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    #[TestDox('execute() extracts archives outside the working directory when no vendor-dir is configured')]
    public function testExecuteArchivesWithoutVendorDirInWorkingDirectory(): void
    {
        $workDir = $this->makeArchiveWorkDir();

        try {
            $app = $this->buildArchivesIn($workDir, []);

            self::assertFileExists($workDir . '/output/dist/vendor/test-pkg/vendor-test-pkg-1.0.0.tar');
            self::assertDirectoryDoesNotExist($workDir . '/cwd/vendor');

            $vendorDir = $app->getComposer()->getConfig()->get('vendor-dir');
            self::assertIsString($vendorDir);
            self::assertStringStartsNotWith($workDir . '/cwd', $vendorDir);
            self::assertDirectoryDoesNotExist($vendorDir, 'The temporary vendor-dir is removed after the build');
        } finally {
            (new SymfonyFilesystem())->remove($workDir);
        }
    }

    #[TestDox('execute() extracts archives into the configured vendor-dir')]
    public function testExecuteArchivesWithConfiguredVendorDir(): void
    {
        $workDir = $this->makeArchiveWorkDir();

        try {
            $app = $this->buildArchivesIn($workDir, ['vendor-dir' => $workDir . '/custom-vendor']);

            self::assertFileExists($workDir . '/output/dist/vendor/test-pkg/vendor-test-pkg-1.0.0.tar');
            self::assertDirectoryDoesNotExist($workDir . '/cwd/vendor');
            self::assertSame($workDir . '/custom-vendor', $app->getComposer()->getConfig()->get('vendor-dir'));
            self::assertDirectoryExists($workDir . '/custom-vendor/composer', 'The configured vendor-dir is used and kept');
        } finally {
            (new SymfonyFilesystem())->remove($workDir);
        }
    }

    private function makeArchiveWorkDir(): string
    {
        $workDir = sys_get_temp_dir() . '/satis-build-' . uniqid('', true);
        mkdir($workDir . '/cwd', 0o777, true);
        mkdir($workDir . '/output');

        $zip = new \ZipArchive();
        $zip->open($workDir . '/test-pkg.zip', \ZipArchive::CREATE);
        $zip->addFromString('README.md', '# Test package');
        $zip->close();

        return $workDir;
    }

    /**
     * Builds archives of a package whose dist archive has to be extracted, from within "$workDir/cwd".
     *
     * @param array<string, mixed> $composerConfig
     */
    private function buildArchivesIn(string $workDir, array $composerConfig): SatisApplication
    {
        $config = $this->baseSatisConfig([
            'repositories' => [$this->packageRepo(distUrl: 'file://' . $workDir . '/test-pkg.zip')],
            'archive' => ['directory' => 'dist', 'format' => 'tar', 'override-dist-type' => true],
            'output-html' => false,
        ]);
        if ([] !== $composerConfig) {
            $config['config'] = $composerConfig;
        }
        $this->writeConfig($config);

        $app = new SatisApplication();
        (new \ReflectionProperty($app, 'io'))->setValue($app, new NullIO());
        $tester = new CommandTester($app->find('build'));

        $cwd = (string) getcwd();
        chdir($workDir . '/cwd');
        try {
            $status = $tester->execute(['file' => $this->configPath, 'output-dir' => $workDir . '/output']);
        } finally {
            chdir($cwd);
        }

        self::assertSame(0, $status, $tester->getDisplay());

        return $app;
    }

    #[TestDox('execute() with --branch filters to dev branch versions')]
    public function testExecuteWithBranchFiltersDevVersions(): void
    {
        $outputDir = $this->makeOutputDir();

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package-a', 'version' => '1.0.0'],
                        ['name' => 'vendor/package-a', 'version' => 'dev-main'],
                        ['name' => 'vendor/package-a', 'version' => 'dev-feature-x'],
                        ['name' => 'vendor/package-b', 'version' => '2.0.0'],
                    ],
                ],
            ],
            'require-all' => true,
            'output-html' => false,
        ];

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ], $config);

        self::assertFileExists($outputDir . '/packages.json');

        $this->writeConfig($config);
        $tester = $this->createSatisTester();
        $status = $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => 'main',
            'packages' => ['vendor/package-a'],
        ], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(0, $status);
        self::assertStringContainsString('Matched branch version', $tester->getDisplay());
    }

    #[TestDox('execute() with --branch preserves other packages')]
    public function testExecuteWithBranchPreservesOtherPackages(): void
    {
        $outputDir = $this->makeOutputDir();

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package-a', 'version' => '1.0.0'],
                        ['name' => 'vendor/package-a', 'version' => 'dev-main'],
                        ['name' => 'vendor/package-b', 'version' => '2.0.0'],
                    ],
                ],
            ],
            'require-all' => true,
            'output-html' => false,
        ];

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ], $config);

        $packagesBefore = json_decode((string) file_get_contents($outputDir . '/packages.json'), true);
        self::assertIsArray($packagesBefore);

        $this->writeConfig($config);
        $tester = $this->createSatisTester();
        $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => 'main',
            'packages' => ['vendor/package-a'],
        ], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $packagesAfter = json_decode((string) file_get_contents($outputDir . '/packages.json'), true);
        self::assertIsArray($packagesAfter);
        self::assertEquals(
            $packagesBefore['available-packages'],
            $packagesAfter['available-packages']
        );
    }

    #[TestDox('execute() with a package filter keeps the other packages when includes are disabled')]
    public function testExecuteWithPackagesFilterPreservesOtherPackagesWithoutIncludes(): void
    {
        $outputDir = $this->makeOutputDir();

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package-a', 'version' => '1.0.0'],
                        ['name' => 'vendor/package-b', 'version' => '2.0.0'],
                        ['name' => 'vendor/package-b', 'version' => 'dev-main'],
                    ],
                ],
            ],
            'require-all' => true,
            'includes' => false,
            'output-html' => false,
        ];

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ], $config);

        $tester = $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            'packages' => ['vendor/package-a'],
        ], $config);

        self::assertSame(0, $tester->getStatusCode());
        $packagesAfter = json_decode((string) file_get_contents($outputDir . '/packages.json'), true);
        self::assertIsArray($packagesAfter);
        self::assertSame(['vendor/package-a', 'vendor/package-b'], $packagesAfter['available-packages']);
    }

    #[TestDox('execute() with --branch preserves other branches of the same package')]
    public function testExecuteWithBranchPreservesOtherBranches(): void
    {
        $outputDir = $this->makeOutputDir();

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package-a', 'version' => '1.0.0'],
                        ['name' => 'vendor/package-a', 'version' => 'dev-main'],
                        ['name' => 'vendor/package-a', 'version' => 'dev-feature-x'],
                    ],
                ],
            ],
            'require-all' => true,
            'output-html' => false,
        ];

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ], $config);

        $this->writeConfig($config);
        $tester = $this->createSatisTester();
        $status = $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => 'main',
            'packages' => ['vendor/package-a'],
        ]);

        self::assertSame(0, $status);

        $packagesAfter = json_decode((string) file_get_contents($outputDir . '/packages.json'), true);
        self::assertIsArray($packagesAfter);
        self::assertContains('vendor/package-a', $packagesAfter['available-packages']);
    }

    #[TestDox('execute() with --branch only updates the requested branch version metadata')]
    public function testExecuteWithBranchOnlyUpdatesRequestedBranchReference(): void
    {
        $outputDir = $this->makeOutputDir();

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        ['name' => 'vendor/package-a', 'version' => '1.0.0', 'dist' => ['url' => 'https://old.example.com/1.0.zip', 'type' => 'zip']],
                        ['name' => 'vendor/package-a', 'version' => 'dev-main', 'dist' => ['url' => 'https://old.example.com/dev-main.zip', 'type' => 'zip']],
                        ['name' => 'vendor/package-a', 'version' => 'dev-feature-x', 'dist' => ['url' => 'https://old.example.com/feat-x.zip', 'type' => 'zip']],
                        ['name' => 'vendor/other-pkg', 'version' => '1.0.0', 'dist' => ['url' => 'https://old.example.com/other.zip', 'type' => 'zip']],
                    ],
                ],
            ],
            'require-all' => true,
            'output-html' => false,
        ];

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ], $config);

        $config['repositories'][0]['package'][1]['dist']['url'] = 'https://new.example.com/dev-main.zip';

        $this->writeConfig($config);
        $tester = $this->createSatisTester();
        $status = $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => 'main',
            'packages' => ['vendor/package-a'],
        ]);

        self::assertSame(0, $status);

        $devJson = json_decode((string) file_get_contents($outputDir . '/p2/vendor/package-a~dev.json'), true);
        self::assertIsArray($devJson);

        $devMain = array_values(array_filter(
            $devJson['packages']['vendor/package-a'],
            static fn (array $pkg): bool => 'dev-main' === $pkg['version']
        ));
        self::assertCount(1, $devMain);
        self::assertSame('https://new.example.com/dev-main.zip', $devMain[0]['dist']['url']);

        $devFeatureX = array_values(array_filter(
            $devJson['packages']['vendor/package-a'],
            static fn (array $pkg): bool => 'dev-feature-x' === $pkg['version']
        ));
        self::assertCount(1, $devFeatureX);
        self::assertSame('https://old.example.com/feat-x.zip', $devFeatureX[0]['dist']['url']);

        $stableJson = json_decode((string) file_get_contents($outputDir . '/p2/vendor/package-a.json'), true);
        self::assertIsArray($stableJson);
        $v1 = array_values(array_filter(
            $stableJson['packages']['vendor/package-a'],
            static fn (array $pkg): bool => '1.0.0' === $pkg['version']
        ));
        self::assertCount(1, $v1);
        self::assertSame('https://old.example.com/1.0.zip', $v1[0]['dist']['url']);
    }

    #[TestDox('execute() --branch fails without packages')]
    public function testExecuteBranchFailsWithoutPackages(): void
    {
        $outputDir = $this->makeOutputDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one package');

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => 'main',
        ], $this->baseSatisConfig());
    }

    #[TestDox('execute() --branch and --repository-url are mutually exclusive')]
    public function testExecuteBranchAndRepositoryUrlAreMutuallyExclusive(): void
    {
        $outputDir = $this->makeOutputDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be used together');

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => 'main',
            '--repository-url' => ['https://example.com/repo.git'],
            'packages' => ['vendor/package-a'],
        ], $this->baseSatisConfig());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function branchEdgeCasesProvider(): array
    {
        return [
            'branch with slash' => [
                'feat/super-power-feature',
                'dev-feat/super-power-feature',
                'vendor/edge-pkg',
            ],
            'version range 3.x' => [
                '3.x',
                '3.0.0',
                'vendor/edge-pkg',
            ],
            'branch with dots' => [
                'my.new-feat',
                'dev-my.new-feat',
                'vendor/edge-pkg',
            ],
        ];
    }

    #[DataProvider('branchEdgeCasesProvider')]
    #[TestDox('execute() with --branch handles edge-case branch names')]
    public function testExecuteBranchHandlesEdgeCaseNames(string $branch, string $matchingVersion, string $packageName): void
    {
        $outputDir = $this->makeOutputDir();

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                [
                    'type' => 'package',
                    'package' => [
                        ['name' => $packageName, 'version' => '1.0.0'],
                        ['name' => $packageName, 'version' => $matchingVersion],
                        ['name' => $packageName, 'version' => '2.0.0'],
                        ['name' => 'vendor/other-pkg', 'version' => '1.0.0'],
                    ],
                ],
            ],
            'require-all' => true,
            'output-html' => false,
        ];

        $this->executeSatis([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
        ], $config);

        $this->writeConfig($config);
        $tester = $this->createSatisTester();
        $status = $tester->execute([
            'file' => $this->configPath,
            'output-dir' => $outputDir,
            '--branch' => $branch,
            'packages' => [$packageName],
        ]);

        self::assertSame(0, $status);

        $packagesAfter = json_decode((string) file_get_contents($outputDir . '/packages.json'), true);
        self::assertIsArray($packagesAfter);
        self::assertContains($packageName, $packagesAfter['available-packages']);
        self::assertContains('vendor/other-pkg', $packagesAfter['available-packages']);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function dataRearchive(): array
    {
        return [
            'rearchived' => [true],
            'not rearchived' => [false],
        ];
    }

    #[DataProvider('dataRearchive')]
    #[TestDox('execute() publishes a new archive when an artifact is replaced under the same version')]
    public function testExecuteRebuildsArchiveOfReplacedArtifact(bool $rearchive): void
    {
        $dir = sys_get_temp_dir() . '/satis-artifact-test-' . uniqid();
        $artifact = $dir . '/artifacts/acme-artifact-1.0.0.zip';
        $outputDir = $dir . '/output';
        new SymfonyFilesystem()->mkdir([$dir . '/artifacts', $outputDir]);
        // Writing a new packages.json triggers a warning, as Composer stats it to keep the owner of a replaced file
        file_put_contents($outputDir . '/packages.json', '{}');

        $config = [
            'name' => 'test/satis-repo',
            'homepage' => 'https://example.com',
            'repositories' => [
                ['type' => 'artifact', 'url' => $dir . '/artifacts'],
            ],
            'require-all' => true,
            'output-html' => false,
            'config' => ['cache-dir' => $dir . '/cache'],
            'archive' => ['directory' => 'dist', 'format' => 'zip', 'rearchive' => $rearchive],
        ];

        try {
            $this->createArtifact($artifact, 'first build');
            $first = $this->buildArtifactDist($config, $outputDir);

            $this->createArtifact($artifact, 'second build');
            $second = $this->buildArtifactDist($config, $outputDir);

            self::assertNotSame($first['url'], $second['url']);
            self::assertFileExists($first['path'], 'The previous archive is kept for existing lock files');
            self::assertSame(sha1_file($second['path']), $second['shasum']);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($second['path']));
            self::assertSame('second build', $zip->getFromName('README.md'));
            $zip->close();
        } finally {
            new SymfonyFilesystem()->remove($dir);
        }
    }

    private function createArtifact(string $path, string $readme): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('composer.json', (string) json_encode(['name' => 'acme/artifact', 'version' => '1.0.0']));
        $zip->addFromString('README.md', $readme);
        $zip->close();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{url: string, path: string, shasum: string}
     */
    private function buildArtifactDist(array $config, string $outputDir): array
    {
        $tester = $this->executeSatis(['file' => $this->configPath, 'output-dir' => $outputDir], $config);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $metadata = json_decode((string) file_get_contents($outputDir . '/p2/acme/artifact.json'), true);
        self::assertIsArray($metadata);
        $dist = $metadata['packages']['acme/artifact'][0]['dist'];
        $url = (string) $dist['url'];

        return [
            'url' => $url,
            'path' => $outputDir . '/' . substr($url, strlen('https://example.com/')),
            'shasum' => (string) $dist['shasum'],
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function dataHtmlOnlyIncludes(): array
    {
        return [
            'with includes' => [true],
            'without includes (p2 metadata)' => [false],
        ];
    }

    #[DataProvider('dataHtmlOnlyIncludes')]
    #[TestDox('execute() with --html-only regenerates index.html from the previous build without scanning repositories')]
    public function testExecuteWithHtmlOnlyRebuildsWebViewFromPreviousBuild(bool $includes): void
    {
        $outputDir = $this->makeOutputDir();
        $config = $this->baseSatisConfig(['includes' => $includes]);

        $tester = $this->executeSatis(['file' => $this->configPath, 'output-dir' => $outputDir], $config);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($outputDir . '/index.html');

        // Backdate everything the first build wrote, so any rewrite shows up as a changed mtime
        $before = [];
        foreach ($this->listFiles($outputDir) as $path) {
            touch($path, 1000000000);
            clearstatcache();
            $before[$path] = [(string) file_get_contents($path), filemtime($path)];
        }

        $templateDir = vfsStream::url('satis/templates');
        mkdir($templateDir);
        file_put_contents(
            $templateDir . '/custom.html.twig',
            'CUSTOM TEMPLATE {{ name }}:{% for packageName, versions in packages %} {{ packageName }}{% endfor %}'
        );

        // The repository of the first build is gone: a scan would fail, while --html-only must not touch it
        $config['repositories'] = [['type' => 'vcs', 'url' => vfsStream::url('satis/missing-repository')]];
        $config['twig-template'] = $templateDir . '/custom.html.twig';

        $tester = $this->executeSatis(
            ['file' => $this->configPath, 'output-dir' => $outputDir, '--html-only' => true],
            $config
        );

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(
            'CUSTOM TEMPLATE test/satis-repo: vendor/test-pkg',
            file_get_contents($outputDir . '/index.html')
        );

        clearstatcache();
        foreach ($before as $path => [$contents, $mtime]) {
            if ($outputDir . '/index.html' === $path) {
                continue;
            }
            self::assertSame($contents, file_get_contents($path), $path . ' was rewritten');
            self::assertSame($mtime, filemtime($path), $path . ' was rewritten');
        }
        self::assertSame(array_keys($before), $this->listFiles($outputDir), 'Files were added or removed');
    }

    #[TestDox('execute() with --html-only builds index.html even when output-html is false')]
    public function testExecuteWithHtmlOnlyIgnoresOutputHtmlConfig(): void
    {
        $outputDir = $this->makeOutputDir();
        $config = $this->baseSatisConfig(['output-html' => false]);

        $this->executeSatis(['file' => $this->configPath, 'output-dir' => $outputDir], $config);
        self::assertFileDoesNotExist($outputDir . '/index.html');

        $tester = $this->executeSatis(
            ['file' => $this->configPath, 'output-dir' => $outputDir, '--html-only' => true],
            $config
        );

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertFileExists($outputDir . '/index.html');
    }

    #[TestDox('execute() with --html-only fails when there is no previous build output')]
    public function testExecuteWithHtmlOnlyFailsWithoutPreviousBuild(): void
    {
        $outputDir = $this->makeOutputDir();

        $tester = $this->executeSatis(
            ['file' => $this->configPath, 'output-dir' => $outputDir, '--html-only' => true],
            $this->baseSatisConfig()
        );

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No packages found in a previous build', $tester->getDisplay());
        self::assertFileDoesNotExist($outputDir . '/index.html');
        self::assertFileDoesNotExist($outputDir . '/packages.json');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function dataHtmlOnlyConflicts(): array
    {
        return [
            '--no-html-output' => [['--no-html-output' => true], '"--no-html-output"'],
            'package arguments' => [['packages' => ['vendor/test-pkg']], 'package arguments'],
            '--repository-url' => [['--repository-url' => ['https://example.com/repo.git']], '"--repository-url"'],
            '--branch' => [['--branch' => 'main'], '"--branch"'],
            '--strict' => [['--strict' => true], '"--strict"'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('dataHtmlOnlyConflicts')]
    #[TestDox('execute() rejects --html-only combined with $_dataName')]
    public function testExecuteWithHtmlOnlyRejectsConflictingOptions(array $options, string $conflict): void
    {
        $outputDir = $this->makeOutputDir();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The "--html-only" option cannot be used together with %s.', $conflict));

        $this->executeSatis(
            ['file' => $this->configPath, 'output-dir' => $outputDir, '--html-only' => true] + $options,
            $this->baseSatisConfig()
        );
    }

    /**
     * @return list<string>
     */
    private function listFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }
}
