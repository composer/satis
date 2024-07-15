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
use Composer\Console\Application as ComposerApplication;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Satis\Console\Command;
use Composer\Satis\Satis;
use Composer\Util\ErrorHandler;
use Composer\Util\Platform;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends ComposerApplication
{
    /** @var IOInterface */
    protected $io;
    /** @var Composer|null */
    protected $composer;

    public function __construct()
    {
        // Composer constuctor does not allow passing BaseApplication::__construct('Satis', Satis::VERSION);
        parent::__construct();
        $this->setName('Satis');
        $this->setVersion(Satis::VERSION);
    }

    /**
     * Need to override composer's
     */
    public function __destruct()
    {
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

    protected function getDefaultCommands(): array
    {
        $commands = array_merge(BaseApplication::getDefaultCommands(), [
            new Command\InitCommand(),
            new Command\AddCommand(),
            new Command\BuildCommand(),
            new Command\PurgeCommand(),
        ]);

        return $commands;
    }

    public function getComposerWithConfig(mixed $config): ?Composer
    {
        if (null === $this->composer) {
            try {
                $this->composer = Factory::create(Platform::isInputCompletionProcess() ? new NullIO() : $this->io, $config, false, false);
            } catch (\InvalidArgumentException $e) {
                $this->io->writeError($e->getMessage());
                if ($this->areExceptionsCaught()) {
                    exit(1);
                }
                throw $e;
            }
        }

        return $this->composer;
    }
}
