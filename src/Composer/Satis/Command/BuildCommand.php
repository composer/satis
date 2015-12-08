<?php

/*
 * This file is part of composer/statis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\Command;

use Composer\Command\Command;
use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Json\JsonFile;
use Composer\Satis\Builder\ArchiveBuilder;
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\Builder\WebBuilder;
use Composer\Satis\PackageSelection\PackageSelection;
use Composer\Util\RemoteFilesystem;
use Composer\Util\Filesystem;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that should be built, if not provided all packages are.', null),
                new InputOption('output-dir', null, InputOption::VALUE_OPTIONAL, 'Location where to output built files', null),
                new InputOption('repository-url', null, InputOption::VALUE_OPTIONAL, 'Only update the repository at given url', null),
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
- <info>"abandoned"</info>: Packages that are abandoned. As the key use the
  package name, as the value use true or the replacement package.
- <info>"notify-batch"</info>: Allows you to specify a URL that will
  be called every time a user installs a package, see
  https://getcomposer.org/doc/05-repositories.md#notify-batch
EOT
            );
    }

    /**
     * @param InputInterface $input The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');
        $configFile = $input->getArgument('file');
        $packagesFilter = $input->getArgument('packages');
        $repositoryUrl = $input->getOption('repository-url');
        $skipErrors = (bool) $input->getOption('skip-errors');

        if ($repositoryUrl !== null && count($packagesFilter) > 0) {
            throw new \InvalidArgumentException('The arguments "package" and "repository-url" can not be used together.');
        }

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

        if (!$outputDir = $input->getOption('output-dir')) {
            if (isset($config['output-dir'])) {
                $outputDir = $config['output-dir'];
            } elseif (Filesystem::isLocalPath($configFile)) {
                $outputDir = dirname(realpath($configFile));
            }
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified by option --output-dir or be configured inside '.$input->getArgument('file'));
        }

        $composer = $this->getApplication()->getComposer(true, $config);
        $packageSelection = new PackageSelection($output, $outputDir, $config, $skipErrors);

        if ($repositoryUrl !== null) {
            $packageSelection->setRepositoryFilter($repositoryUrl);
        } else {
            $packageSelection->setPackagesFilter($packagesFilter);
        }

        $packages = $packageSelection->select($composer, $verbose);

        if (isset($config['archive']['directory'])) {
            $downloads = new ArchiveBuilder($output, $outputDir, $config, $skipErrors);
            $downloads->setComposer($composer);
            $downloads->dump($packages);
        }

        if ($packageSelection->hasFilterForPackages() || $packageSelection->hasRepositoryFilter()) {
            // in case of an active filter we need to load the dumped packages.json and merge the
            // updated packages in
            $oldPackages = $packageSelection->load();
            $packages += $oldPackages;
            ksort($packages);
        }

        $packagesBuilder = new PackagesBuilder($output, $outputDir, $config, $skipErrors);
        $updated = $packagesBuilder->dump($packages);
        if (!$updated) {
            return;
        }

        if ($htmlView = !$input->getOption('no-html-output')) {
            $htmlView = !isset($config['output-html']) || $config['output-html'];
        }

        if ($htmlView) {
            $web = new WebBuilder($output, $outputDir, $config, $skipErrors);
            $web->setRootPackage($composer->getPackage());
            $web->dump($packages);
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
     * @throws \RuntimeException
     *
     * @return string
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
