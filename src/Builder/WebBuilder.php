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

namespace Composer\Satis\Builder;

use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Extra\Html\HtmlExtension;
use Twig\Loader\FilesystemLoader;

class WebBuilder extends Builder
{
    /** @var RootPackageInterface Root package used to build the pages. */
    private $rootPackage;
    /** @var array<string, array<string, string>> List of calculated required packages. */
    private $dependencies;
    /** @var Environment */
    private $twig;
    /** @var array<string, string> The labels for the fields to toggle on the front end */
    private $fieldsToToggle = [
        'description' => 'Description',
        'type' => 'Type',
        'keywords' => 'Keywords',
        'homepage' => 'Homepage',
        'license' => 'License',
        'authors' => 'Authors',
        'support' => 'Support',
        'releases' => 'Releases',
        'required-by' => 'Required by',
    ];

    public function dump(array $packages): void
    {
        $mappedPackages = $this->getMappedPackageList($packages);

        $name = $this->rootPackage->getPrettyName();
        if ('__root__' === $name) {
            $name = 'A';
            $this->output->writeln('Define a "name" property in your json config to name the repository');
        }

        if (is_null($this->rootPackage->getHomepage())) {
            $this->output->writeln('Define a "homepage" property in your json config to configure the repository URL');
        }

        $this->setDependencies($packages);

        $this->output->writeln('<info>Writing web view</info>');

        $content = $this->getTwigEnvironment()->render($this->getTwigTemplate(), [
            'name' => $name,
            'url' => $this->rootPackage->getHomepage(),
            'description' => $this->rootPackage->getDescription(),
            'keywords' => $this->rootPackage->getKeywords(),
            'packages' => $mappedPackages,
            'dependencies' => $this->dependencies,
            'fieldsToToggle' => $this->fieldsToToggle,
            'blockIndexing' => !isset($this->config['allow-seo-indexing']) || true !== $this->config['allow-seo-indexing'],
        ]);

        file_put_contents($this->outputDir . '/index.html', $content);
    }

    public function setRootPackage(RootPackageInterface $rootPackage): self
    {
        $this->rootPackage = $rootPackage;

        return $this;
    }

    public function setTwigEnvironment(Environment $twig): self
    {
        $this->twig = $twig;

        return $this;
    }

    private function getTwigEnvironment(): Environment
    {
        if (null === $this->twig) {
            $twigTemplate = $this->config['twig-template'] ?? null;
            $templateDir = !is_null($twigTemplate) ? pathinfo($twigTemplate, PATHINFO_DIRNAME) : __DIR__ . '/../../views';
            $loader = new FilesystemLoader($templateDir);
            $options = false !== getenv('SATIS_TWIG_DEBUG') ? ['debug' => true] : [];

            $this->twig = new Environment($loader, $options);
            $this->twig->addExtension(new HtmlExtension());

            if (false !== getenv('SATIS_TWIG_DEBUG')) {
                $this->twig->addExtension(new DebugExtension());
            }
        }

        return $this->twig;
    }

    private function getTwigTemplate(): string
    {
        $twigTemplate = $this->config['twig-template'] ?? null;

        return !is_null($twigTemplate) ? pathinfo($twigTemplate, PATHINFO_BASENAME) : 'index.html.twig';
    }

    /**
     * Defines the required packages.
     *
     * @param PackageInterface[] $packages List of packages to dump
     *
     * @return $this
     */
    private function setDependencies(array $packages): self
    {
        $dependencies = [];

        foreach ($packages as $package) {
            foreach ($package->getRequires() as $link) {
                $dependencies[$link->getTarget()][$link->getSource()] = $link->getSource();
            }
        }

        $this->dependencies = $dependencies;

        return $this;
    }

    /**
     * Gets a list of packages grouped by name with a list of versions.
     *
     * @param PackageInterface[] $ungroupedPackages List of packages to dump
     *
     * @return array<string, mixed> Grouped list of packages with versions
     */
    private function getMappedPackageList(array $ungroupedPackages): array
    {
        $groupedPackages = $this->groupPackagesByName($ungroupedPackages);

        $mappedPackages = [];
        foreach ($groupedPackages as $name => $packages) {
            $highest = $this->getHighestVersion($packages);

            $mappedPackages[$name] = [
                'highest' => $highest,
                'abandoned' => $highest instanceof CompletePackageInterface ? $highest->isAbandoned() : false,
                'replacement' => $highest instanceof CompletePackageInterface ? $highest->getReplacementPackage() : null,
                'versions' => $this->getDescSortedVersions($packages),
            ];
        }

        return $mappedPackages;
    }

    /**
     * Gets a list of packages grouped by name.
     *
     * @param PackageInterface[] $packages List of packages to dump
     *
     * @return array<string, mixed> List of packages grouped by name
     */
    private function groupPackagesByName(array $packages): array
    {
        $groupedPackages = [];
        foreach ($packages as $package) {
            $groupedPackages[$package->getName()][] = $package;
        }

        return $groupedPackages;
    }

    /**
     * Gets the highest version of packages.
     *
     * @param PackageInterface[] $packages List of packages to dump
     *
     * @return PackageInterface The package with the highest version
     */
    private function getHighestVersion(array $packages): ?PackageInterface
    {
        /** @var PackageInterface|null $highestVersion */
        $highestVersion = null;
        foreach ($packages as $package) {
            if (null === $highestVersion || version_compare($package->getVersion(), $highestVersion->getVersion(), '>=')) {
                $highestVersion = $package;
            }
        }

        return $highestVersion;
    }

    /**
     * Sorts by version the list of packages.
     *
     * @param PackageInterface[] $packages List of packages to dump
     *
     * @return PackageInterface[] Sorted list of packages by version
     */
    private function getDescSortedVersions(array $packages): array
    {
        usort($packages, function (PackageInterface $a, PackageInterface $b) {
            return version_compare($b->getVersion(), $a->getVersion());
        });

        return $packages;
    }
}
