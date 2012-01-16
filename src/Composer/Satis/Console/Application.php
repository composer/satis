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

namespace Composer\Satis\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Composer\Satis\Command;
use Composer\Console\Application as ComposerApplication;
use Composer\Json\JsonFile;
use Composer\Satis\Satis;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Application extends BaseApplication
{
    protected $composer;

    public function __construct()
    {
        parent::__construct('Satis', Satis::VERSION);
    }

    /**
     * {@inheritDoc}
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $this->registerCommands();

        return parent::doRun($input, $output);
    }

    /**
     * Initializes all the composer commands
     */
    protected function registerCommands()
    {
        $this->add(new Command\BuildCommand());
    }
}
