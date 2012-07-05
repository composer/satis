<?php
namespace Composer\Satis\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Input\ArrayInput;

class MirrorCommand extends Command
{

    protected function configure()
    {
        $this
            ->setName('mirror')
            ->setDescription('Mirrors packages from a composer.lock file to make them available locally')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::REQUIRED, 'Json file to use'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputOption('config-file', null, InputOption::VALUE_NONE, 'The config file to update with the new info'),
            ))
            ->setHelp(<<<EOT
The <info>mirror</info> command reads the given composer.lock file and mirrors
each git repository so they can be used locally.
<warning>This will only work for repos hosted on github.</warning>
EOT
            )
        ;
    }

    /**
     * @param InputInterface  $input  The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = new JsonFile($input->getArgument('file'));
        $dir = $input->getArgument('output-dir');
        $cfg = $input->getOption('config-file');
        if (!$file->exists()) {
            $output->writeln('<error>File not found: '.$input->getArgument('file').'</error>');

            return 1;
        }
        $repositories = $file->read();
        $packages = array_unique(array_map(function($package){return $package['package'];}, $repositories['packages']));
        foreach($packages as $package){
            $command = $this->getApplication()->find('add');

            $arguments = array(
                'command'   => 'add',
                'package'   => $package,
                'repos-dir' => $dir,
            );
            if(!is_null($cfg)){
                $arguments['--config-file'] = $cfg;
            }

            $input = new ArrayInput($arguments);
            $returnCode = $command->run($input, $output);
        }
    }
}
