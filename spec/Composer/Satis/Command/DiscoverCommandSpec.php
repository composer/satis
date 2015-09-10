<?php

namespace spec\Composer\Satis\Command;

use Composer\Satis\Provider\ProviderFactory;
use Composer\Satis\Provider\ProviderInterface;
use Composer\Satis\Provider\Repository;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DiscoverCommandSpec extends ObjectBehavior
{
    function let(ProviderFactory $providerFactory, ProviderInterface $provider, InputInterface $input)
    {
        $this->setProviderFactory($providerFactory);
        $providerFactory->create(Argument::any(), Argument::any(), Argument::any())->willReturn($provider);

        $input->getOption('name')->willReturn('my-repo')->shouldBeCalled();
        $input->getOption('homepage')->willReturn('http://repo')->shouldBeCalled(2);
        $input->getOption('exclude')->willReturn(array())->shouldBeCalled();
        $input->getOption('provider')->willReturn('dummy-provider')->shouldBeCalled();
        $input->bind(Argument::any())->shouldBeCalled();
        $input->isInteractive()->willReturn(false);
        $input->validate()->willReturn(false);
    }

    function it_sets_basic_options(InputInterface $input, OutputInterface $output, ProviderInterface $provider)
    {
        $input->getArgument('organisations')->willReturn(array())->shouldBeCalled();
        $provider->getRepositories(Argument::any())->shouldNotBeCalled();
        $output->write('{
    "name": "my-repo",
    "homepage": "http:\/\/repo",
    "repositories": [],
    "require-all": true
}')->shouldBeCalled();
        $this->run($input, $output);
    }

    function it_generates_json_with_repositories(InputInterface $input, OutputInterface $output,
                                                 ProviderInterface $provider, Repository $repository)
    {
        $input->getArgument('organisations')->willReturn(array('dummy'))->shouldBeCalled();
        $repository->getName()->willReturn('name');
        $repository->getUrl()->willReturn('http://url');
        $provider->getRepositories('dummy')
            ->willReturn(array($repository))
            ->shouldBeCalled();

        $output->write('{
    "name": "my-repo",
    "homepage": "http:\/\/repo",
    "repositories": [
        {
            "type": "vcs",
            "url": "http:\/\/url"
        }
    ],
    "require-all": true
}')->shouldBeCalled();

        $this->run($input, $output);
    }
}
