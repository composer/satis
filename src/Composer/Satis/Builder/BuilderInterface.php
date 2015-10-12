<?php

/*
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis\Builder;

/**
 * Builder interface.
 *
 * @author James Hautot <james@rezo.net>
 */
interface BuilderInterface
{
    public function dump(array $packages);
}
