<?php
namespace Composer\Satis\Composer;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;

/**
 * Class Factory
 *
 * \Composer\Factory was extended to add custom repository class
 *
 * @package Composer\Satis\Composer
 */
class Factory extends \Composer\Factory
{
    /**
     * Added additional required VCS type "vcs-namespace"
     *
     * @param IOInterface $io
     * @param Config $config
     * @param EventDispatcher $eventDispatcher
     * @return \Composer\Repository\RepositoryManager
     */
    protected function createRepositoryManager(IOInterface $io, Config $config, EventDispatcher $eventDispatcher = null)
    {
        $rm = parent::createRepositoryManager($io, $config, $eventDispatcher);
        $rm->setRepositoryClass('vcs-namespace', 'Composer\Satis\Repository\VcsNamespaceRepository');
        return $rm;
    }
}