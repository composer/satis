<?php

/*
 * This file is part of Satis.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Satis\Command;

use Composer\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Composer\Json\JsonFile;

class PurgeCommand extends Command
{
    protected function configure()
    {
        $this->setName('purge')
            ->setDescription('Purge packages')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
            ))
            ->setHelp(
<<<EOT
The <info>purge</info> command deletes useless archive files, depending
of given json file (satis.json is used by default) and the
last json file in the include directory of the given output-dir.

In your satis.json (or other name you give), you must define
"archive" argument.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getArgument('file');
        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: '.$configFile.'</error>');

            return 1;
        }
        $config = $file->read();

        /**
         * Verif if archive is defined
         */
        if (!isset($config['archive']) || !isset($config['archive']['directory'])) {
            $output->writeln('<error>You must define "archive" argument in your '.$configFile.'</error>');

            return 1;
        }

        if (!$outputDir = $input->getArgument('output-dir')) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument');
        }

        $files = glob($outputDir."/include/*.json");

        if (empty($files)) {
            $output->writeln('<info>No log file</info>');

            return 1;
        }

        $files = array_combine($files, array_map("filemtime", $files));
        arsort($files);

        $file = file_get_contents(key($files));
        $json = json_decode($file, true);

        $needed = null;
        foreach ($json['packages'] as $key => $value) {
            $needed[] = str_replace("/", "-", $key);
        }

        /**
         * Packages in output-dir
         */
        $files = scandir($outputDir."/".$config['archive']['directory'], 1);

        if (empty($files)) {
            $output->writeln('<info>No archived files</info>');

            return 1;
        }

        /**
         * Get vendor-package of archived files
         */
        $regex = "/(.*)-(?:.*)-(?:.*)-(?:.*)/i";

        foreach ($files as $file) {
            preg_match($regex, $file, $matches);

            if (isset($matches[1]) && !in_array($matches[1], $needed)) {
                $output->writeln("<info>".$matches[1]." :: deleted</info>");

                unlink($outputDir."/".$config['archive']['directory']."/".$file);
            }
        }

        $output->writeln("<info>Purge :: finished</info>");
    }
}
