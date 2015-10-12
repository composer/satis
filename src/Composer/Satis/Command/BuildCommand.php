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

use Composer\Config\JsonConfigSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Composer\Command\Command;
use Composer\Composer;
use Composer\Config;
use Composer\Json\JsonFile;
use Composer\Util\RemoteFilesystem;
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\Builder\DownloadsBuilder;
use Composer\Satis\Builder\WebBuilder;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BuildCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds a composer repository out of a json file')
            ->setDefinition(array(
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that should be built, if not provided all packages are.', null),
                new InputOption('no-html-output', null, InputOption::VALUE_NONE, 'Turn off HTML view'),
                new InputOption('skip-errors', null, InputOption::VALUE_NONE, 'Skip Download or Archive errors'),
            ))
            ->setHelp(<<<EOT
The <info>build</info> command reads the given json file
(satis.json is used by default) and outputs a composer
repository in the given output-dir.

The json config file accepts the following keys:

- <info>"repositories"</info>: defines which repositories are searched
  for packages.
- <info>"output-dir"</info>: where to output the repository files
  if not provided as an argument when calling build.
- <info>"require-all"</info>: boolean, if true, all packages present
  in the configured repositories will be present in the
  dumped satis repository.
- <info>"require"</info>: if you do not want to dump all packages,
  you can explicitly require them by name and version.
- <info>"minimum-stability"</info>: sets default stability for packages
  (default: dev), see
  http://getcomposer.org/doc/04-schema.md#minimum-stability
- <info>"require-dependencies"</info>: if you mark a few packages as
  required to mirror packagist for example, setting this
  to true will make satis automatically require all of your
  requirements' dependencies.
- <info>"require-dev-dependencies"</info>: works like require-dependencies
  but requires dev requirements rather than regular ones.
- <info>"config"</info>: all config options from composer, see
  http://getcomposer.org/doc/04-schema.md#config
- <info>"output-html"</info>: boolean, controls whether the repository
  has an html page as well or not.
- <info>"name"</info>: for html output, this defines the name of the
  repository.
- <info>"homepage"</info>: for html output, this defines the home URL
  of the repository (where you will host it).
- <info>"twig-template"</info>: Location of twig template to use for
  building the html output.
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
        $configFile = $input->getArgument('file');
        $packagesFilter = $input->getArgument('packages');
        $skipErrors = (bool) $input->getOption('skip-errors');

        // load auth.json authentication information and pass it to the io interface
        $io = $this->getIO();
        $io->loadConfiguration($this->getConfiguration());

        if (preg_match('{^https?://}i', $configFile)) {
            $rfs = new RemoteFilesystem($io);
            $contents = $rfs->getContents(parse_url($configFile, PHP_URL_HOST), $configFile, false);
            $config = JsonFile::parseJson($contents, $configFile);
        } else {
            $file = new JsonFile($configFile);
            if (!$file->exists()) {
                $output->writeln('<error>File not found: '.$configFile.'</error>');

                return 1;
            }
            $config = $file->read();
        }

        // disable packagist by default
        unset(Config::$defaultRepositories['packagist']);

        // fetch options
        $requireAll = isset($config['require-all']) && true === $config['require-all'];
        $requireDependencies = isset($config['require-dependencies']) && true === $config['require-dependencies'];
        $requireDevDependencies = isset($config['require-dev-dependencies']) && true === $config['require-dev-dependencies'];

        if (!$requireAll && !isset($config['require'])) {
            $output->writeln('No explicit requires defined, enabling require-all');
            $requireAll = true;
        }

        $minimumStability = isset($config['minimum-stability']) ? $config['minimum-stability'] : 'dev';

        if (!$outputDir = $input->getArgument('output-dir')) {
            $outputDir = isset($config['output-dir']) ? $config['output-dir'] : null;
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside '.$input->getArgument('file'));
        }

        $composer = $this->getApplication()->getComposer(true, $config);
        #JamesRezo
        $packagesBuilder = new PackagesBuilder($output);
        $packages = $packagesBuilder->select($composer, $verbose, $requireAll, $requireDependencies, $requireDevDependencies, $minimumStability, $skipErrors, $packagesFilter);

        if (isset($config['archive']['directory'])) {
            #JamesRezo
            $downloads = new DownloadsBuilder($output);
            $downloads->dump($config, $packages, $input, $outputDir, $skipErrors, $this->getApplication()->getHelperSet());
        }

        $filenamePrefix = $outputDir.'/include/all';
        $filename = $outputDir.'/packages.json';
        if (!empty($packagesFilter)) {
            // in case of an active package filter we need to load the dumped packages.json and merge the
            // updated packages in
            $oldPackages = $packagesList->load($filename, $packagesFilter);
            $packages += $oldPackages;
            ksort($packages);
        }

        $packagesBuilder->dump($packages, $filenamePrefix);

        if ($htmlView = !$input->getOption('no-html-output')) {
            $htmlView = !isset($config['output-html']) || $config['output-html'];
        }

        if ($htmlView) {
            $dependencies = array();
            foreach ($packages as $package) {
                foreach ($package->getRequires() as $link) {
                    $dependencies[$link->getTarget()][$link->getSource()] = $link->getSource();
                }
            }

            $rootPackage = $composer->getPackage();
            $twigTemplate = isset($config['twig-template']) ? $config['twig-template'] : null;
            #JamesRezo
            $web = new WebBuilder($output);
            $web->dump($packages, $rootPackage, $outputDir, $twigTemplate, $dependencies);
        }
    }

    /**
     * @return Config
     */
    private function getConfiguration()
    {
        $config = new Config();

        // add dir to the config
        $config->merge(array('config' => array('home' => $this->getComposerHome())));

        // load global auth file
        $file = new JsonFile($config->get('home').'/auth.json');
        if ($file->exists()) {
            $config->merge(array('config' => $file->read()));
        }
        $config->setAuthConfigSource(new JsonConfigSource($file, true));

        return $config;
    }

    /**
     * @return string
     *
     * @throws \RuntimeException
     */
    private function getComposerHome()
    {
        $home = getenv('COMPOSER_HOME');
        if (!$home) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                if (!getenv('APPDATA')) {
                    throw new \RuntimeException('The APPDATA or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = strtr(getenv('APPDATA'), '\\', '/').'/Composer';
            } else {
                if (!getenv('HOME')) {
                    throw new \RuntimeException('The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = rtrim(getenv('HOME'), '/').'/.composer';
            }
        }

        return $home;
    }
}
