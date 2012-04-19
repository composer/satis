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
use Composer\Console\Application as ComposerApplication;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\AliasPackage;
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
        $verbose = $input->getOption('verbose');
        $file = new JsonFile($input->getArgument('file'));
        if (!$file->exists()) {
            $output->writeln('<error>File not found: '.$input->getArgument('file').'</error>');

            return 1;
        }
        $config = $file->read();

        // disable packagist by default
        $config['repositories'][] = array('packagist' => false);

        // fetch options
        $requireAll = isset($config['require-all']) && true === $config['require-all'];
        if (!$requireAll && !isset($config['require'])) {
            $output->writeln('No explicit requires defined, enabling require-all');
            $requireAll = true;
        }

        $composer = $this->getApplication()->getComposer(true, $config);

        $repo = array('packages' => array());
        $targets = array();
        $selected = array();
        $dumper = new ArrayDumper;

        foreach ($composer->getPackage()->getRequires() as $link) {
            $targets[$link->getTarget()] = array(
                'matched' => false,
                'link' => $link,
                'constraint' => $link->getConstraint()
            );
        }

        // run over all packages and store matching ones
        $output->writeln('<info>Scanning packages</info>');
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            foreach ($repository->getPackages() as $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    continue;
                }

                $name = $package->getName();
                $version = $package->getVersion();

                // skip non-matching packages
                if (!$requireAll && (!isset($targets[$name]) || !$targets[$name]['constraint']->matches(new VersionConstraint('=', $version)))) {
                    continue;
                }

                // add matching package if not yet selected
                if (!isset($selected[$package->getUniqueName()])) {
                    if ($verbose) {
                        $output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                    }
                    $targets[$name]['matched'] = true;
                    $selected[$package->getUniqueName()] = $package;
                }
            }
        }

        // check for unmatched requirements
        foreach ($targets as $package => $target) {
            if (!$target['matched']) {
                $output->writeln('<error>The '.$target['link']->getTarget().' '.$target['link']->getPrettyConstraint().' requirement did not match any package</error>');
            }
        }

        // dump
        foreach ($selected as $package) {
            $repo['packages'][$package->getPrettyName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }
        $output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($input->getArgument('build-dir').'/packages.json');
        $repoJson->write($repo);
    }
}
