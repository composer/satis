<?php
namespace Composer\Satis\PackageSelection;

use Composer\Package\Link;
use Composer\Repository\RepositoryInterface;

interface PackageSelectionInterface
{
    public function addLink(Link $link);
    public function considerPackagesFromRepo(RepositoryInterface $repo);
    public function getSelectedPackages();
}
