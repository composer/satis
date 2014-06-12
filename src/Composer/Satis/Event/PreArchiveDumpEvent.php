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

namespace Composer\Satis\Event;

use Composer\EventDispatcher;

/**
 * The pre archive dump event.
 *
 * Adds information about path to current extracted package source before it is archived.
 */
class PreArchiveDumpEvent extends EventDispatcher\Event implements EventDispatcher\FolderEventInterface
{
    /**
     * @var string
     */
    private $packageSourcePath;

    /**
     * Constructor.
     *
     * @param string           $name         The event name
     * @param string           $packageSourcePath
     */
    public function __construct($name, $packageSourcePath)
    {
        parent::__construct($name);
        $this->packageSourcePath = $packageSourcePath;
    }

    /**
     * Returns the package package source path as current working directory.
     *
     * @return string
     */
    public function getCurrentWorkingDirectory()
    {
        return $this->packageSourcePath;
    }
}
