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

namespace Composer\Satis\Console\IO;

use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('FailedUpdateRecordingIO')]
class FailedUpdateRecordingIOTest extends TestCase
{
    /**
     * The warnings Composer's VCS drivers write when a mirror cannot be updated.
     *
     * @return array<string, array{string, string}>
     */
    public static function dataFailedUpdateWarnings(): array
    {
        return [
            'git' => [
                '<error>Failed to update https://example.com/repo.git, package information from this repository may be outdated</error>',
                'Failed to update https://example.com/repo.git, package information from this repository may be outdated',
            ],
            'hg and fossil' => [
                '<error>Failed to update https://example.com/repo, package information from this repository may be outdated (abort: error: Connection refused)</error>',
                'Failed to update https://example.com/repo, package information from this repository may be outdated (abort: error: Connection refused)',
            ],
        ];
    }

    #[DataProvider('dataFailedUpdateWarnings')]
    #[TestDox('records the "Failed to update" warning of $_dataName')]
    public function testRecordsFailedUpdateWarnings(string $message, string $expected): void
    {
        $io = new FailedUpdateRecordingIO(new BufferIO());

        $io->writeError($message);

        self::assertSame([$expected], $io->getFailedUpdates());
    }

    #[TestDox('records "Failed to update" warnings written as an array of messages')]
    public function testRecordsFailedUpdateWarningsFromArrays(): void
    {
        $io = new FailedUpdateRecordingIO(new BufferIO());

        $io->writeError([
            'Some other line',
            '<error>Failed to update https://example.com/a.git, package information from this repository may be outdated</error>',
        ]);
        $io->writeError('<error>Failed to update https://example.com/b.git, package information from this repository may be outdated</error>');

        self::assertSame([
            'Failed to update https://example.com/a.git, package information from this repository may be outdated',
            'Failed to update https://example.com/b.git, package information from this repository may be outdated',
        ], $io->getFailedUpdates());
    }

    #[TestDox('ignores other messages')]
    public function testIgnoresOtherMessages(): void
    {
        $io = new FailedUpdateRecordingIO(new BufferIO());

        $io->writeError('<warning>Something else went wrong</warning>');
        $io->writeError('<error>Failed to clone https://example.com/repo.git</error>');
        $io->write('<error>Failed to update https://example.com/repo.git, package information from this repository may be outdated</error>');

        self::assertSame([], $io->getFailedUpdates());
    }

    #[TestDox('forwards output to the decorated IO')]
    public function testForwardsOutput(): void
    {
        $inner = new BufferIO();
        $io = new FailedUpdateRecordingIO($inner);

        $io->write('to stdout');
        $io->writeError('<error>Failed to update https://example.com/repo.git, package information from this repository may be outdated</error>');

        $output = $inner->getOutput();
        self::assertStringContainsString('to stdout', $output);
        self::assertStringContainsString('Failed to update https://example.com/repo.git', $output);
    }

    #[TestDox('forwards authentications to the decorated IO')]
    public function testForwardsAuthentications(): void
    {
        $inner = new BufferIO();
        $io = new FailedUpdateRecordingIO($inner);

        $io->setAuthentication('example.com', 'user', 'secret');

        self::assertTrue($inner->hasAuthentication('example.com'));
        self::assertSame(['username' => 'user', 'password' => 'secret'], $io->getAuthentication('example.com'));
        self::assertSame($inner->getAuthentications(), $io->getAuthentications());
    }
}
