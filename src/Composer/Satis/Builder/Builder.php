<?php

/**
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Composer\Satis\Builder;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base class for Satis Builders.
 *
 * @author James Hautot <james@rezo.net>
 */
class Builder
{
    /** @var OutputInterface $output The output Interface. */
    protected $output;

    /** @var string $outputDir The directory where to build. */
    protected $outputDir;

    /** @var array $config The parameters from ./satis.json. */
    protected $config;

    /**
     * Base Constructor.
     *
     * @param OutputInterface $output    The output Interface
     * @param string          $outputDir The directory where to build
     * @param array           $config    The parameters from ./satis.json
     */
    public function __construct(OutputInterface $output, $outputDir, $config)
    {
        $this->output = $output;
        $this->outputDir = $outputDir;
        $this->config = $config;
    }
}
