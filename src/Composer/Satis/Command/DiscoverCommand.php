<?php

namespace Composer\Satis\Command;

use Composer\Satis\Provider\ProviderFactory;
use Composer\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DiscoverCommand extends Command
{
    /**
     * @var ProviderFactory
     */
    protected $providerFactory;

    /**
     * @param ProviderFactory $providerFactory
     */
    public function setProviderFactory(ProviderFactory $providerFactory)
    {
        $this->providerFactory = $providerFactory;
    }

    /**
     * @return ProviderFactory
     */
    public function getProviderFactory()
    {
        if ($this->providerFactory === null) {
            $this->providerFactory = new ProviderFactory($this->getApplication()->getComposer());
        }

        return $this->providerFactory;
    }

    protected function configure()
    {
        $this
            ->setName('discover')
            ->setDescription('Generates json file which can be used to generate repository from the given provider')
            ->setDefinition(array(
                new InputOption(
                    'provider',
                    'p',
                    InputOption::VALUE_OPTIONAL,
                    'Provider which should be used to fetch list of repositories',
                    'github'
                ),
                new InputOption(
                    'auth',
                    'a',
                    InputOption::VALUE_OPTIONAL,
                    'Authentication data in provider specific format'
                ),
                new InputOption('name', null, InputOption::VALUE_OPTIONAL, 'Name of the repository', 'My repository'),
                new InputOption('homepage', null, InputOption::VALUE_OPTIONAL, 'Homepage of the repository'),
                new InputOption(
                    'exclude',
                    'e',
                    InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                    'Exclude named repositories'
                ),
                new InputArgument(
                    'organisations',
                    InputArgument::IS_ARRAY,
                    'List of the organisations / namespaces which should be scanned for composer repositories.',
                    null
                ),
            ))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $provider = $this->getProviderFactory()->create($input->getOption('provider'), $input);

        $data = new \stdClass();
        $data->name = $input->getOption('name');

        if ($input->getOption('homepage') !== null) {
            $data->homepage =  $input->getOption('homepage');
        }

        $excludes = $input->getOption('exclude');

        $data->repositories = array();

        foreach ($input->getArgument('organisations') as $organisation) {
            foreach ($provider->getRepositories($organisation) as $repository) {
                if (in_array($repository->getName(), $excludes)) {
                    continue;
                }

                $data->repositories[] = array('type' => 'vcs', 'url' => $repository->getUrl());
            }
        }

        $data->{'require-all'} = true;

        $output->write(json_encode($data, JSON_PRETTY_PRINT));
    }
}
