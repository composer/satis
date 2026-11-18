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

namespace Composer\Satis\Console\IO;

use Composer\Config;
use Composer\IO\IOInterface;

/**
 * Decorates an IO and records the warnings Composer's VCS drivers write when a mirror fails to update.
 *
 * The git, hg and fossil drivers do not throw when updating their cached mirror fails; they write
 * "Failed to update <url>, package information from this repository may be outdated" and carry on
 * with the cached mirror. Recording those warnings lets `build --strict` fail the build.
 */
class FailedUpdateRecordingIO implements IOInterface
{
    private const FAILED_UPDATE_PREFIX = 'Failed to update ';

    /** @var array<string, true> */
    private array $failedUpdates = [];

    public function __construct(private readonly IOInterface $io)
    {
    }

    /**
     * @return list<string> the recorded warnings, without formatting tags
     */
    public function getFailedUpdates(): array
    {
        return array_keys($this->failedUpdates);
    }

    public function isInteractive()
    {
        return $this->io->isInteractive();
    }

    public function isVerbose()
    {
        return $this->io->isVerbose();
    }

    public function isVeryVerbose()
    {
        return $this->io->isVeryVerbose();
    }

    public function isDebug()
    {
        return $this->io->isDebug();
    }

    public function isDecorated()
    {
        return $this->io->isDecorated();
    }

    public function write($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->io->write($messages, $newline, $verbosity);
    }

    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        foreach ((array) $messages as $message) {
            $message = strip_tags($message);
            if (str_starts_with($message, self::FAILED_UPDATE_PREFIX)) {
                $this->failedUpdates[$message] = true;
            }
        }

        $this->io->writeError($messages, $newline, $verbosity);
    }

    public function writeRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->io->writeRaw($messages, $newline, $verbosity);
    }

    public function writeErrorRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $this->io->writeErrorRaw($messages, $newline, $verbosity);
    }

    public function overwrite($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $this->io->overwrite($messages, $newline, $size, $verbosity);
    }

    public function overwriteError($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $this->io->overwriteError($messages, $newline, $size, $verbosity);
    }

    public function ask(string $question, $default = null)
    {
        return $this->io->ask($question, $default);
    }

    public function askConfirmation(string $question, bool $default = true)
    {
        return $this->io->askConfirmation($question, $default);
    }

    public function askAndValidate(string $question, callable $validator, ?int $attempts = null, $default = null)
    {
        return $this->io->askAndValidate($question, $validator, $attempts, $default);
    }

    public function askAndHideAnswer(string $question)
    {
        return $this->io->askAndHideAnswer($question);
    }

    public function select(string $question, array $choices, $default, $attempts = false, string $errorMessage = 'Value "%s" is invalid', bool $multiselect = false)
    {
        return $this->io->select($question, $choices, $default, $attempts, $errorMessage, $multiselect);
    }

    public function getAuthentications()
    {
        return $this->io->getAuthentications();
    }

    public function hasAuthentication(string $repositoryName)
    {
        return $this->io->hasAuthentication($repositoryName);
    }

    public function getAuthentication(string $repositoryName)
    {
        return $this->io->getAuthentication($repositoryName);
    }

    public function setAuthentication(string $repositoryName, string $username, ?string $password = null)
    {
        $this->io->setAuthentication($repositoryName, $username, $password);
    }

    public function loadConfiguration(Config $config)
    {
        $this->io->loadConfiguration($config);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->io->emergency($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function alert($message, array $context = []): void
    {
        $this->io->alert($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function critical($message, array $context = []): void
    {
        $this->io->critical($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function error($message, array $context = []): void
    {
        $this->io->error($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function warning($message, array $context = []): void
    {
        $this->io->warning($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function notice($message, array $context = []): void
    {
        $this->io->notice($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function info($message, array $context = []): void
    {
        $this->io->info($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function debug($message, array $context = []): void
    {
        $this->io->debug($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param mixed[]           $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->io->log($level, $message, $context);
    }
}
