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
use Composer\Json\JsonFile;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class PurgeCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('purge')
            ->setDescription('Purge packages')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
            ])
            ->setHelp(
<<<'EOT'
The <info>purge</info> command deletes useless archive files, depending
on given json file (satis.json is used by default) and the
lastest json file in the include directory of the given output-dir.

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
            $output->writeln('<error>File not found: ' . $configFile . '</error>');

            return 1;
        }
        $config = $file->read();

        /*
         * Check whether archive is defined
         */
        if (!isset($config['archive']) || !isset($config['archive']['directory'])) {
            $output->writeln('<error>You must define "archive" parameter in your ' . $configFile . '</error>');

            return 1;
        }

        if (!$outputDir = $input->getArgument('output-dir')) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument');
        }

        $files = glob($outputDir . '/include/*.json');

        if (empty($files)) {
            $output->writeln('<info>No log file</info>');

            return 1;
        }

        $files = array_combine($files, array_map('filemtime', $files));
        arsort($files);

        $file = file_get_contents(key($files));
        $json = json_decode($file, true);

        $prefix = $config['archive']['directory'];
        if (isset($config['archive']['prefix-url'])) {
            $prefix = sprintf('%s/%s/', $config['archive']['prefix-url'], $prefix);
        } else {
            $prefix = sprintf('%s/%s/', $config['homepage'], $prefix);
        }

        $length = strlen($prefix);
        $needed = [];
        foreach ($json['packages'] as $package) {
            foreach ($package as $version) {
                if (!isset($version['dist']['url'])) {
                    continue;
                }

                $url = $version['dist']['url'];

                if (substr($url, 0, $length) === $prefix) {
                    $needed[] = substr($url, $length);
                }
            }
        }

        $distDirectory = sprintf('%s/%s', $outputDir, $config['archive']['directory']);

        $finder = new Finder();
        $finder
            ->files()
            ->in($distDirectory)
        ;

        if (!$finder->count()) {
            $output->writeln('<warning>No archives found.</warning>');

            return 0;
        }

        /** @var SplFileInfo[] $unreferenced */
        $unreferenced = [];
        foreach ($finder as $file) {
            if (!in_array($file->getRelativePathname(), $needed)) {
                $unreferenced[] = $file;
            }
        }

        if (empty($unreferenced)) {
            $output->writeln('<warning>No unreferenced archives found.</warning>');

            return 0;
        }

        foreach ($unreferenced as $file) {
            unlink($file->getPathname());

            $output->writeln(sprintf(
                '<info>Removed archive</info>: <comment>%s</comment>',
                $file->getRelativePathname()
            ));
        }

        $finder = new Finder();
        $finder
            ->directories()
            ->ignoreDotFiles(true)
            ->ignoreUnreadableDirs(true)
            ->in($distDirectory)
        ;

        foreach ($finder->getIterator() as $directory) {
            if (!(new Finder())->in($directory->getPathname())->files()->count()) {
                rmdir($directory->getPathname());
                $output->writeln(sprintf(
                    '<info>Removed empty directory</info>: <comment>%s</comment>',
                    $directory->getPathname()
                ));
            }
        }

        $output->writeln('<info>Done.</info>');

        return 0;
    }
}
