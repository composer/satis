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

namespace Composer\Satis\Console;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Satis\Console\Command;
use Composer\Satis\Satis;
use Composer\Util\ErrorHandler;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends BaseApplication
{
    /** @var IOInterface */
    protected $io;
    /** @var Composer */
    protected $composer;

    public function __construct()
    {
        parent::__construct('Satis', Satis::VERSION);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $styles = Factory::createAdditionalStyles();
        foreach ($styles as $name => $style) {
            $output->getFormatter()->setStyle($name, $style);
        }

        $this->io = new ConsoleIO($input, $output, $this->getHelperSet());
        ErrorHandler::register($this->io);

        return parent::doRun($input, $output);
    }

    /**
     * @param bool $required
     * @param array|string|null $config
     *  Either a configuration array or a filename to read from, if null it will read from the default filename
     *
     * @return Composer
     */
    public function getComposer(bool $required = true, $config = null): Composer
    {
        if (null === $this->composer) {
            try {
                $this->composer = Factory::create($this->io, $config);
            } catch (\InvalidArgumentException $e) {
                $this->io->write($e->getMessage());
                exit(1);
            }
        }

        return $this->composer;
    }

    protected function getDefaultCommands(): array
    {
        $commands = array_merge(parent::getDefaultCommands(), [
            new Command\InitCommand(),
            new Command\AddCommand(),
            new Command\BuildCommand(),
            new Command\PurgeCommand(),
        ]);

        return $commands;
    }
}
