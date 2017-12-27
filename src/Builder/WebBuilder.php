<?php

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

/**
 * Build the web pages.
 *
 * @author James Hautot <james@rezo.net>
 */
class WebBuilder extends Builder
{
    /** @var RootPackageInterface Root package used to build the pages. */
    private $rootPackage;

    /** @var PackageInterface[] List of calculated required packages. */
    private $dependencies;

    /** @var \Twig_Environment */
    private $twig;

    /**
     * {@inheritdoc}
     */
    public function dump(array $packages)
    {
        $mappedPackages = $this->getMappedPackageList($packages);

        $name = $this->rootPackage->getPrettyName();
        if ('__root__' === $name) {
            $name = 'A';
            $this->output->writeln('Define a "name" property in your json config to name the repository');
        }

        if (!$this->rootPackage->getHomepage()) {
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
        ]);

        file_put_contents($this->outputDir . '/index.html', $content);
    }

    /**
     * Defines the root package of the repository.
     *
     * @param RootPackageInterface $rootPackage The root package
     *
     * @return $this
     */
    public function setRootPackage(RootPackageInterface $rootPackage)
    {
        $this->rootPackage = $rootPackage;

        return $this;
    }

    /**
     * Sets the twig environment.
     *
     * @param \Twig_Environment $twig
     *
     * @return $this
     */
    public function setTwigEnvironment(\Twig_Environment $twig)
    {
        $this->twig = $twig;

        return $this;
    }

    /**
     * Gets the twig environment.
     *
     * Creates default if needed.
     *
     * @return \Twig_Environment
     */
    private function getTwigEnvironment()
    {
        if (null === $this->twig) {
            $twigTemplate = $this->config['twig-template'] ?? null;

            $templateDir = $twigTemplate ? pathinfo($twigTemplate, PATHINFO_DIRNAME) : __DIR__ . '/../../views';
            $loader = new \Twig_Loader_Filesystem($templateDir);
            $this->twig = new \Twig_Environment($loader);
        }

        return $this->twig;
    }

    /**
     * Gets the twig template name.
     *
     * @return string
     */
    private function getTwigTemplate()
    {
        $twigTemplate = $this->config['twig-template'] ?? null;

        return $twigTemplate ? pathinfo($twigTemplate, PATHINFO_BASENAME) : 'index.html.twig';
    }

    /**
     * Defines the required packages.
     *
     * @param PackageInterface[] $packages List of packages to dump
     *
     * @return $this
     */
    private function setDependencies(array $packages)
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
     * @param PackageInterface[] $packages List of packages to dump
     *
     * @return array Grouped list of packages with versions
     */
    private function getMappedPackageList(array $packages)
    {
        $groupedPackages = $this->groupPackagesByName($packages);

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
     * @return array List of packages grouped by name
     */
    private function groupPackagesByName(array $packages)
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
    private function getHighestVersion(array $packages)
    {
        /** @var $highestVersion PackageInterface|null */
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
    private function getDescSortedVersions(array $packages)
    {
        usort($packages, function (PackageInterface $a, PackageInterface $b) {
            return version_compare($b->getVersion(), $a->getVersion());
        });

        return $packages;
    }
}
