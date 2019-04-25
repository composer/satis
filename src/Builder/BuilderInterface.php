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

namespace Composer\Satis\Builder;

use Composer\Package\PackageInterface;

interface BuilderInterface
{
    /**
     * Dumps the given packages.
     *
     * @param PackageInterface[] $packages List of packages to dump
     */
    public function dump(array $packages);
}
