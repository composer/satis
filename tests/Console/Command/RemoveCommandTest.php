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
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[TestDox('RemoveCommand')]
class RemoveCommandTest extends TestCase
{
    private const REPOSITORY_URL = 'https://github.com/foo/bar.git';
    private const OTHER_REPOSITORY_URL = 'https://github.com/existing/repo.git';

    private string $configPath;

    protected function setUp(): void
    {
        vfsStream::setup('satis');
        $this->configPath = vfsStream::url('satis/satis.json');
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->addCommand(new RemoveCommand());

        return new CommandTester($app->find('remove'));
    }

    /** @param array<string, mixed> $config */
    private function writeConfig(array $config): void
    {
        file_put_contents(
            $this->configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array<string, mixed> */
    private function readConfig(): array
    {
        $contents = file_get_contents($this->configPath);
        self::assertIsString($contents);

        return json_decode($contents, true);
    }

    #[TestDox('Rejects an HTTP URL as the config file path')]
    public function testRejectsHttpConfigFile(): void
    {
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => 'http://example.com/satis.json']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Unable to write to remote file', $tester->getDisplay());
    }

    #[TestDox('Rejects an HTTPS URL as the config file path')]
    public function testRejectsHttpsConfigFile(): void
    {
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => 'https://example.com/satis.json']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Unable to write to remote file', $tester->getDisplay());
    }

    #[TestDox('Rejects a config file that does not exist')]
    public function testRejectsNonExistentFile(): void
    {
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => vfsStream::url('satis/nonexistent.json')]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('File not found', $tester->getDisplay());
    }

    #[TestDox('Rejects a repository URL that is not in the config')]
    public function testRejectsUnknownUrl(): void
    {
        $this->writeConfig([
            'name' => 'test/repo',
            'repositories' => [
                ['type' => 'vcs', 'url' => self::OTHER_REPOSITORY_URL],
            ],
        ]);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(4, $tester->getStatusCode());
        self::assertStringContainsString('Repository url not found in the file', $tester->getDisplay());

        $config = $this->readConfig();
        self::assertCount(1, $config['repositories']);
    }

    #[TestDox('Rejects a repository URL when the config has no repositories at all')]
    public function testRejectsUnknownUrlWithoutRepositoriesKey(): void
    {
        $this->writeConfig(['name' => 'test/repo']);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(4, $tester->getStatusCode());
        self::assertStringContainsString('Repository url not found in the file', $tester->getDisplay());
    }

    #[TestDox('Rejects a repository URL when the repositories value is not an array')]
    public function testRejectsUnknownUrlWithNonArrayRepositories(): void
    {
        $this->writeConfig(['name' => 'test/repo', 'repositories' => 'invalid']);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(4, $tester->getStatusCode());
        self::assertStringContainsString('Repository url not found in the file', $tester->getDisplay());
    }

    #[TestDox('Removes the only repository from the config')]
    public function testRemoveOnlyRepository(): void
    {
        $this->writeConfig([
            'name' => 'test/repo',
            'repositories' => [
                ['type' => 'vcs', 'url' => self::REPOSITORY_URL],
            ],
        ]);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('successfully updated', $tester->getDisplay());

        $config = $this->readConfig();
        self::assertSame([], $config['repositories']);
    }

    #[TestDox('Preserves the other repositories and reindexes the list')]
    public function testRemovePreservesOtherRepositories(): void
    {
        $this->writeConfig([
            'name' => 'test/repo',
            'repositories' => [
                ['type' => 'vcs', 'url' => self::REPOSITORY_URL],
                ['type' => 'vcs', 'url' => self::OTHER_REPOSITORY_URL],
            ],
        ]);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(0, $tester->getStatusCode());

        $config = $this->readConfig();
        // Reindexed, so the remaining repository is at offset 0 and the list is
        // still encoded as a JSON array rather than an object.
        self::assertSame([0], array_keys($config['repositories']));
        self::assertSame(self::OTHER_REPOSITORY_URL, $config['repositories'][0]['url']);
    }

    #[TestDox('Removes every entry sharing the same URL')]
    public function testRemoveEveryEntryWithTheSameUrl(): void
    {
        $this->writeConfig([
            'name' => 'test/repo',
            'repositories' => [
                ['type' => 'vcs', 'url' => self::REPOSITORY_URL, 'name' => 'foo/bar'],
                ['type' => 'vcs', 'url' => self::REPOSITORY_URL],
                ['type' => 'vcs', 'url' => self::OTHER_REPOSITORY_URL],
            ],
        ]);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(0, $tester->getStatusCode());

        $config = $this->readConfig();
        self::assertCount(1, $config['repositories']);
        self::assertSame(self::OTHER_REPOSITORY_URL, $config['repositories'][0]['url']);
    }

    #[TestDox('Leaves the rest of the configuration untouched')]
    public function testRemoveKeepsOtherConfigKeys(): void
    {
        $this->writeConfig([
            'name' => 'test/repo',
            'homepage' => 'https://packages.example.org',
            'require-all' => true,
            'repositories' => [
                ['type' => 'vcs', 'url' => self::REPOSITORY_URL],
            ],
        ]);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(0, $tester->getStatusCode());

        $config = $this->readConfig();
        self::assertSame('test/repo', $config['name']);
        self::assertSame('https://packages.example.org', $config['homepage']);
        self::assertTrue($config['require-all']);
    }

    #[TestDox('Keeps repository entries that have no url')]
    public function testRemoveKeepsRepositoriesWithoutUrl(): void
    {
        $this->writeConfig([
            'name' => 'test/repo',
            'repositories' => [
                ['type' => 'package', 'package' => ['name' => 'foo/bar', 'version' => '1.0.0']],
                ['type' => 'vcs', 'url' => self::REPOSITORY_URL],
            ],
        ]);
        $tester = $this->createTester();
        $tester->execute(['url' => self::REPOSITORY_URL, 'file' => $this->configPath]);

        self::assertSame(0, $tester->getStatusCode());

        $config = $this->readConfig();
        self::assertCount(1, $config['repositories']);
        self::assertSame('package', $config['repositories'][0]['type']);
    }
}
