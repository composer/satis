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
use Composer\Config;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

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
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json'),
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Repository name'),
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Home page'),
            ])
            ->setHelp(<<<'EOT'
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
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $output->writeln([
            '',
            $formatter->formatBlock('Welcome to the Satis config generator', 'bg=blue;fg=white', true),
            '',
        ]);

        $output->writeln([
            '',
            'This command will guide you through creating your Satis config.',
            '',
        ]);
    }

    /**
     * Generate configuration file
     *
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

        if (preg_match('{^https?://}i', $configFile)) {
            $output->writeln('<error>Unable to write to remote file ' . $configFile . '</error>');

            return 2;
        }

        $file = new JsonFile($configFile);
        if ($file->exists()) {
            $output->writeln('<error>Configuration file already exists</error>');

            return 1;
        }

        $config = [
            'name' => $input->getOption('name'),
            'homepage' => $input->getOption('homepage'),
            'repositories' => [],
            'require-all' => true,
        ];

        $file->write($config);

        $output->writeln([
            '',
            $formatter->formatBlock('Your configuration file successfully created!', 'bg=blue;fg=white', true),
            '',
        ]);

        $output->writeln([
            '',
            'You are ready to add your package repositories',
            'Use <comment>satis add repository-url</comment> to add them.',
            '',
        ]);

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
        $this->prompt($input, $output, 'Repository name', 'name', function ($value) {
            if (!$value) {
                throw new \InvalidArgumentException('Repository name should not be empty');
            }

            return $value;
        });

        $this->prompt($input, $output, 'Home page', 'homepage', function ($value) {
            if (!preg_match('/https?:\/\/.+/', $value)) {
                throw new \InvalidArgumentException(
                    'Enter a valid URL it will be used for building your repository'
                );
            }

            return $value;
        });
    }

    /**
     * Prompt for an input option.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string          $prompt
     * @param string          $optionName For the default value and where the answer is set
     * @param callable        $validator
     */
    protected function prompt(InputInterface $input, OutputInterface $output, $prompt, $optionName, $validator)
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $question = $this->getQuestion($prompt, $input->getOption($optionName));
        $question->setValidator($validator);

        $input->setOption($optionName, $helper->ask($input, $output, $question));
    }

    /**
     * Build a question
     *
     * @param string $prompt
     * @param string $default
     *
     * @return Question
     */
    protected function getQuestion($prompt, $default)
    {
        $prompt = ($default ? sprintf('%s (%s)', $prompt, $default) : $prompt) . ': ';

        return new Question($prompt, $default);
    }
}
