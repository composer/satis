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

use Composer\Config;
use Composer\Console\Application;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonValidationException;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Satis\Console\Application as SatisApplication;
use Composer\Util\ProcessExecutor;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;

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

    private function invokeDeduplicateRepositories(RepositoryManager $manager, ?\Symfony\Component\Console\Output\OutputInterface $output = null, bool $verbose = false): void
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
    private function invokeRemoveDisabledRepositories(RepositoryManager $manager, array $disabledRepoNames, ?\Symfony\Component\Console\Output\OutputInterface $output = null, bool $verbose = false): void
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
}
