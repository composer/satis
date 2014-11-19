<?php

namespace Composer\Satis\Provider;

use Composer\Composer;
use Github\Client;
use Symfony\Component\Console\Input\InputInterface;

class ProviderFactory
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @param Composer $composer
     */
    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    /**
     *
     * @param InputInterface  $input
     *
     * @throws UnknownProviderException
     * @return ProviderInterface
     */
    public function create($name, InputInterface $input)
    {
        switch ($name) {
            case 'github':
                $client = new Client();

                $token = null;
                if ($input->getOption('auth') !== null) {
                    $token = $input->getOption('auth');
                } elseif ($this->composer->getConfig()->has('github-oauth')) {
                    $auth = $this->composer->getConfig()->get('github-oauth');
                    if (isset($auth['github.com'])) {
                        $token = $auth['github.com'];
                    }
                }

                return new GithubProvider($client, $token);
                break;
            default:
                throw new UnknownProviderException(sprintf('Failed to load provider named: %s', $name));
        }
    }
} 
