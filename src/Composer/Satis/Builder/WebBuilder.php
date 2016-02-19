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

use Composer\Package\PackageInterface;

/**
 * Build the web pages.
 *
 * @author James Hautot <james@rezo.net>
 */
class WebBuilder extends Builder implements BuilderInterface
{
    /** @var PackageInterface Main datas to build the pages. */
    private $rootPackage;

    /** @var array List of calculated required packages. */
    private $dependencies;

    /**
     * Build the web pages.
     *
     * @param array $packages List of packages to dump
     */
    public function dump(array $packages)
    {
        $twigTemplate = isset($this->config['twig-template']) ? $this->config['twig-template'] : null;

        $templateDir = $twigTemplate ? pathinfo($twigTemplate, PATHINFO_DIRNAME) : __DIR__.'/../../../../views';
        $loader = new \Twig_Loader_Filesystem($templateDir);
        $twig = new \Twig_Environment($loader);

        $mappedPackages = $this->getMappedPackageList($packages);

        $name = $this->rootPackage->getPrettyName();
        if ($name === '__root__') {
            $name = 'A';
            $this->output->writeln('Define a "name" property in your json config to name the repository');
        }

        if (!$this->rootPackage->getHomepage()) {
            $this->output->writeln('Define a "homepage" property in your json config to configure the repository URL');
        }

        $this->setDependencies($packages);

        $this->output->writeln('<info>Writing web view</info>');

        $content = $twig->render($twigTemplate ? pathinfo($twigTemplate, PATHINFO_BASENAME) : 'index.html.twig', array(
            'name' => $name,
            'url' => $this->rootPackage->getHomepage(),
            'description' => $this->rootPackage->getDescription(),
            'packages' => $mappedPackages,
            'dependencies' => $this->dependencies,
        ));

        file_put_contents($this->outputDir.'/index.html', $content);
    }

    /**
     * Defines de main datas of the repository.
     *
     * @param PackageInterface $rootPackage [description]
     */
    public function setRootPackage(PackageInterface $rootPackage)
    {
        $this->rootPackage = $rootPackage;

        return $this;
    }

    /**
     * Defines the required packages.
     *
     * @param array $packages List of packages to dump
     */
    private function setDependencies(array $packages)
    {
        $dependencies = array();
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
     * @param array $packages List of packages to dump
     *
     * @return array Grouped list of packages with versions
     */
    private function getMappedPackageList(array $packages)
    {
        $groupedPackages = $this->groupPackagesByName($packages);

        $mappedPackages = array();
        foreach ($groupedPackages as $name => $packages) {
            $highest = $this->getHighestVersion($packages);

            $mappedPackages[$name] = array(
                'highest' => $highest,
                'abandoned' => $highest->isAbandoned(),
                'replacement' => $highest->getReplacementPackage(),
                'versions' => $this->getDescSortedVersions($packages),
            );
        }

        return $mappedPackages;
    }

    /**
     * Gets a list of packages grouped by name.
     *
     * @param array $packages List of packages to dump
     *
     * @return array List of packages grouped by name
     */
    private function groupPackagesByName(array $packages)
    {
        $groupedPackages = array();
        foreach ($packages as $package) {
            $groupedPackages[$package->getName()][] = $package;
        }

        return $groupedPackages;
    }

    /**
     * Gets the highest version of packages.
     *
     * @param array $packages List of packages to dump
     *
     * @return string The highest version of a package
     */
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

    /**
     * Sorts by version the list of packages.
     *
     * @param array $packages List of packages to dump
     *
     * @return array Sorted list of packages by version
     */
    private function getDescSortedVersions(array $packages)
    {
        usort($packages, function ($a, $b) {
            return version_compare($b->getVersion(), $a->getVersion());
        });

        return $packages;
    }
}
