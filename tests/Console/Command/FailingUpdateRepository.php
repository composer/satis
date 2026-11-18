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

use Composer\IO\IOInterface;
use Composer\Repository\ArrayRepository;

/**
 * Writes the warning of a VCS driver whose mirror failed to update, without touching the network.
 */
class FailingUpdateRepository extends ArrayRepository
{
    /**
     * @param array{url: string} $repoConfig
     */
    public function __construct(array $repoConfig, IOInterface $io)
    {
        parent::__construct();

        $io->writeError('<error>Failed to update ' . $repoConfig['url'] . ', package information from this repository may be outdated</error>');
    }
}
