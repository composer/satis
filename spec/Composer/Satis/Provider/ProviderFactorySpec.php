<?php

namespace spec\Composer\Satis\Provider;

use Composer\Composer;
use Composer\Config;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Component\Console\Input\InputInterface;

class ProviderFactorySpec extends ObjectBehavior
{
    function let(Composer $composer, Config $config)
    {
        $this->beConstructedWith($composer);

        $composer->getConfig()->willReturn($config);
    }

    function it_throws_an_exception_for_unknown_type(InputInterface $input)
    {
        $this->shouldThrow('\Composer\Satis\Provider\UnknownProviderException')
            ->duringCreate('unknown', $input);
    }

    function it_creates_new_instance_of_the_github_provider(InputInterface $input)
    {
        $provider = $this->create('github', $input);
        $provider->shouldBeAnInstanceOf('\Composer\Satis\Provider\GithubProvider');
    }

    function it_uses_token_from_input_option(InputInterface $input, Config $config)
    {
        $input->getOption('auth')->willReturn('xxx')->shouldBeCalled();
        $config->get('github-oauth')->shouldNotBeCalled();

        $this->create('github', $input);
    }

    function it_uses_token_from_composer_configuration(InputInterface $input, Config $config)
    {
        $input->getOption('auth')->willReturn(null);
        $config->has('github-oauth')->willReturn(true);
        $config->get('github-oauth')->willReturn('xxx')->shouldBeCalled();

        $this->create('github', $input);
    }
}
