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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Composer\Command\Command;
use Composer\Config;
use Composer\Json\JsonFile;

/**
 * @author Sergey Kolodyazhnyy <sergey.kolodyazhnyy@gmail.com>
 */
class AddCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('add')
            ->setDescription('Add repository URL to satis JSON file')
            ->setDefinition(array(
                new InputArgument('url',  InputArgument::REQUIRED, 'VCS repository URL'),
                new InputArgument('file', InputArgument::OPTIONAL, 'JSON file to use', './satis.json')
            ))
            ->setHelp(<<<EOT
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
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getArgument('file');
        $repositoryUrl = $input->getArgument('url');

        if (preg_match('{^https?://}i', $configFile)) {
            $output->writeln('<error>Unable to write to remote file '.$configFile.'</error>');
            return 2;
        }

        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: '.$configFile.'</error>');
            return 1;
        }

        $config = $file->read();
        if (!isset($config['repositories']) || !is_array($config['repositories'])) {
            $config['repositories'] = array();
        }

        $config['repositories'][] = array('type' => 'vcs', 'url'  => $repositoryUrl);

        $file->write($config);

        return 0;
    }

}
