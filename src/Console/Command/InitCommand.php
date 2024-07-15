<?php

declare(strict_types=1);

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
use Composer\Json\JsonFile;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class InitCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->getName() ?? $this->setName('init');
        $this
            ->setDescription('Initialize Satis configuration file')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json'),
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'Repository name'),
                new InputOption('homepage', null, InputOption::VALUE_REQUIRED, 'Home page'),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>init</info> generates configuration file (satis.json is used by default).
                You will need to run <comment>build</comment> command to build repository.
                EOT
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $configFile = $input->getArgument('file');

        if (1 === preg_match('{^https?://}i', $configFile)) {
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

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $this->prompt($input, $output, 'Repository name', 'name', function ($value) {
            if (!$value) {
                throw new \InvalidArgumentException('Repository name should not be empty');
            }

            return $value;
        });

        $this->prompt($input, $output, 'Home page', 'homepage', function ($value) {
            if (1 !== preg_match('/https?:\/\/.+/', $value)) {
                throw new \InvalidArgumentException('Enter a valid URL it will be used for building your repository');
            }

            return $value;
        });
    }

    protected function prompt(InputInterface $input, OutputInterface $output, string $prompt, string $optionName, callable $validator): void
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $question = $this->getQuestion($prompt, $input->getOption($optionName));
        $question->setValidator($validator);

        $input->setOption($optionName, $helper->ask($input, $output, $question));
    }

    protected function getQuestion(string $prompt, ?string $default): Question
    {
        $prompt = (is_string($default) && '' !== $default ? sprintf('%s (%s)', $prompt, $default) : $prompt) . ': ';

        return new Question($prompt, $default);
    }
}
