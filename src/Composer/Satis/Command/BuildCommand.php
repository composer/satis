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

use Composer\Package\Loader\ArrayLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Composer\Command\Command;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\Composer;
use Composer\Config;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\PackageInterface;
use Composer\Package\Link;
use Composer\Repository\ComposerRepository;
use Composer\Repository\PlatformRepository;
use Composer\Json\JsonFile;
use Composer\Satis\Satis;
use Composer\Factory;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
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

- "repositories": defines which repositories are searched
  for packages.
- "output-dir": where to output the repository files
  if not provided as an argument when calling build.
- "require-all": boolean, if true, all packages present
  in the configured repositories will be present in the
  dumped satis repository.
- "require": if you do not want to dump all packages,
  you can explicitly require them by name and version.
- "minimum-stability": sets default stability for packages
  (default: dev), see
  http://getcomposer.org/doc/04-schema.md#minimum-stability
- "require-dependencies": if you mark a few packages as
  required to mirror packagist for example, setting this
  to true will make satis automatically require all of your
  requirements' dependencies.
- "require-dev-dependencies": works like require-dependencies
  but requires dev requirements rather than regular ones.
- "config": all config options from composer, see
  http://getcomposer.org/doc/04-schema.md#config
- "output-html": boolean, controls whether the repository
  has an html page as well or not.
- "name": for html output, this defines the name of the
  repository.
- "homepage": for html output, this defines the home URL
  of the repository (where you will host it).
