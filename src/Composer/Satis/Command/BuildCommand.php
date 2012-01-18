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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Composer\Console\Application as ComposerApplication;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BuildCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds a repository out of a composer json file')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::REQUIRED, 'Json file to use'),
                new InputArgument('build-dir', InputArgument::REQUIRED, 'Location where to output built files'),
            ))
            ->setHelp(<<<EOT
The <info>build</info> command reads the given json file and
outputs a composer repository in the given build-dir.
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
        $io = new ConsoleIO($input, $output, new HelperSet());
        $composer = $this->getComposer($io, $input->getArgument('file'));

        $packages = array();
        $targets = array();
        $dumper = new ArrayDumper;

        foreach ($composer->getPackage()->getRequires() as $link) {
            $targets[$link->getTarget()] = $link->getConstraint();
        }

        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                $name = $package->getName();
                if (isset($targets[$name]) && $targets[$name]->matches(new VersionConstraint('=', $package->getVersion()))) {
                    $packages[$package->getName()][$package->getVersion()] = $dumper->dump($package);
                }
            }
        }

        $output->writeln('Writing packages.json');
        $repoJson = new JsonFile($input->getArgument('build-dir').'/packages.json');
        $repoJson->write($packages);
    }

    /**
     * @param IOInterface $io
     * @param string      $file
     *
     * @return Composer
     */
    public function getComposer(IOInterface $io, $file)
    {
        return \Composer\Factory::create($io, $file);
    }
}
