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

        foreach ($this->client->api('organization')->setPerPage(100)->repositories($organisation, 'private') as $repository) {
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
     * @return array
     */
    protected function isComposerAware(array $repository)
    {
        return $this->hasComposerFile($repository['owner']['login'], $repository['name'], 'master')
            || $this->hasComposerFile($repository['owner']['login'], $repository['name'], 'develop');
    }

    private function hasComposerFile($owner, $repository, $branch)
    {
        return $this->client->api('repo')->contents()->exists(
            $owner,
            $repository,
            'composer.json',
            $branch
        );
    }
}
