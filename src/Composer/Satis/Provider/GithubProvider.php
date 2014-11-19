<?php

namespace Composer\Satis\Provider;

use Github\Client;

class GithubProvider implements ProviderInterface
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @param Client $client
     * @param null   $token
     */
    public function __construct(Client $client, $token = null)
    {
        $this->client = $client;

        if ($token !== null) {
            $this->client->authenticate($token, null, Client::AUTH_HTTP_TOKEN);
        }
    }

    /**
     * @param string $organisation
     *
     * @return Repository[]
     */
    public function getRepositories($organisation)
    {
        $repositories = array();

        foreach ($this->client->organizations()->setPerPage(100)->repositories($organisation, 'private') as $repository) {
            if (!$this->isComposerAware($repository)) {
                continue;
            }

            $repositories[] = new Repository($repository['full_name'], $repository['git_url']);
        }

        return $repositories;
    }

    /**
     * @param array $repository
     *
     * @return boolean
     */
    private function isComposerAware(array $repository)
    {
        return $this->client->repositories()->contents()->exists(
            $repository['owner']['login'],
            $repository['name'],
            'composer.json'
        );
    }
}
