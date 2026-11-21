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

#[TestDox('InitCommand')]
class InitCommandTest extends TestCase
{
    private const HOMEPAGE = 'https://packages.example.org';

    private string $configPath;

    protected function setUp(): void
    {
        vfsStream::setup('satis');
        $this->configPath = vfsStream::url('satis/satis.json');
    }

    private function createTester(): CommandTester
    {
        $app = new Application();
        $app->addCommand(new InitCommand());

        return new CommandTester($app->find('init'));
    }

    #[TestDox('Rejects a repository name that is not a valid package name')]
    public function testRejectsInvalidName(): void
    {
        $tester = $this->createTester();
        $tester->execute(
            ['file' => $this->configPath, '--name' => 'My Repository', '--homepage' => self::HOMEPAGE],
            ['interactive' => false]
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('My Repository is invalid', $tester->getDisplay());
        self::assertFileDoesNotExist($this->configPath);
    }

    #[TestDox('Writes the config with a valid repository name')]
    public function testWritesConfigWithValidName(): void
    {
        $tester = $this->createTester();
        $tester->execute(
            ['file' => $this->configPath, '--name' => 'acme/repo', '--homepage' => self::HOMEPAGE],
            ['interactive' => false]
        );

        self::assertSame(0, $tester->getStatusCode());

        $contents = file_get_contents($this->configPath);
        self::assertIsString($contents);
        $config = json_decode($contents, true);
        self::assertSame('acme/repo', $config['name']);
        self::assertSame(self::HOMEPAGE, $config['homepage']);
    }

    #[TestDox('Asks again for the repository name when the answer is not a valid package name')]
    public function testAsksAgainForInvalidName(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['My Repository', 'acme/repo', self::HOMEPAGE]);
        $tester->execute(['file' => $this->configPath]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('My Repository is invalid', $tester->getDisplay());

        $contents = file_get_contents($this->configPath);
        self::assertIsString($contents);
        $config = json_decode($contents, true);
        self::assertSame('acme/repo', $config['name']);
    }
}