- "twig-template": Location of twig template to use for
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
        $skipErrors = (bool)$input->getOption('skip-errors');

        if (preg_match('{^https?://}i', $configFile)) {
            $rfs = new RemoteFilesystem($this->getIO());
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

        $minimumStability =  isset($config['minimum-stability']) ? $config['minimum-stability'] : 'dev';

        if (!$outputDir = $input->getArgument('output-dir')) {
            $outputDir = isset($config['output-dir']) ? $config['output-dir'] : null;
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside '.$input->getArgument('file'));
        }

        $composer = $this->getApplication()->getComposer(true, $config);
        $packages = $this->selectPackages($composer, $output, $verbose, $requireAll, $requireDependencies, $requireDevDependencies, $minimumStability, $skipErrors, $packagesFilter);

        if ($htmlView = !$input->getOption('no-html-output')) {
            $htmlView = !isset($config['output-html']) || $config['output-html'];
        }

        if (isset($config['archive']['directory'])) {
            $this->dumpDownloads($config, $packages, $input, $output, $outputDir, $skipErrors);
        }

        $filenamePrefix = $outputDir.'/include/all';
        $filename = $outputDir.'/packages.json';
        if(!empty($packagesFilter)) {
            // in case of an active package filter we need to load the dumped packages.json and merge the
            // updated packages in
            $oldPackages = $this->loadDumpedPackages($filename, $packagesFilter);
            $packages += $oldPackages;
            ksort($packages);
        }

        $packageFile = $this->dumpPackageIncludeJson($packages, $output, $filenamePrefix);
        $packageFileHash = hash_file('sha1', $packageFile);

        $includes = array(
            'include/all$'.$packageFileHash.'.json' => array( 'sha1'=>$packageFileHash ),
        );
        
        $this->dumpPackagesJson($includes, $output, $filename);

        if ($htmlView) {
            $dependencies = array();
            foreach ($packages as $package) {
                foreach ($package->getRequires() as $link) {
                    $dependencies[$link->getTarget()][$link->getSource()] = $link->getSource();
                }
            }

            $rootPackage = $composer->getPackage();
            $twigTemplate = isset($config['twig-template']) ? $config['twig-template'] : null;
            $this->dumpWeb($packages, $output, $rootPackage, $outputDir, $twigTemplate, $dependencies);
        }
    }

    private function selectPackages(Composer $composer, OutputInterface $output, $verbose, $requireAll, $requireDependencies, $requireDevDependencies, $minimumStability, $skipErrors, array $packagesFilter = array())
    {
        $selected = array();

        // run over all packages and store matching ones
        $output->writeln('<info>Scanning packages</info>');

        $repos = $composer->getRepositoryManager()->getRepositories();
        $pool = new Pool($minimumStability);
        foreach ($repos as $repo) {
            try {
                $pool->addRepository($repo);
            } catch(\Exception $exception) {
                if(!$skipErrors) {
                    throw $exception;
                }
                $output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }
        }

        if ($requireAll) {
            $links = array();
            $filterForPackages = count($packagesFilter) > 0;

            foreach ($repos as $repo) {
                // collect links for composer repos with providers
                if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                    foreach ($repo->getProviderNames() as $name) {
                        $links[] = new Link('__root__', $name, new MultiConstraint(array()), 'requires', '*');
                    }
                } else {
                    $packages = array();
                    if($filterForPackages) {
                        // apply package filter if defined
                        foreach ($packagesFilter as $filter) {
                            $packages += $repo->findPackages($filter);
                        }
                    } else {
                        // process other repos directly
                        $packages = $repo->getPackages();
                    }

                    foreach ($packages as $package) {
                        // skip aliases
                        if ($package instanceof AliasPackage) {
                            continue;
                        }

                        if ($package->getStability() > BasePackage::$stabilities[$minimumStability]) {
                            continue;
                        }

                        // add matching package if not yet selected
                        if (!isset($selected[$package->getUniqueName()])) {
                            if ($verbose) {
                                $output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                            }
                            $selected[$package->getUniqueName()] = $package;
                        }
                    }
                }
            }
        } else {
            $links = array_values($composer->getPackage()->getRequires());

            // only pick up packages in our filter, if a filter has been set.
            if (count($packagesFilter) > 0) {
                 $links = array_filter($links, function(Link $link) use ($packagesFilter) {
                     return in_array($link->getTarget(), $packagesFilter);
                });
            }

            $links = array_values($links);
        }


        // process links if any
        $depsLinks = array();

        $i = 0;
        while (isset($links[$i])) {
            $link = $links[$i];
            $i++;
            $name = $link->getTarget();
            $matches = $pool->whatProvides($name, $link->getConstraint());

            foreach ($matches as $index => $package) {
                // skip aliases
                if ($package instanceof AliasPackage) {
                    $package = $package->getAliasOf();
                }

                // add matching package if not yet selected
                if (!isset($selected[$package->getUniqueName()])) {
                    if ($verbose) {
                        $output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
                    }
                    $selected[$package->getUniqueName()] = $package;

                    if (!$requireAll) {
                        $required = array();
                        if ($requireDependencies) {
                            $required = $package->getRequires();
                        }
                        if ($requireDevDependencies) {
                            $required = array_merge($required, $package->getDevRequires());
                        }
                        // append non-platform dependencies
                        foreach ($required as $dependencyLink) {
                            $target = $dependencyLink->getTarget();
                            if (!preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $target)) {
                                $linkId = $target.' '.$dependencyLink->getConstraint();
                                // prevent loading multiple times the same link
                                if (!isset($depsLinks[$linkId])) {
                                    $links[] = $dependencyLink;
                                    $depsLinks[$linkId] = true;
                                }
                            }
                        }
                    }
                }
            }

            if (!$matches) {
                $output->writeln('<error>The '.$name.' '.$link->getPrettyConstraint().' requirement did not match any package</error>');
            }
        }

        ksort($selected, SORT_STRING);

        return $selected;
    }

    /**
     * @param array           $config   Directory where to create the downloads in, prefix-url, etc..
     * @param array           $packages Reference to packages so we can rewrite the JSON.
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string          $outputDir
     * @param bool            $skipErrors   If true, any exception while dumping a package will be ignored.
     *
     * @return void
     */
    private function dumpDownloads(array $config, array &$packages, InputInterface  $input, OutputInterface $output, $outputDir, $skipErrors)
    {
        if (isset($config['archive']['absolute-directory'])) {
            $directory = $config['archive']['absolute-directory'];
        } else {
            $directory = sprintf('%s/%s', $outputDir, $config['archive']['directory']);
        }

        $output->writeln(sprintf("<info>Creating local downloads in '%s'</info>", $directory));

        $format = isset($config['archive']['format']) ? $config['archive']['format'] : 'zip';
        $endpoint = isset($config['archive']['prefix-url']) ? $config['archive']['prefix-url'] : $config['homepage'];
        $skipDev = isset($config['archive']['skip-dev']) ? (bool) $config['archive']['skip-dev'] : false;
        $whitelist = isset($config['archive']['whitelist']) ? (array) $config['archive']['whitelist'] : array();
        $blacklist = isset($config['archive']['blacklist']) ? (array) $config['archive']['blacklist'] : array();

        $composerConfig = Factory::createConfig();
        $factory = new Factory;
        $io = new ConsoleIO($input, $output, $this->getApplication()->getHelperSet());
        $io->loadConfiguration($composerConfig);

        /* @var \Composer\Downloader\DownloadManager $downloadManager */
        $downloadManager = $factory->createDownloadManager($io, $composerConfig);

        /* @var \Composer\Package\Archiver\ArchiveManager $archiveManager */
        $archiveManager = $factory->createArchiveManager($composerConfig, $downloadManager);

        $archiveManager->setOverwriteFiles(false);

        /* @var \Composer\Package\CompletePackage $package */
        foreach ($packages as $name => $package) {
            if ('metapackage' === $package->getType()) {
                continue;
            }

            if (true === $skipDev && true === $package->isDev()) {
                $output->writeln(sprintf("<info>Skipping '%s' (is dev)</info>", $name));
                continue;
            }

            $names = $package->getNames();
            if ($whitelist && !array_intersect($whitelist, $names)) {
                $output->writeln(sprintf("<info>Skipping '%s' (is not in whitelist)</info>", $name));
                continue;
            }

            if ($blacklist && array_intersect($blacklist, $names)) {
                $output->writeln(sprintf("<info>Skipping '%s' (is in blacklist)</info>", $name));
                continue;
            }

            $output->writeln(sprintf("<info>Dumping '%s'.</info>", $name));

            try {
                if ('pear-library' === $package->getType()) {
                    // PEAR packages are archives already
                    $filesystem = new Filesystem();
                    $packageName = $archiveManager->getPackageFilename($package);
                    $path =
                        realpath($directory) . '/' . $packageName . '.' .
                        pathinfo($package->getDistUrl(), PATHINFO_EXTENSION);
                    if (!file_exists($path)) {
                        $downloadDir = sys_get_temp_dir() . '/composer_archiver/' . $packageName;
                        $filesystem->ensureDirectoryExists($downloadDir);
                        $downloadManager->download($package, $downloadDir, false);
                        $filesystem->ensureDirectoryExists($directory);
                        $filesystem->rename($downloadDir . '/' . pathinfo($package->getDistUrl(), PATHINFO_BASENAME), $path);
                        $filesystem->removeDirectory($downloadDir);
                    }
                    // Set archive format to `file` to tell composer to download it as is
                    $archiveFormat = 'file';
                } else {
                    $path = $archiveManager->archive($package, $format, $directory);
                    $archiveFormat = $format;
                }
                $archive = basename($path);
                $distUrl = sprintf('%s/%s/%s', $endpoint, $config['archive']['directory'], $archive);
                $package->setDistType($archiveFormat);
                $package->setDistUrl($distUrl);
                $package->setDistSha1Checksum(hash_file('sha1', $path));
                $package->setDistReference($package->getSourceReference());
            } catch(\Exception $exception) {
                if(!$skipErrors) {
                    throw $exception;
                }
                $output->writeln(sprintf("<error>Skipping Exception '%s'.</error>", $exception->getMessage()));
            }
        }
    }

    
    private function dumpPackageIncludeJson(array $packages, OutputInterface $output, $filename)
    {
        $repo = array('packages' => array());
        $dumper = new ArrayDumper;
        foreach ($packages as $package) {
            $repo['packages'][$package->getPrettyName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }
        $repoJson = new JsonFile($filename);
        $repoJson->write($repo);
        $hash = hash_file('sha1', $filename);
        $filenameWithHash = $filename.'$'.$hash.'.json';
        rename($filename, $filenameWithHash);
        $output->writeln("<info>wrote packages json $filenameWithHash</info>");
        return $filenameWithHash;
    }
    
    private function dumpPackagesJson($includes, OutputInterface $output, $filename){
        $repo = array(
            'packages'          => array(),
            'includes'          => $includes,
        );
        
        $output->writeln('<info>Writing packages.json</info>');
        $repoJson = new JsonFile($filename);
        $repoJson->write($repo);
    }

    private function dumpWeb(array $packages, OutputInterface $output, PackageInterface $rootPackage, $directory, $template = null, array $dependencies = array())
    {
        $templateDir = $template ? pathinfo($template, PATHINFO_DIRNAME) : __DIR__.'/../../../../views';
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

        $content = $twig->render($template ? pathinfo($template, PATHINFO_BASENAME) : 'index.html.twig', array(
            'name'          => $name,
            'url'           => $rootPackage->getHomepage(),
            'description'   => $rootPackage->getDescription(),
            'packages'      => $mappedPackages,
            'dependencies'  => $dependencies,
        ));

        file_put_contents($directory.'/index.html', $content);
    }

    private function loadDumpedPackages($filename, array $packagesFilter = array())
    {
        $packages = array();
        $repoJson = new JsonFile($filename);
        $dirName  = dirname($filename);

        if ($repoJson->exists()) {
            $loader       = new ArrayLoader();
            $jsonIncludes = $repoJson->read();
            $jsonIncludes = isset($jsonIncludes['includes']) && is_array($jsonIncludes['includes'])
                ? $jsonIncludes['includes']
                : array();

            foreach ($jsonIncludes as $includeFile => $includeConfig) {
                $includeJson = new JsonFile($dirName . '/' . $includeFile);
                $jsonPackages = $includeJson->read();
                $jsonPackages = isset($jsonPackages['packages']) && is_array($jsonPackages['packages'])
                    ? $jsonPackages['packages']
                    : array();

                foreach ($jsonPackages as $jsonPackage) {
                    if (is_array($jsonPackage)) {
                        foreach ($jsonPackage as $jsonVersion) {
                            if (is_array($jsonVersion)) {
                                if(isset($jsonVersion['name']) && in_array($jsonVersion['name'], $packagesFilter)) {
                                    continue;
                                }
                                $package = $loader->load($jsonVersion);
                                $packages[$package->getUniqueName()] = $package;
                            }
                        }
                    }
                }
            }
        }

        return $packages;
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
