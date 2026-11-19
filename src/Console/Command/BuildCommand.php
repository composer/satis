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
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Satis\Builder\ArchiveBuilder;
use Composer\Satis\Builder\PackagesBuilder;
use Composer\Satis\Builder\WebBuilder;
use Composer\Satis\Console\Application as SatisApplication;
use Composer\Satis\Console\IO\FailedUpdateRecordingIO;
use Composer\Satis\PackageSelection\PackageSelection;
use Composer\Semver\Constraint\Constraint;
use Composer\Util\Filesystem;
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
                new InputOption('branch', null, InputOption::VALUE_REQUIRED, 'Only update the given branch or version range of the specified package(s)'),
                new InputOption('no-html-output', null, InputOption::VALUE_NONE, 'Turn off HTML view'),
                new InputOption('html-only', null, InputOption::VALUE_NONE, 'Only rebuild the HTML view from the packages of the previous build in output-dir, without scanning repositories'),
                new InputOption('skip-errors', null, InputOption::VALUE_NONE, 'Skip Download or Archive errors'),
                new InputOption('progress', null, InputOption::VALUE_NONE, 'Show progress bars while downloading and archiving dist files'),
                new InputOption('stats', null, InputOption::VALUE_NONE, 'Deprecated, use --progress instead'),
                new InputOption('minify', null, InputOption::VALUE_NONE, 'Minify output'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Exit with a non-zero code when a VCS mirror failed to update, a root requirement matched no package or --skip-errors skipped an error'),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>build</info> command reads the given json file
                (satis.json is used by default) and outputs a composer
                repository in the given output-dir.

                With <info>--html-only</info>, only the web view (index.html) is
                rebuilt, from the packages of the previous build in output-dir.
                Repositories are not scanned and packages.json, the package
                metadata and archives are left untouched. This is meant for
                iterating on a custom twig-template, and builds the web view
                even when output-html is false.

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
                  repository. It must be a valid package name such as acme/satis.
                - <info>"homepage"</info>: for html output and urls in meta data files, this defines the home URL
                  of the repository (where you will host it). Build command allows this to be overloaded in SATIS_HOMEPAGE environment variable.
                - <info>"twig-template"</info>: Location of twig template to use for
                  building the html output.
                - <info>"allow-seo-indexing"</info>: Allow the generated html output to be indexed by search engines.
                - <info>"abandoned"</info>: Packages that are abandoned. As the key use the
                  package name, as the value use true or the replacement package.
                - <info>"blacklist"</info>: Packages and versions which should be excluded from the final package list.
                - <info>"only-best-candidates"</info>: Selects only the best matching version for each dependency constraint.
                  Each constraint is resolved on its own, without the solver, so the result is not guaranteed to be installable.
                - <info>"notify-batch"</info>: Allows you to specify a URL that will
                  be called every time a user installs a package, see
                  https://getcomposer.org/doc/05-repositories.md#notify-batch
                - <info>"include-filename"</info> Specify filename instead of default include/all${SHA1_HASH}.json
                - <info>"archive"</info> archive configuration, see https://composer.github.io/satis/using#downloads

                With <info>--strict</info> the build still runs to completion, but exits with a
                non-zero code when a VCS mirror failed to update (its package information may be
                outdated) or a package listed in <info>"require"</info> matched no package. Combined
                with <info>--skip-errors</info>, it also exits with a non-zero code when an error was
                skipped, after writing the output.
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
        $branch = $input->getOption('branch');
        $skipErrors = (bool) $input->getOption('skip-errors');
        $minify = (bool) $input->getOption('minify');
        $htmlOnly = (bool) $input->getOption('html-only');
        $strict = (bool) $input->getOption('strict');

        if ((bool) $input->getOption('stats')) {
            $output->writeln('<warning>The "--stats" option is deprecated, use "--progress" instead.</warning>');
        }

        // load auth.json authentication information and pass it to the io interface
        $io = $this->getIO();
        $io->loadConfiguration($this->getConfiguration());

        // the VCS drivers only warn when updating a mirror fails, record those warnings for --strict
        $failedUpdates = null;
        if ($strict) {
            $io = $failedUpdates = new FailedUpdateRecordingIO($io);
        }

        $config = [];
        $localConfigFile = null;

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
            $localConfigFile = $configFile;
        }

        // the errors skipped by --skip-errors, which fail a --strict build
        $skippedErrors = [];

        try {
            $this->check($configFile);
        } catch (JsonValidationException|ParsingException|\UnexpectedValueException $e) {
            if ($e instanceof JsonValidationException) {
                foreach ($e->getErrors() as $error) {
                    $output->writeln(sprintf('<error>%s</error>', $error));
                }
            }
            if (!$skipErrors) {
                throw $e;
            }
            $output->writeln(sprintf('<warning>%s: %s</warning>', get_class($e), $e->getMessage()));
            $skippedErrors[] = $e->getMessage();
        }

        if ($htmlOnly) {
            $conflicts = array_filter([
                '"--no-html-output"' => (bool) $input->getOption('no-html-output'),
                'package arguments' => count($packagesFilter) > 0,
                '"--repository-url"' => null !== $repositoryUrl && [] !== $repositoryUrl,
                '"--branch"' => null !== $branch,
                '"--strict"' => $strict,
            ]);
            if ([] !== $conflicts) {
                throw new \InvalidArgumentException(sprintf('The "--html-only" option cannot be used together with %s.', implode(', ', array_keys($conflicts))));
            }
        }

        if (null !== $branch && (null !== $repositoryUrl && [] !== $repositoryUrl)) {
            throw new \InvalidArgumentException('The "--branch" and "--repository-url" options cannot be used together.');
        }

        if ((null !== $repositoryUrl && [] !== $repositoryUrl) && count($packagesFilter) > 0) {
            throw new \InvalidArgumentException('The arguments "package" and "repository-url" can not be used together.');
        }

        if (null !== $branch && 0 === count($packagesFilter)) {
            throw new \InvalidArgumentException('The "--branch" option requires at least one package to be specified.');
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
            if (null !== $localConfigFile) {
                $config = $this->mergeLocalAuthConfig($config, $localConfigFile, $output);
            }
            $composer = $application->getComposerWithConfig($config, $io);
        } else {
            $composer = $application->getComposer(true);
        }

        if (is_null($composer)) {
            throw new \Exception('Unable to get Composer instance');
        }

        $composerConfig = $composer->getConfig();
        if ($application instanceof SatisApplication) {
            // When the working directory has a composer.json, Composer's BaseCommand::initialize() has already created
            // a Composer instance from it, and getComposerWithConfig() only merges $config into that instance's config.
            // Load the result into the IO, so the credentials of the Satis config and its auth.json reach it as well.
            $io->loadConfiguration($composerConfig);
        } else {
            $composerConfig->merge($config);
            $composer->setConfig($composerConfig);
        }

        // Feed repo manager with satis' repos and process disable directives
        $manager = $composer->getRepositoryManager();
        if (null !== $failedUpdates) {
            // When Satis runs as a plugin, Composer already created the manager with its own IO
            (new \ReflectionProperty($manager, 'io'))->setValue($manager, $failedUpdates);
        }
        $disabledRepoNames = [];
        foreach ($config['repositories'] as $repo) {
            if (is_array($repo) && !isset($repo['type']) && 1 === count($repo) && false === current($repo)) {
                $disabledRepoNames[] = (string) key($repo);
                continue;
            }
            if (!isset($repo['type'])) {
                continue;
            }
            $manager->addRepository($manager->createRepository($repo['type'], $repo, $repo['name'] ?? null));
        }
        if ([] !== $disabledRepoNames) {
            $this->removeDisabledRepositories($manager, $disabledRepoNames, $output, (bool) $verbose);
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
        $this->deduplicateRepositories($manager, $output, (bool) $verbose);

        $packageSelection = new PackageSelection($output, $outputDir, $config, $skipErrors, $io);

        if ($htmlOnly) {
            $packages = $packageSelection->load();
            if ([] === $packages) {
                $packagesJson = new JsonFile($outputDir . '/packages.json');
                $rootConfig = $packagesJson->exists() ? $packagesJson->read() : [];
                // without includes, packages.json lists the packages in available-packages only, which
                // available-package-patterns replaces
                if (isset($rootConfig['available-package-patterns']) && !isset($rootConfig['includes']) && !isset($rootConfig['providers'])) {
                    $output->writeln(sprintf('<error>The packages.json of the previous build in %s lists no packages: with includes disabled and available-package-patterns set, the previous build cannot be read back</error>', $outputDir));
                } else {
                    $output->writeln(sprintf('<error>No packages found in a previous build in %s, run a full build first</error>', $outputDir));
                }

                return 1;
            }

            $this->dumpWebView($output, $outputDir, $config, $skipErrors, $composer->getPackage(), $packages);

            return 0;
        }

        if (null !== $repositoryUrl && [] !== $repositoryUrl) {
            $packageSelection->setRepositoriesFilter($repositoryUrl, (bool) $input->getOption('repository-strict'));
        } else {
            $packageSelection->setPackagesFilter($packagesFilter);
        }

        $packages = $packageSelection->select($composer, $verbose);

        if (null !== $branch && count($packagesFilter) > 0) {
            $packages = $this->filterPackagesByBranch($packages, $packagesFilter, $branch, $output);
        }

        if (isset($config['archive']['directory'])) {
            $downloads = new ArchiveBuilder($output, $outputDir, $config, $skipErrors);
            $downloads->setComposer($composer);
            $downloads->setProgress((bool) $input->getOption('progress') || (bool) $input->getOption('stats'));
            $downloads->setIO($io);

            // Composer extracts archives into a temporary directory below the vendor-dir, which defaults to vendor/
            // in the working directory. Use a temporary vendor-dir instead, unless one is configured or Satis runs as
            // a plugin of a project, which has a vendor-dir of its own.
            $temporaryVendorDir = null;
            if ($application instanceof SatisApplication && !isset($config['config']['vendor-dir'])) {
                $temporaryVendorDir = sys_get_temp_dir() . '/satis-vendor-' . bin2hex(random_bytes(8));
                $composerConfig->merge(['config' => ['vendor-dir' => $temporaryVendorDir]]);
            }

            try {
                $downloads->dump($packages);
            } finally {
                if (null !== $temporaryVendorDir) {
                    (new Filesystem())->removeDirectory($temporaryVendorDir);
                }
            }
            $skippedErrors = array_merge($skippedErrors, $downloads->getSkippedErrors());
        }

        $packages = $packageSelection->clean();

        if (null !== $branch && count($packagesFilter) > 0) {
            $packages = $this->filterPackagesByBranch($packages, $packagesFilter, $branch, $output);
        }

        if ($packageSelection->hasFilterForPackages() || $packageSelection->hasRepositoriesFilter()) {
            if (null !== $branch && count($packagesFilter) > 0) {
                $packageSelection->setPackagesFilter([]);
            }
            // in case of an active filter we need to load the dumped packages.json and merge the
            // updated packages in
            $oldPackages = $packageSelection->load();

            if (null !== $branch && count($packagesFilter) > 0) {
                $oldTargetPackages = array_filter(
                    $oldPackages,
                    static fn (PackageInterface $package): bool => in_array($package->getName(), $packagesFilter, true)
                );
                $oldMatchingPackages = $this->filterPackagesByBranch($oldTargetPackages, $packagesFilter, $branch, $output);
                $oldPackages = array_diff_key($oldPackages, $oldMatchingPackages);
            }

            $packages += $oldPackages;
            ksort($packages);
        }

        $packagesBuilder = new PackagesBuilder($output, $outputDir, $config, $skipErrors, $minify);
        $packagesBuilder->dump($packages);

        if ($this->shouldBuildHtml($input, $config)) {
            $this->dumpWebView($output, $outputDir, $config, $skipErrors, $composer->getPackage(), $packages);
        }

        if (null !== $failedUpdates) {
            return $this->reportStrictFailures(
                $output,
                $failedUpdates->getFailedUpdates(),
                $packageSelection->getUnmatchedRootRequirements(),
                array_merge($skippedErrors, $packageSelection->getSkippedErrors()),
            );
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $config
     * @param PackageInterface[]   $packages
     */
    private function dumpWebView(OutputInterface $output, string $outputDir, array $config, bool $skipErrors, RootPackageInterface $rootPackage, array $packages): void
    {
        $web = new WebBuilder($output, $outputDir, $config, $skipErrors);
        $web->setRootPackage($rootPackage);
        $web->dump($packages);
    }

    /**
     * @param list<string> $failedUpdates
     * @param list<string> $unmatchedRootRequirements
     * @param list<string> $skippedErrors
     */
    private function reportStrictFailures(OutputInterface $output, array $failedUpdates, array $unmatchedRootRequirements, array $skippedErrors): int
    {
        if ([] === $failedUpdates && [] === $unmatchedRootRequirements && [] === $skippedErrors) {
            return 0;
        }

        $output->writeln('<error>Strict mode: the build is incomplete</error>');
        if ([] !== $failedUpdates) {
            $output->writeln('VCS mirrors that failed to update:');
            foreach ($failedUpdates as $failedUpdate) {
                $output->writeln(' - ' . $failedUpdate);
            }
        }
        if ([] !== $unmatchedRootRequirements) {
            $output->writeln('Root requirements that matched no package:');
            foreach ($unmatchedRootRequirements as $requirement) {
                $output->writeln(' - ' . $requirement);
            }
        }
        if ([] !== $skippedErrors) {
            $output->writeln('Errors skipped by --skip-errors:');
            foreach (array_unique($skippedErrors) as $error) {
                $output->writeln(' - ' . $error);
            }
        }

        return 1;
    }

    /**
     * Remove repositories matching disable directives from the RepositoryManager.
     *
     * Handles the {"name": false} pattern used to disable named repositories
     * (e.g. {"packagist.org": false}).
     *
     * @param string[] $disabledRepoNames
     */
    private function removeDisabledRepositories(RepositoryManager $manager, array $disabledRepoNames, OutputInterface $output, bool $verbose = false): void
    {
        $refl = new \ReflectionProperty($manager, 'repositories');
        $repositories = $refl->getValue($manager);

        $removedNames = [];

        $repositories = array_values(array_filter(
            $repositories,
            static function ($repo) use ($disabledRepoNames, &$removedNames): bool {
                if (!$repo instanceof ConfigurableRepositoryInterface) {
                    return true;
                }
                $url = rtrim((string) preg_replace('{^https?://}i', '', $repo->getRepoConfig()['url'] ?? ''), '/');
                foreach ($disabledRepoNames as $name) {
                    $normalizedName = rtrim((string) preg_replace('{^https?://}i', '', $name), '/');
                    if ('' !== $normalizedName && ($url === $normalizedName || str_ends_with($url, '.' . $normalizedName))) {
                        $removedNames[] = $name;

                        return false;
                    }
                }

                return true;
            }
        ));

        $refl->setValue($manager, $repositories);

        if ($verbose) {
            foreach ($removedNames as $name) {
                $output->writeln(sprintf('<info>Removed repository %s (disabled by config)</info>', $name));
            }
        }
    }

    /**
     * Drop repositories that are registered more than once in the RepositoryManager.
     *
     * The repositories of a Satis config reach the manager up to three times: through
     * Factory::create() in standalone mode, through the loop above, and through
     * RootPackageLoader::load(). Each duplicate makes PackageSelection scan the very
     * same VCS repository again - one more `git remote update`, one more
     * `git remote show origin` and one more composer.json read per tag and branch -
     * which multiplies build time and load on the git servers.
     */
    private function deduplicateRepositories(RepositoryManager $manager, OutputInterface $output, bool $verbose = false): void
    {
        $refl = new \ReflectionProperty($manager, 'repositories');
        $repositories = $refl->getValue($manager);

        $seen = [];
        $removedKeys = [];

        $repositories = array_values(array_filter(
            $repositories,
            static function ($repo) use (&$seen, &$removedKeys): bool {
                if (!$repo instanceof ConfigurableRepositoryInterface) {
                    return true;
                }

                $repoConfig = $repo->getRepoConfig();
                $url = $repoConfig['url'] ?? null;
                $key = is_string($url) && '' !== $url
                    ? ($repoConfig['type'] ?? '') . ' ' . rtrim($url, '/')
                    : (string) json_encode($repoConfig);

                if (isset($seen[$key])) {
                    $removedKeys[] = $key;

                    return false;
                }

                $seen[$key] = true;

                return true;
            }
        ));

        $refl->setValue($manager, $repositories);

        if ($verbose) {
            foreach (array_count_values($removedKeys) as $key => $count) {
                $output->writeln(sprintf('<info>Removed %d duplicate registration(s) of repository %s</info>', $count, $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function shouldBuildHtml(InputInterface $input, array $config): bool
    {
        if ((bool) $input->getOption('no-html-output')) {
            return false;
        }

        if (isset($config['output-html'])) {
            return (bool) $config['output-html'];
        }

        return true;
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

    /**
     * Merge the auth.json next to a local Satis config file into its "config" block, the way Composer treats the
     * auth.json next to composer.json: the auth file wins over the config block, per host. getComposerWithConfig()
     * then merges the result over the global auth.json into the Composer config.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function mergeLocalAuthConfig(array $config, string $configFile, OutputInterface $output): array
    {
        $realConfigFile = realpath($configFile);
        $authFile = new JsonFile(dirname(false !== $realConfigFile ? $realConfigFile : $configFile) . '/auth.json');
        if (!$authFile->exists()) {
            return $config;
        }

        $output->writeln(sprintf('<info>Loading auth config from %s</info>', $authFile->getPath()), OutputInterface::VERBOSITY_VERBOSE);
        // like Factory::createComposer() does with the auth.json next to composer.json, warn and load it anyway
        try {
            $authFile->validateSchema(JsonFile::AUTH_SCHEMA);
        } catch (JsonValidationException $e) {
            $output->writeln(sprintf(
                '<warning>%s, this may result in errors and should be resolved:%s - %s</warning>',
                $e->getMessage(),
                PHP_EOL,
                implode(PHP_EOL . ' - ', $e->getErrors())
            ));
        }

        $configBlock = isset($config['config']) && is_array($config['config']) ? $config['config'] : [];
        foreach ($authFile->read() as $key => $value) {
            $configBlock[$key] = isset($configBlock[$key]) && is_array($configBlock[$key]) && is_array($value)
                ? array_merge($configBlock[$key], $value)
                : $value;
        }
        $config['config'] = $configBlock;

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

            $data = json_decode($content);

            $schemaFile = __DIR__ . '/../../../res/satis-schema.json';
            $schemaFileContents = file_get_contents($schemaFile);
            if (false === $schemaFileContents) {
                throw new ParsingException('Could not read file contents from "' . $schemaFile . '"');
            }
            $schema = json_decode($schemaFileContents);
            $validator = new Validator();
            $validator->validate($data, $schema);

            if (!$validator->isValid()) {
                $errors = [];
                foreach ($validator->getErrors() as $error) {
                    $errors[] = ($error['property'] ? $error['property'] . ' : ' : '') . $error['message'];
                }

                throw new JsonValidationException('The json config file does not match the expected JSON schema', $errors);
            }

            return true;
        }

        throw new ParsingException('"' . $configFile . '" does not contain valid JSON' . "\n" . $result->getMessage(), $result->getDetails());
    }

    /**
     * Filters packages to only those matching the given branch name or version range.
     * Packages not in the target set are kept as-is.
     *
     * A value such as "main" is matched against dev branch versions ("dev-main"),
     * while a version range such as "1.x" is matched against the package version.
     *
     * @param PackageInterface[] $packages
     * @param string[]           $packageNames
     *
     * @return PackageInterface[]
     */
    private function filterPackagesByBranch(array $packages, array $packageNames, string $branch, OutputInterface $output): array
    {
        $parser = new VersionParser();
        $expectedDevVersion = 'dev-' . $branch;

        $constraint = null;
        try {
            $constraint = $parser->parseConstraints($branch);
        } catch (\UnexpectedValueException $e) {
            $constraint = null;
        }

        $filtered = [];

        foreach ($packages as $uniqueName => $package) {
            if (!in_array($package->getName(), $packageNames, true)) {
                $filtered[$uniqueName] = $package;

                continue;
            }

            $prettyVersion = $package->getPrettyVersion();

            $matched = $expectedDevVersion === $prettyVersion;

            if (!$matched && null !== $constraint && $constraint->matches(new Constraint('==', $package->getVersion()))) {
                $matched = true;
            }

            if ($matched) {
                $filtered[$uniqueName] = $package;
                $output->writeln(
                    sprintf('<info>Matched branch version: %s (%s)</info>', $package->getPrettyName(), $prettyVersion),
                    OutputInterface::VERBOSITY_VERBOSE
                );

                continue;
            }

            if (0 === strpos($prettyVersion, 'dev-') && $package->isDev()) {
                continue;
            }
        }

        return $filtered;
    }
}
