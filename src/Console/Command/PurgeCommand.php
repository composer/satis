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
use Composer\Json\JsonFile;
use Composer\Satis\PackageSelection\PackageSelection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class PurgeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->getName() ?? $this->setName('purge');
        $this
            ->setDescription('Purge packages')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputArgument('output-dir', InputArgument::OPTIONAL, 'Location where to output built files', null),
                new InputArgument('dry-run', InputArgument::OPTIONAL, 'Dry run, allows to inspect what might be deleted', null),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>purge</info> command deletes useless archive files, depending
                on given json file (satis.json is used by default) and the
                newest json file in the include directory of the given output-dir.

                In your satis.json (or other name you give), you must define
                "archive" argument. You also need to define "homepage" argument or "SATIS_HOMEPAGE" environment variable if you don't use archive "prefix-url" argument.
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $input->getArgument('file');
        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: ' . $configFile . '</error>');

            return 1;
        }
        $config = $file->read();

        /*
         * Check whether archive is defined
         */
        if (!isset($config['archive']) || !isset($config['archive']['directory'])) {
            $output->writeln('<error>You must define "archive" parameter in your ' . $configFile . '</error>');

            return 1;
        }

        $outputDir = $input->getArgument('output-dir') ?? $config['output-dir'] ?? null;
        if (null === $outputDir) {
            throw new \InvalidArgumentException('The output dir must be specified as second argument or be configured inside ' . $input->getArgument('file'));
        }

        $dryRun = (bool) $input->getArgument('dry-run');
        if ($dryRun) {
            $output->writeln('<notice>Dry run enabled, no actual changes will be done.</notice>');
        }

        $packageSelection = new PackageSelection($output, $outputDir, $config, false);
        $packages = $packageSelection->load();

        $satis_homepage = getenv('SATIS_HOMEPAGE');
        $prefix = sprintf(
            '%s/%s/',
            $config['archive']['prefix-url'] ?? (false !== $satis_homepage ? $satis_homepage : $config['homepage']),
            $config['archive']['directory']
        );

        $length = strlen($prefix);
        $needed = [];
        foreach ($packages as $package) {
            if (is_null($package->getDistType())) {
                continue;
            }
            $url = (string) $package->getDistUrl();
            if (substr($url, 0, $length) === $prefix) {
                $needed[] = substr($url, $length);
            }
        }

        $distDirectory = sprintf('%s/%s', $outputDir, $config['archive']['directory']);

        $finder = new Finder();
        $finder
            ->files()
            ->in($distDirectory)
        ;

        if (0 === $finder->count()) {
            $output->writeln('<warning>No archives found.</warning>');

            return 0;
        }

        /** @var SplFileInfo[] $unreferenced */
        $unreferenced = [];
        foreach ($finder as $currentFile) {
            $filename = strtr($currentFile->getRelativePathname(), DIRECTORY_SEPARATOR, '/');
            if (!in_array($filename, $needed, true)) {
                $unreferenced[] = $currentFile;
            }
        }

        if (0 === count($unreferenced)) {
            $output->writeln('<warning>No unreferenced archives found.</warning>');

            return 0;
        }

        foreach ($unreferenced as $currentFile) {
            if (!$dryRun) {
                unlink($currentFile->getPathname());
            }

            $output->writeln(sprintf(
                '<info>Removed archive</info>: <comment>%s</comment>',
                $currentFile->getRelativePathname()
            ));
        }

        if (!$dryRun) {
            $this->removeEmptyDirectories($output, $distDirectory);
        }

        $output->writeln('<info>Done.</info>');

        return 0;
    }

    private function removeEmptyDirectories(OutputInterface $output, string $dir, int $depth = 2): bool
    {
        $empty = true;
        $children = @scandir($dir);

        if (false === $children) {
            return false;
        }

        foreach ($children as $child) {
            if ('.' === $child || '..' === $child) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $child;

            if (is_dir($path)
                && $depth > 0
                && $this->removeEmptyDirectories($output, $path, $depth - 1)
                && rmdir($path)
            ) {
                $output->writeln(sprintf('<info>Removed empty directory</info>: <comment>%s</comment>', $path));
            } else {
                $empty = false;
            }
        }

        return $empty;
    }
}
