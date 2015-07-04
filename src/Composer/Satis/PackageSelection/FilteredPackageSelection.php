<?php
namespace Composer\Satis\PackageSelection;

use Composer\Package\Link;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryInterface;

class FilteredPackageSelection implements PackageSelectionInterface
{
    protected $filter;
    protected $selection;

    public function __construct(array $filter, PackageSelection $selection)
    {
        $this->filter = $filter;
        $this->selection = $selection;
    }

    public function considerPackagesFromRepo(RepositoryInterface $repo)
    {
        if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
            $this->selection->considerPackagesFromRepo($repo); // special case
            return;
        }

        foreach ($this->filter as $filter) {
            $this->selection->considerPackages($repo->findPackages($filter));
        }
    }

    public function addLink(Link $link)
    {
        if (!in_array($link->getTarget(), $this->filter)) {
            return;
        }

        $this->selection->addLink($link);
    }

    public function getSelectedPackages()
    {
        return $this->selection->getSelectedPackages();
    }
}
