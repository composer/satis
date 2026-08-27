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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->getName() ?? $this->setName('remove');
        $this
            ->setDescription('Remove repository URL from satis JSON file')
            ->setDefinition([
                new InputArgument('url', InputArgument::REQUIRED, 'VCS repository URL'),
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json'),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>remove</info> command removes the given repository URL from the json
                file (satis.json is used by default). You will need to run <comment>build</comment> command
                to regenerate your repository without that repository, and <comment>purge</comment> to
                drop the archives it left behind.
                EOT
            );
    }

    /**
     * Exit codes mirror AddCommand: 2 for a remote config file, 1 for a config
     * file that does not exist, 0 on success. Where AddCommand returns 4 for a
     * URL that is already in the config, this returns 4 for a URL that is not.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $configFile = $input->getArgument('file');
        $repositoryUrl = $input->getArgument('url');

        if (1 === preg_match('{^https?://}i', $configFile)) {
            $output->writeln('<error>Unable to write to remote file ' . $configFile . '</error>');

            return 2;
        }

        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: ' . $configFile . '</error>');

            return 1;
        }

        $config = $file->read();
        $repositories = [];
        if (isset($config['repositories']) && is_array($config['repositories'])) {
            $repositories = $config['repositories'];
        }

        $remaining = array_values(array_filter(
            $repositories,
            static fn ($repository): bool => !isset($repository['url']) || $repository['url'] !== $repositoryUrl
        ));

        if (count($remaining) === count($repositories)) {
            $output->writeln('<error>Repository url not found in the file</error>');

            return 4;
        }

        $config['repositories'] = $remaining;

        $file->write($config);

        $output->writeln([
            '',
            $formatter->formatBlock('Your configuration file successfully updated! It\'s time to rebuild your repository', 'bg=blue;fg=white', true),
            '',
        ]);

        return 0;
    }
}
