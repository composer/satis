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

namespace Composer\Satis\Builder;

use Composer\Package\PackageInterface;

/**
 * @author James Hautot <james@rezo.net>
 */
class WebBuilder extends Builder implements BuilderInterface
{
    private $rootPackage;

    private $dependencies;

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

    public function setRootPackage(PackageInterface $rootPackage)
    {
        $this->rootPackage = $rootPackage;
    }

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
