<?php
namespace Composer\Satis\PackageSelection;

use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\MultiConstraint;
use Composer\Package\PackageInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PackageSelection implements PackageSelectionInterface
{
    /** @var PackageInterface[] A (unique package name)->(Package) map of the selected packages */
    protected $selection = array();

    /** @var Link[] Links to follow/expand  */
    protected $links = array();

    protected $minimumStability = 'dev';

    /** @var LinkResolver */
    protected $resolver;

    /** @var OutputInterface */
    protected $output;

    public function __construct($minimumStability, LinkResolver $resolver, OutputInterface $output)
    {
        $this->minimumStability = $minimumStability;
        $this->resolver = $resolver;
        $this->output = $output;
    }


    public function addLink(Link $link) {
        $this->links[] = $link;
    }

    /**
     * @return \Composer\Package\Link[]
     */
    public function getLinks()
    {
        return $this->links;
    }

    public function considerPackage(PackageInterface $package)
    {
        if ($package instanceof AliasPackage) {
            return;
        }

        if ($package->getStability() > BasePackage::$stabilities[$this->minimumStability]) {
            return;
        }

        $uniqueName = $package->getUniqueName();

        if (!isset($this->selection[$uniqueName])) {
            $this->selection[$uniqueName] = $package;

            if ($this->output->isVerbose()) {
                $this->output->writeln('Selected '.$package->getPrettyName().' ('.$package->getPrettyVersion().')');
            }
        }
    }

    public function considerPackages($packages)
    {
        foreach ($packages as $package) {
            $this->considerPackage($package);
        }
    }

    /**
     * @param RepositoryInterface $repo
     */
    public function considerPackagesFromRepo(RepositoryInterface $repo)
    {
        if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
            foreach ($repo->getProviderNames() as $name) {
                $this->addLink(new Link('__root__', $name, new MultiConstraint(array()), 'requires', '*'));
            }
            return;
        }

        $this->considerPackages($repo->getPackages());
    }

    public function getSelectedPackages()
    {
        $this->resolver->work($this);

        ksort($this->selection, SORT_STRING);

        return $this->selection;
    }
}
