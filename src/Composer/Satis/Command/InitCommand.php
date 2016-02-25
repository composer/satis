<?php

/*
 * This file is part of Satis.
 *
 * (c) Sergey Kolodyazhnyy <sergey.kolodyazhnyy@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis\Command;

use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Composer\Command\BaseCommand;
use Composer\Config;
use Composer\Json\JsonFile;

/**
 * @author Sergey Kolodyazhnyy <sergey.kolodyazhnyy@gmail.com>
 */
class InitCommand extends BaseCommand
{

    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Initialize Satis configuration file')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json'),
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Repository name'),
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Home page')
            ))
            ->setHelp(<<<EOT
The <info>init</info> generates configuration file (satis.json is used by default).
You will need to run <comment>build</comment> command to build repository.
EOT
            )
        ;
    }

    /**
     * Print welcome message
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $formatter = $this->getHelperSet()->get('formatter');

        $output->writeln(array(
            '',
            $formatter->formatBlock('Welcome to the Satis config generator', 'bg=blue;fg=white', true),
            ''
        ));

        $output->writeln(array(
            '',
            'This command will guide you through creating your Satis config.',
            '',
        ));
    }


    /**
     * Generate configuration file
     *
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $formatter = $this->getHelperSet()->get('formatter');

        $configFile = $input->getArgument('file');

        if (preg_match('{^https?://}i', $configFile)) {
            $output->writeln('<error>Unable to write to remote file '.$configFile.'</error>');
            return 2;
        }

        $file = new JsonFile($configFile);
        if ($file->exists()) {
            $output->writeln('<error>Configuration file already exists</error>');
            return 1;
        }

        $config = array(
            'name'         => $input->getOption('name'),
            'homepage'     => $input->getOption('homepage'),
            'repositories' => array(),
            'require-all'  => true
        );

        $file->write($config);

        $output->writeln(array(
            '',
            $formatter->formatBlock('Your configuration file successfully created!', 'bg=blue;fg=white', true),
            ''
        ));

        $output->writeln(array(
            '',
            'You are ready to add your package repositories',
            'Use <comment>satis add repository-url</comment> to add them.',
            '',
        ));

        return 0;
    }

    /**
     * Interact with user
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        /** @var DialogHelper $dialog */
        $dialog = $this->getHelperSet()->get('dialog');

        $name = $input->getOption('name');
        $name = $dialog->askAndValidate(
            $output,
            $this->getQuestion('Repository name', $name),
            function ($value) use ($name) {
                if (null === $value) {
                    $value = $name;
                }

                if (!$value) {
                    throw new \InvalidArgumentException("Repository name should not be empty");
                }


                return $value;
            }
        );
        $input->setOption('name', $name);

        $homepage = $input->getOption('homepage');
        $homepage = $dialog->askAndValidate(
            $output,
            $this->getQuestion('Home page', $homepage),
            function ($value) use ($homepage) {
                if (null === $value) {
                    $value = $homepage;
                }

                if (!preg_match('/https?:\/\/.+/', $value)) {
                    throw new \InvalidArgumentException(
                        "Enter a valid URL it will be used for building your repository"
                    );
                }

                return $value;
            }
        );

        $input->setOption('homepage', $homepage);
    }

    /**
     * Build a question
     *
     * @param $question
     * @param $default
     * @return string
     */
    protected function getQuestion($question, $default)
    {
        return ($default ? sprintf("%s (%s)", $question, $default) : $question) . ": ";
    }

}
