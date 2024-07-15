<?php

declare(strict_types=1);

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
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Console\Application as ComposerApplication;
use Composer\Json\JsonFile;
use Composer\Json\JsonValidationException;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Satis\Builder\ArchiveBuilder;
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\Builder\WebBuilder;
use Composer\Satis\Console\Application as SatisApplication;
use Composer\Satis\PackageSelection\PackageSelection;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use JsonSchema\Validator;
use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->getName() ?? $this->setName('build');
        $this
            ->setDescription('Builds a composer repository out of a json file')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Packages that should be built. If not provided, all packages are built.', null),
                new InputOption('repository-url', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only update the repository at given URL(s).', null),
                new InputOption('repository-strict', null, InputOption::VALUE_NONE, 'Also apply the repository filter when resolving dependencies'),
                new InputOption('no-html-output', null, InputOption::VALUE_NONE, 'Turn off HTML view'),
                new InputOption('skip-errors', null, InputOption::VALUE_NONE, 'Skip Download or Archive errors'),
                new InputOption('stats', null, InputOption::VALUE_NONE, 'Display the download progress bar'),
                new InputOption('minify', null, InputOption::VALUE_NONE, 'Minify output'),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>build</info> command reads the given json file
                (satis.json is used by default) and outputs a composer
                repository in the given output-dir.

                The json config file accepts the following keys:

                - <info>"repositories"</info>: defines which repositories are searched
                  for packages.
                - <info>"repositories-dep"</info>: define additional repositories for dependencies
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
                - <info>"only-dependencies"</info>: only require dependencies - choose this if you want to build
                  a mirror of your project's dependencies without building packages for the main project repositories.
                - <info>"config"</info>: all config options from composer, see
                  http://getcomposer.org/doc/04-schema.md#config
                - <info>"strip-hosts"</info>: boolean or an array of domains, IPs, CIDR notations, '/local' (=localnet and other reserved)
                  or '/private' (=private IPs) to be stripped from the output. If set and non-false, local file paths are removed too.
                - <info>"output-html"</info>: boolean, controls whether the repository
                  has an html page as well or not.
                - <info>"name"</info>: for html output, this defines the name of the
                  repository.
                - <info>"homepage"</info>: for html output and urls in meta data files, this defines the home URL
                  of the repository (where you will host it). Build command allows this to be overloaded in SATIS_HOMEPAGE environment variable.
                - <info>"twig-template"</info>: Location of twig template to use for
                  building the html output.
                - <info>"allow-seo-indexing"</info>: Allow the generated html output to be indexed by search engines.
                - <info>"abandoned"</info>: Packages that are abandoned. As the key use the
                  package name, as the value use true or the replacement package.
                - <info>"blacklist"</info>: Packages and versions which should be excluded from the final package list.
                - <info>"only-best-candidates"</info>: Returns a minimal set of dependencies needed to satisfy the configuration.
                  The resulting satis repository will contain only one or two versions of each project.
                - <info>"notify-batch"</info>: Allows you to specify a URL that will
                  be called every time a user installs a package, see
                  https://getcomposer.org/doc/05-repositories.md#notify-batch
                - <info>"include-filename"</info> Specify filename instead of default include/all${SHA1_HASH}.json
                - <info>"archive"</info> archive configuration, see https://getcomposer.org/doc/articles/handling-private-packages-with-satis.md#downloads
                EOT
            );
    }

    /**
     * @throws JsonValidationException
     * @throws ParsingException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $configFile = $input->getArgument('file');
        $packagesFilter = $input->getArgument('packages');
        $repositoryUrl = $input->getOption('repository-url');
        $skipErrors = (bool) $input->getOption('skip-errors');
        $minify = (bool) $input->getOption('minify');

        // load auth.json authentication information and pass it to the io interface
        $io = $this->getIO();
        $io->loadConfiguration($this->getConfiguration());
        $config = [];

        if (1 === preg_match('{^https?://}i', $configFile)) {
            $rfs = new RemoteFilesystem($io, $this->getConfiguration());
            $host = parse_url($configFile, PHP_URL_HOST);
            if (is_string($host)) {
                $contents = $rfs->getContents($host, $configFile, false);
                if (is_string($contents)) {
                    $config = JsonFile::parseJson($contents, $configFile);
                }
            }
        } else {
            $file = new JsonFile($configFile);
            if (!$file->exists()) {
                $output->writeln('<error>File not found: ' . $configFile . '</error>');

                return 1;
            }
            $config = $file->read();
        }

        try {
            $this->check($configFile);
        } catch (JsonValidationException $e) {
            foreach ($e->getErrors() as $error) {
                $output->writeln(sprintf('<error>%s</error>', $error));
            }
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
        } catch (ParsingException $e) {
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
        } catch (\UnexpectedValueException $e) {
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
        }

        if ((null !== $repositoryUrl && [] !== $repositoryUrl) && count($packagesFilter) > 0) {
            throw new \InvalidArgumentException('The arguments "package" and "repository-url" can not be used together.');
        }

        // disable packagist by default
        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        $outputDir = $input->getArgument('output-dir');
        if (!(bool) $outputDir) {
            $outputDir = $config['output-dir'] ?? null;
        }

        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside ' . $input->getArgument('file'));
        }

        $homepage = getenv('SATIS_HOMEPAGE');
        if (false !== $homepage) {
            $config['homepage'] = $homepage;
            $output->writeln(sprintf('<notice>Homepage config used from env SATIS_HOMEPAGE: %s</notice>', $homepage));
        }

        /** @var SatisApplication|ComposerApplication $application */
        $application = $this->getApplication();
        if ($application instanceof SatisApplication) {
            $composer = $application->getComposerWithConfig($config);
        } else {
            $composer = $application->getComposer(true);
        }

        if (is_null($composer)) {
            throw new \Exception('Unable to get Composer instance');
        }

        $composerConfig = $composer->getConfig();
        if (!$application instanceof SatisApplication) {
            $composerConfig->merge($config);
            $composer->setConfig($composerConfig);
        }

        // Feed repo manager with satis' repos
        $manager = $composer->getRepositoryManager();
        foreach ($config['repositories'] as $repo) {
            $manager->addRepository($manager->createRepository($repo['type'], $repo, $repo['name'] ?? null));
        }
        // Make satis' config file pretend it is the root package
        $parser = new VersionParser();
        /**
         * In standalone case, the RootPackageLoader assembles an internal VersionGuesser with a broken ProcessExecutor
         * Workaround by explicitly injecting a ProcessExecutor with enableAsync;
         */
        $process = new ProcessExecutor($io);
        $process->enableAsync();
        $guesser = new VersionGuesser($composerConfig, $process, $parser);
        $loader = new RootPackageLoader($manager, $composerConfig, $parser, $guesser);
        $satisConfigAsRootPackage = $loader->load($config);
        $composer->setPackage($satisConfigAsRootPackage);

        $packageSelection = new PackageSelection($output, $outputDir, $config, $skipErrors);

        if (null !== $repositoryUrl && [] !== $repositoryUrl) {
            $packageSelection->setRepositoriesFilter($repositoryUrl, (bool) $input->getOption('repository-strict'));
        } else {
            $packageSelection->setPackagesFilter($packagesFilter);
        }

        $packages = $packageSelection->select($composer, $verbose);

        if (isset($config['archive']['directory'])) {
            $downloads = new ArchiveBuilder($output, $outputDir, $config, $skipErrors);
            $downloads->setComposer($composer);
            $downloads->setInput($input);
            $downloads->dump($packages);
        }

        $packages = $packageSelection->clean();

        if ($packageSelection->hasFilterForPackages() || $packageSelection->hasRepositoriesFilter()) {
            // in case of an active filter we need to load the dumped packages.json and merge the
            // updated packages in
            $oldPackages = $packageSelection->load();
            $packages += $oldPackages;
            ksort($packages);
        }

        $packagesBuilder = new PackagesBuilder($output, $outputDir, $config, $skipErrors, $minify);
        $packagesBuilder->dump($packages);

        $htmlView = (bool) $input->getOption('no-html-output');
        if (!$htmlView) {
            $htmlView = !isset($config['output-html']) || (bool) $config['output-html'];
        }

        if ($htmlView) {
            $web = new WebBuilder($output, $outputDir, $config, $skipErrors);
            $web->setRootPackage($composer->getPackage());
            $web->dump($packages);
        }

        return 0;
    }

    private function getConfiguration(): Config
    {
        $config = new Config();

        // add dir to the config
        $config->merge(['config' => ['home' => $this->getComposerHome()]]);

        // load global auth file
        $file = new JsonFile($config->get('home') . '/auth.json');
        if ($file->exists()) {
            $config->merge(['config' => $file->read()]);
        }
        $config->setAuthConfigSource(new JsonConfigSource($file, true));

        return $config;
    }

    private function getComposerHome(): string
    {
        $home = getenv('COMPOSER_HOME');
        if (false === $home) {
            if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
                $appData = getenv('APPDATA');
                if (false === $appData) {
                    throw new \RuntimeException('The APPDATA or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = strtr($appData, '\\', '/') . '/Composer';
            } else {
                $homeEnv = getenv('HOME');
                if (false === $homeEnv) {
                    throw new \RuntimeException('The HOME or COMPOSER_HOME environment variable must be set for composer to run correctly');
                }
                $home = rtrim($homeEnv, '/') . '/.composer';
            }
        }

        return $home;
    }

    /**
     * @throws ParsingException         if the json file has an invalid syntax
     * @throws JsonValidationException  if the json file doesn't match the schema
     * @throws \UnexpectedValueException if the json file is not UTF-8
     */
    private function check(string $configFile): bool
    {
        $content = file_get_contents($configFile);

        $parser = new JsonParser();
        $result = is_string($content) ? $parser->lint($content) : new ParsingException('Could not read file contents from "' . $configFile . '"');
        if (null === $result) {
            if (defined('JSON_ERROR_UTF8') && JSON_ERROR_UTF8 === json_last_error()) {
                throw new \UnexpectedValueException('"' . $configFile . '" is not UTF-8, could not parse as JSON');
            }

            $data = json_decode((string) $content);

            $schemaFile = __DIR__ . '/../../../res/satis-schema.json';
            $schemaFileContents = file_get_contents($schemaFile);
            if (false === $schemaFileContents) {
                throw new ParsingException('Could not read file contents from "' . $schemaFile . '"');
            }
            $schema = json_decode($schemaFileContents);
            $validator = new Validator();
            $validator->check($data, $schema);

            if (!$validator->isValid()) {
                $errors = [];
                foreach ((array) $validator->getErrors() as $error) {
                    $errors[] = ($error['property'] ? $error['property'] . ' : ' : '') . $error['message'];
                }

                throw new JsonValidationException('The json config file does not match the expected JSON schema', $errors);
            }

            return true;
        }

        throw new ParsingException('"' . $configFile . '" does not contain valid JSON' . "\n" . $result->getMessage(), $result->getDetails());
    }
}
