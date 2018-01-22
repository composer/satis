<?php

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Console\Command;

use Composer\Command\BaseCommand;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Repository\VcsRepository;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Sergey Kolodyazhnyy <sergey.kolodyazhnyy@gmail.com>
 */
class AddCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('add')
            ->setDescription('Add repository URL to satis JSON file')
            ->setDefinition([
                new InputArgument('url', InputArgument::REQUIRED, 'VCS repository URL'),
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json'),
            ])
            ->setHelp(<<<'EOT'
The <info>add</info> command adds given repository URL to the json file
(satis.json is used by default). You will need to run <comment>build</comment> command to
fetch updates from repository.
EOT
            )
        ;
    }

    /**
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $configFile = $input->getArgument('file');
        $repositoryUrl = $input->getArgument('url');

        if (preg_match('{^https?://}i', $configFile)) {
            $output->writeln('<error>Unable to write to remote file ' . $configFile . '</error>');

            return 2;
        }

        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: ' . $configFile . '</error>');

            return 1;
        }

        if (!$this->isRepositoryValid($repositoryUrl)) {
            $output->writeln('<error>Invalid Repository URL: ' . $repositoryUrl . '</error>');

            return 3;
        }

        $config = $file->read();
        if (!isset($config['repositories']) || !is_array($config['repositories'])) {
            $config['repositories'] = [];
        }

        foreach ($config['repositories'] as $repository) {
            if (isset($repository['url']) && $repository['url'] == $repositoryUrl) {
                $output->writeln('<error>Repository already added to the file</error>');

                return 4;
            }
        }

        $config['repositories'][] = ['type' => 'vcs', 'url' => $repositoryUrl];

        $file->write($config);

        $output->writeln([
            '',
            $formatter->formatBlock('Your configuration file successfully updated! It\'s time to rebuild your repository', 'bg=blue;fg=white', true),
            '',
        ]);

        return 0;
    }

    /**
     * Validate repository URL
     *
     * @param $repositoryUrl
     *
     * @return bool
     */
    protected function isRepositoryValid($repositoryUrl)
    {
        $io = new NullIO();
        $config = Factory::createConfig();
        $io->loadConfiguration($config);
        $repository = new VcsRepository(['url' => $repositoryUrl], $io, $config);

        if (!($driver = $repository->getDriver())) {
            return false;
        }

        $information = $driver->getComposerInformation($driver->getRootIdentifier());

        return !empty($information['name']);
    }
}
