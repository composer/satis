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

namespace Composer\Satis\PackageSelection;

use Composer\Config;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\PackageInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class PackageSelectionTagFilterTest extends TestCase
{
    private const PACKAGE_NAME = 'vendor/package';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/satis-tag-filter-' . uniqid('', true);
        (new Filesystem())->ensureDirectoryExists($this->workDir . '/repository');

        $this->git('init');
        // Instead of --initial-branch, which requires Git 2.28
        $this->git('symbolic-ref', 'HEAD', 'refs/heads/main');
        foreach (['v1.0.0', 'v2.0.0'] as $tag) {
            $this->commitComposerJson(['name' => self::PACKAGE_NAME, 'description' => $tag]);
            $this->git('tag', $tag);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->workDir);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function dataReadTags(): array
    {
        return [
            'filter disabled' => [[], ['v1.0.0', 'v2.0.0']],
            'filter enabled' => [['filter-tags-by-require' => true], ['v2.0.0']],
            'filter enabled with require-dependencies' => [['filter-tags-by-require' => true, 'require-dependencies' => true], ['v1.0.0', 'v2.0.0']],
            'filter enabled with inline alias' => [['filter-tags-by-require' => true, 'require' => [self::PACKAGE_NAME => '2.0.0 as 3.0.0']], ['v1.0.0', 'v2.0.0']],
        ];
    }

    /**
     * @param array<string, mixed> $extraConfig
     * @param list<string> $expectedReadTags
     */
    #[DataProvider('dataReadTags')]
    public function testReadTags(array $extraConfig, array $expectedReadTags): void
    {
        [$selected, $output] = $this->select($extraConfig);

        self::assertSame([self::PACKAGE_NAME . '-2.0.0.0'], array_keys($selected));
        $this->assertReadTags($expectedReadTags, $output);
        // Branches are never skipped
        self::assertStringContainsString(sprintf('Reading composer.json of %s (main)', self::PACKAGE_NAME), $output);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function dataDefaultBranchWithoutComposerJson(): array
    {
        return [
            'matching tag' => ['^2.0', [self::PACKAGE_NAME . '-2.0.0.0']],
            'no matching tag' => ['^3.0', []],
        ];
    }

    /**
     * Without a composer.json on the default branch, the tags may contain other packages, so all tags are read.
     *
     * @param list<string> $expectedSelected
     */
    #[DataProvider('dataDefaultBranchWithoutComposerJson')]
    public function testDefaultBranchWithoutComposerJsonReadsAllTags(string $constraint, array $expectedSelected): void
    {
        $this->git('rm', '--quiet', 'composer.json');
        $this->git('commit', '--message', 'Remove composer.json from the default branch');

        [$selected, $output] = $this->select(['filter-tags-by-require' => true, 'require' => [self::PACKAGE_NAME => $constraint]]);

        self::assertSame($expectedSelected, array_keys($selected));
        $this->assertReadTags(['v1.0.0', 'v2.0.0'], $output);
    }

    public function testTagsOfOtherPackagesAreKept(): void
    {
        $this->git('tag', '--delete', 'v1.0.0');
        $this->git('checkout', '--quiet', 'v2.0.0~1');
        $this->commitComposerJson(['name' => 'vendor/old-package']);
        $this->git('tag', 'v1.0.0');
        $this->git('checkout', '--quiet', 'main');
        $this->git('rm', '--quiet', 'composer.json');
        $this->git('commit', '--message', 'Remove composer.json from the default branch');

        [$selected] = $this->select([
            'filter-tags-by-require' => true,
            'require' => ['vendor/old-package' => '^1.0', self::PACKAGE_NAME => '^2.0'],
        ]);

        self::assertSame(['vendor/old-package-1.0.0.0', self::PACKAGE_NAME . '-2.0.0.0'], array_keys($selected));
    }

    public function testNoPackageLeftAfterSkippingTags(): void
    {
        // An invalid constraint makes the repository skip the default branch
        $this->commitComposerJson(['name' => self::PACKAGE_NAME, 'require' => ['vendor/other' => 'invalid']]);

        [$selected, $output] = $this->select(['filter-tags-by-require' => true, 'require' => [self::PACKAGE_NAME => '^3.0']]);

        self::assertSame([], $selected);
        $this->assertReadTags([], $output);
    }

    public function testUnusedRepositoryIsNotRead(): void
    {
        [$selected] = $this->select([
            'filter-tags-by-require' => true,
            'repositories' => [
                ['type' => 'vcs', 'url' => $this->workDir . '/repository', 'name' => self::PACKAGE_NAME],
                ['type' => 'vcs', 'url' => $this->workDir . '/missing-repository', 'name' => self::PACKAGE_NAME],
            ],
        ]);

        self::assertSame([self::PACKAGE_NAME . '-2.0.0.0'], array_keys($selected));
    }

    public function testRepositoryWithoutComposerJsonStillFails(): void
    {
        $this->git('rm', '--quiet', 'composer.json');
        $this->git('commit', '--message', 'Remove composer.json from the branch');
        $this->git('tag', '--delete', 'v1.0.0', 'v2.0.0');

        $this->expectException(InvalidRepositoryException::class);

        $this->select(['filter-tags-by-require' => true]);
    }

    /**
     * @param array<string, mixed> $extraConfig
     *
     * @return array{array<string, PackageInterface>, string} The selected packages and the output of the repositories
     */
    private function select(array $extraConfig): array
    {
        $config = $extraConfig + [
            'name' => 'test/satis',
            'homepage' => 'http://localhost',
            'repositories' => [['type' => 'vcs', 'url' => $this->workDir . '/repository', 'name' => self::PACKAGE_NAME]],
            'require' => [self::PACKAGE_NAME => '^2.0'],
            'config' => ['cache-dir' => $this->workDir . '/cache'],
        ];

        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE);
        $composer = (new Factory())->createComposer($io, $config, true, null, false);

        $selection = new PackageSelection(new NullOutput(), $this->workDir . '/build', $config, false, $io);

        return [$selection->select($composer, false), $io->getOutput()];
    }

    /**
     * @param list<string> $expectedReadTags
     */
    private function assertReadTags(array $expectedReadTags, string $output): void
    {
        foreach (['v1.0.0', 'v2.0.0'] as $tag) {
            $message = sprintf('Importing tag %s (', $tag);
            if (in_array($tag, $expectedReadTags, true)) {
                self::assertStringContainsString($message, $output);
            } else {
                self::assertStringNotContainsString($message, $output);
            }
        }
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    private function commitComposerJson(array $composerJson): void
    {
        file_put_contents($this->workDir . '/repository/composer.json', json_encode($composerJson, \JSON_THROW_ON_ERROR));
        $this->git('add', 'composer.json');
        $this->git('commit', '--message', 'Update composer.json');
    }

    private function git(string ...$arguments): void
    {
        // Identity of the test repository, independent of the local Git config
        $process = new Process(
            ['git', '-c', 'user.name=Satis', '-c', 'user.email=satis@example.com', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments],
            $this->workDir . '/repository'
        );
        $process->mustRun();
    }
}
