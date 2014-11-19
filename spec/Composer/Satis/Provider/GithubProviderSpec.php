<?php

namespace spec\Composer\Satis\Provider;

use Github\Api\Organization;
use Github\Api\Repo;
use Github\Api\Repository\Contents;
use Github\Client;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class GithubProviderSpec extends ObjectBehavior
{
    function let(Client $client, Organization $organization, Repo $repo, Contents $contents)
    {
        $this->beConstructedWith($client);

        $client->api('organization')->willReturn($organization);
        $client->api('repo')->willReturn($repo);
        $repo->contents()->willReturn($contents);
        $organization->setPerPage(Argument::any())->willReturn($organization);
    }

    function it_authenticate_with_given_token(Client $client, Organization $organization)
    {
        $this->beConstructedWith($client, 'token');
        $client->authenticate('token', null, Client::AUTH_HTTP_TOKEN)->shouldBeCalled();
        $organization->repositories('dummy', 'private')->willReturn(array());

        $this->getRepositories('dummy');
    }

    function it_performs_requests_as_an_anonymous_user(Client $client, Organization $organization)
    {
        $client->authenticate(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();
        $organization->repositories('dummy', 'private')->willReturn(array());

        $this->getRepositories('dummy');
    }

    function it_returns_an_array_of_repositories_from_github(Organization $organization, Contents $contents)
    {
        $data = array(
            array(
                'owner' => array('login' => 'owner_login'),
                'name' => 'sample_repo',
                'full_name' => 'sample-organisation/sample_repo',
                'git_url' => 'git@github.com/example/repo.git'
            )
        );
        $organization->repositories('sample-organisation', 'private')->willReturn($data)->shouldBeCalled();
        $contents->exists('owner_login', 'sample_repo', 'composer.json', 'master')->willReturn(true);

        $reposities = $this->getRepositories('sample-organisation');

        $reposities->shouldBeArray();
        $reposities->shouldHaveCount(1);

        $repo = $reposities[0];
        $repo->shouldBeAnInstanceOf('Composer\Satis\Provider\Repository');
        $repo->getName()->shouldBe($data[0]['full_name']);
        $repo->getUrl()->shouldBe($data[0]['git_url']);
    }

    function it_filters_non_composer_aware_repositories(Organization $organization, Contents $contents)
    {
        $data = array(
            array(
                'owner' => array('login' => 'owner_login'),
                'name' => 'sample_repo',
                'full_name' => 'sample-organisation/sample_repo',
                'git_url' => 'git@github.com/sample-organisation/sample_repo.git'
            ),
            array(
                'owner' => array('login' => 'owner_login'),
                'name' => 'sample_repo2',
                'full_name' => 'sample-organisation/sample_repo2',
                'git_url' => 'git@github.com/sample-organisation/sample_repo2.git'
            )
        );
        $organization->repositories('sample-organisation', 'private')->willReturn($data)->shouldBeCalled();
        $contents->exists('owner_login', 'sample_repo', 'composer.json', 'master')->willReturn(true);
        $contents->exists('owner_login', 'sample_repo', 'composer.json', 'develop')->shouldNotBeCalled();
        $contents->exists('owner_login', 'sample_repo2', 'composer.json', 'master')->willReturn(false);
        $contents->exists('owner_login', 'sample_repo2', 'composer.json', 'develop')->shouldBeCalled()->willReturn(false);

        $reposities = $this->getRepositories('sample-organisation');

        $reposities->shouldBeArray();
        $reposities->shouldHaveCount(1);
    }
}
