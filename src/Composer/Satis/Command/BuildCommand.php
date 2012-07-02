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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;
use Composer\Composer;
use Composer\Config;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\AliasPackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\PackageInterface;
use Composer\Json\JsonFile;
use Composer\Satis\Satis;

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
                new InputOption('--stylesheet', null, InputOption::VALUE_NONE, "Local stylesheet to add"),
                new InputOption('--dynamic', null, InputOption::VALUE_OPTIONAL, "Output an 'index.php' instead of 'index.html'", 'no')
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

        // check if we need a local stylesheet
        $stylesheet = $input->getOption('stylesheet');
        if (!empty($stylesheet)) {
            if (!file_exists($stylesheet) || !is_readable($stylesheet)) {
                throw new \RuntimeException(sprintf("Could not open your stylesheet '%s'", $stylesheet));
            }
        }

        // check if we should output an index.php instead
        $dynamic = strtolower($input->getOption('dynamic'));
        if (!in_array($dynamic, array('yes', 'no'))) {
            throw new \InvalidArgumentException("Please use --dynamic=yes|no");
        }

        // disable packagist by default
        unset(Config::$defaultRepositories['packagist']);

        // fetch options
        $requireAll = isset($config['require-all']) && true === $config['require-all'];
        if (!$requireAll && !isset($config['require'])) {
            $output->writeln('No explicit requires defined, enabling require-all');
            $requireAll = true;
        }

        $composer = $this->getApplication()->getComposer(true, $config);
        $packages = $this->selectPackages($composer, $output, $verbose, $requireAll);

        $filename = $input->getArgument('build-dir').'/packages.json';
        $rootPackage = $composer->getPackage();
        $this->dumpJson($packages, $output, $filename);
        $this->dumpWeb(
            $packages,
            $output,
            $rootPackage,
            $input->getArgument('build-dir'),
            $stylesheet,
            $dynamic
        );
    }

    private function selectPackages(Composer $composer, OutputInterface $output, $verbose, $requireAll)
    {
        $targets = array();
        $selected = array();

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

        asort($selected, SORT_STRING);

        return $selected;
    }

    private function dumpJson(array $packages, OutputInterface $output, $filename)
    {
        $repo = array('packages' => array());
        $dumper = new ArrayDumper;
        foreach ($packages as $package) {
            $repo['packages'][$package->getPrettyName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }
        $output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($filename);
        $repoJson->write($repo);
    }

    private function dumpWeb(
        array $packages,
        OutputInterface $output,
        PackageInterface $rootPackage,
        $directory,
        $stylesheet = '',
        $dynamic
    ) {
        $templateDir = __DIR__.'/../../../../views';
        $loader = new \Twig_Loader_Filesystem($templateDir);
        $twig = new \Twig_Environment($loader);

        $mappedPackages = $this->getMappedPackageList($packages);

        $name = $rootPackage->getPrettyName();
        if ($name === '__root__') {
            $name = 'A';
            $output->writeln('Define a "name" property in your json config to name the repository');
        }

        if (!$rootPackage->getHomepage()) {
            $output->writeln('Define a "homepage" property in your json config to configure the repository URL');
        }

        $output->writeln('<info>Writing web view</info>');
        $vars = array(
            'name'          => $name,
            'url'           => $rootPackage->getHomepage(),
            'description'   => $rootPackage->getDescription(),
            'packages'      => $mappedPackages,
        );


        $targetPage = $directory . '/index.html';
        if ($dynamic == 'yes') {
            $targetPage = $directory . '/index.php';
        }
        file_put_contents($targetPage, $twig->render('index.html.twig', $vars));

        $targetStyles = $directory . '/styles.css';
        copy($templateDir.'/styles.css', $targetStyles);

        if (!empty($stylesheet)) {
            $localStyles = PHP_EOL
                . PHP_EOL . '/* local style sheet */' . PHP_EOL
                . file_get_contents($stylesheet);
            file_put_contents($targetStyles, $localStyles, FILE_APPEND);
        }
    }

    private function getMappedPackageList(array $packages)
    {
        $groupedPackages = $this->groupPackagesByName($packages);

        $mappedPackages = array();
        foreach ($groupedPackages as $name => $packages) {
            $mappedPackages[$name] = array(
                'highest' => $this->getHighestVersion($packages),
                'versions' => $this->getDescSortedVersions($packages),
            );
        }

        return $mappedPackages;
    }

    private function groupPackagesByName(array $packages)
    {
        $groupedPackages = array();
        foreach ($packages as $package) {
            $groupedPackages[$package->getName()][] = $package;
        }

        return $groupedPackages;
    }

    private function getHighestVersion(array $packages)
    {
        $highestVersion = null;
        foreach ($packages as $package) {
            if (null === $highestVersion || version_compare($package->getVersion(), $highestVersion->getVersion(), '>=')) {
                $highestVersion = $package;
            }
        }

        return $highestVersion;
    }

    private function getDescSortedVersions(array $packages)
    {
        usort($packages, function ($a, $b) {
            return version_compare($b->getVersion(), $a->getVersion());
        });

        return $packages;
    }
}
