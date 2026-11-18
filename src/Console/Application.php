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
use Composer\Config;
use Composer\Console\Application as ComposerApplication;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
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
            new Command\RemoveCommand(),
        ]);

        return $commands;
    }

    /**
     * @param IOInterface|null $io The IO for a Composer instance that has yet to be created, defaults to the application IO
     */
    public function getComposerWithConfig(mixed $config, ?IOInterface $io = null): ?Composer
    {
        if (null === $this->composer) {
            try {
                $this->composer = Factory::create(Platform::isInputCompletionProcess() ? new NullIO() : ($io ?? $this->io), $config, false, false);
            } catch (\InvalidArgumentException $e) {
                $this->io->writeError($e->getMessage());
                if ($this->areExceptionsCaught()) {
                    exit(1);
                }
                throw $e;
            }
        } else {
            if (!is_array($config)) {
                throw new \InvalidArgumentException('"$config" parameter must be an array');
            }

            $this->composer->getConfig()->merge($config);
            // like Factory::createComposer() does over composer.json and its auth.json, let COMPOSER_AUTH win over
            // the Satis config and the auth.json next to it
            $this->loadComposerAuthEnv($this->composer->getConfig(), $io ?? $this->io);
        }

        return $this->composer;
    }

    /**
     * Merges COMPOSER_AUTH into the config, validated like Factory::loadComposerAuthEnv(), which is private.
     */
    private function loadComposerAuthEnv(Config $config, IOInterface $io): void
    {
        $composerAuthEnv = Platform::getEnv('COMPOSER_AUTH');
        if (false === $composerAuthEnv || '' === $composerAuthEnv) {
            return;
        }

        $authData = json_decode($composerAuthEnv);
        if (null === $authData) {
            throw new \UnexpectedValueException('COMPOSER_AUTH environment variable is malformed, should be a valid JSON object');
        }

        $io->writeError('Loading auth config from COMPOSER_AUTH', true, IOInterface::DEBUG);
        try {
            JsonFile::validateJsonSchema('COMPOSER_AUTH', $authData, JsonFile::AUTH_SCHEMA);
        } catch (JsonValidationException $e) {
            $io->writeError('<warning>' . $e->getMessage() . ', this may result in errors and should be resolved:' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $e->getErrors()) . '</>');
        }

        $authData = json_decode($composerAuthEnv, true);
        if (is_array($authData)) {
            $config->merge(['config' => $authData], 'COMPOSER_AUTH');
        }
    }
}
