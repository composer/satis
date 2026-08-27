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

namespace Composer\Satis\Plugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Satis\Console\Command\AddCommand;
use Composer\Satis\Console\Command\BuildCommand;
use Composer\Satis\Console\Command\InitCommand;
use Composer\Satis\Console\Command\PurgeCommand;
use Composer\Satis\Console\Command\RemoveCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox('CommandProvider')]
#[CoversClass(CommandProvider::class)]
class CommandProviderTest extends TestCase
{
    #[TestDox('Implements the CommandProviderCapability interface')]
    public function testImplementsCommandProviderCapability(): void
    {
        $provider = new CommandProvider();
        self::assertInstanceOf(CommandProviderCapability::class, $provider);
    }

    #[TestDox('Returns the expected commands in order')]
    public function testGetCommandsReturnsExpectedCommands(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();

        $expected = self::expectedCommands();

        self::assertCount(count($expected), $commands);

        foreach ($expected as $index => [$name, $class]) {
            self::assertSame($name, $commands[$index]->getName());
            self::assertInstanceOf($class, $commands[$index]);
        }
    }

    /**
     * @return list<array{0: string, 1: class-string}>
     */
    private static function expectedCommands(): array
    {
        return [
            ['satis:add', AddCommand::class],
            ['satis:build', BuildCommand::class],
            ['satis:init', InitCommand::class],
            ['satis:purge', PurgeCommand::class],
            ['satis:remove', RemoveCommand::class],
        ];
    }
}
