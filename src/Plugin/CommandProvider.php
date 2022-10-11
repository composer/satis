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

/**
 * Register commands for the Composer CLI
 */
class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return [
            new AddCommand('satis:add'),
            new BuildCommand('satis:build'),
            new InitCommand('satis:init'),
            new PurgeCommand('satis:purge'),
        ];
    }
}
