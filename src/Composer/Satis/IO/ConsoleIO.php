<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis\IO;

use Composer\IO\ConsoleIO as BaseConsoleIO;

/**
 * Wrapper around Composer's IO
 *
 * This wrapper is used to prepend a custom prefix to all written messages.
 *
 * @author Christoph Mewes <christoph@webvariants.de>
 */
class ConsoleIO extends BaseConsoleIO
{
    protected $prefix = '';
    protected $internal = false;

    /**
     * Set the prefix.
     *
     * @param string $prefix The prefix to prepend, should include a trailing space
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }

    /**
     * Prepend the prefix to all messages.
     *
     * @param  string|array $messages The message as an array of lines or a single string
     * @return string|array
     */
    protected function prepend($messages)
    {
        if (is_array($messages)) {
            foreach ($messages as $idx => $message) {
                $messages[$idx] = $this->prefix.$message;
            }
        }
        else {
            $messages = $this->prefix.$messages;
        }

        return $messages;
    }

    /**
     * {@inheritDoc}
     */
    public function write($messages, $newline = true)
    {
        return parent::write($this->internal ? $messages : $this->prepend($messages), $newline);
    }

    /**
     * {@inheritDoc}
     */
    public function overwrite($messages, $newline = true, $size = null)
    {
        $this->internal = true;
        parent::overwrite($this->prepend($messages), $newline, $size);
        $this->internal = false;
    }
}
