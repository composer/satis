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

use Symfony\Component\Console\Output\OutputInterface;

abstract class Builder implements BuilderInterface
{
    /** @var OutputInterface $output The output Interface. */
    protected $output;
    /** @var string $outputDir The directory where to build. */
    protected $outputDir;
    /** @var array $config The parameters from ./satis.json. */
    protected $config;
    /** @var bool $skipErrors Skips Exceptions if true. */
    protected $skipErrors;

    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors)
    {
        $this->output = $output;
        $this->outputDir = $outputDir;
        $this->config = $config;
        $this->skipErrors = $skipErrors;
    }
}
