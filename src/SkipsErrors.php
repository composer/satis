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

namespace Composer\Satis;

/**
 * Rethrows an exception, or skips it with --skip-errors, recording its message for `build --strict`.
 *
 * Requires the using class to have an `OutputInterface $output` and a `bool $skipErrors` property.
 */
trait SkipsErrors
{
    /** @var array<string, true> The messages of the skipped exceptions */
    private array $skippedErrors = [];

    /**
     * The messages of the exceptions skipped with --skip-errors, without duplicates.
     *
     * @return list<string>
     */
    public function getSkippedErrors(): array
    {
        return array_map('strval', array_keys($this->skippedErrors));
    }

    /**
     * @throws \Exception the given exception, unless errors are skipped
     */
    protected function skipError(\Exception $exception): void
    {
        if (!$this->skipErrors) {
            throw $exception;
        }

        $this->skippedErrors[$exception->getMessage()] = true;
        $this->output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
    }
}
