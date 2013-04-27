<?php
namespace Composer\Satis\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

class UpdateReposCommand extends Command
{

    protected function configure()
    {
        $this
            ->setName('update')
            ->setDescription('Fetch last updates from each package')
            ->setDefinition(array(
                new InputArgument('repos-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
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
        $dir = $input->getArgument('repos-dir');
        $repos = glob($dir.'/*.git');
        $cmd = 'cd %s && git fetch';
        foreach($repos as $repo){
            $output->writeln(' - Fetching latest changes in <info>'.$repo.'</info>');
            $process = new Process(sprintf($cmd, $repo));
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \Exception($process->getErrorOutput());
            }
            $output->writeln($process->getOutput());
        }
    }
}
