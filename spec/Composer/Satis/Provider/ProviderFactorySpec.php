<?php

namespace spec\Composer\Satis\Provider;

use Composer\Composer;
use Composer\Config;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProviderFactorySpec extends ObjectBehavior
{
    function let(Composer $composer, Config $config)
    {
        $this->beConstructedWith($composer);

        $composer->getConfig()->willReturn($config);
    }

    function it_throws_an_exception_for_unknown_type(InputInterface $input, OutputInterface $output)
    {
        $this->shouldThrow('\Composer\Satis\Provider\UnknownProviderException')
            ->duringCreate('unknown', $input, $output);
    }

    function it_creates_new_instance_of_the_github_provider(InputInterface $input, OutputInterface $output)
    {
        $provider = $this->create('github', $input, $output);
        $provider->shouldBeAnInstanceOf('\Composer\Satis\Provider\GithubProvider');
    }

    function it_uses_token_from_input_option(InputInterface $input, OutputInterface $output, Config $config)
    {
        $input->getOption('auth')->willReturn('xxx')->shouldBeCalled();
        $config->get('github-oauth')->shouldNotBeCalled();

        $this->create('github', $input, $output);
    }

    function it_uses_token_from_composer_configuration(InputInterface $input, OutputInterface $output, Config $config)
    {
        $input->getOption('auth')->willReturn(null);
        $config->has('github-oauth')->willReturn(true);
        $config->get('github-oauth')->willReturn('xxx')->shouldBeCalled();

        $this->create('github', $input, $output);
    }
}
