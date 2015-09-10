<?php

namespace Composer\Satis\Provider;

interface ProviderInterface
{
    /**
     * @param string $organisation
     *
     * @return Repository[]
     */
    public function getRepositories($organisation);
}
