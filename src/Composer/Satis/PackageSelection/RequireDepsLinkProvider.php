<?php
namespace Composer\Satis\PackageSelection;

use Composer\Package\PackageInterface;

class RequireDepsLinkProvider implements LinkProvider
{
    public function getLinks(PackageInterface $package)
    {
        return $package->getRequires();
    }
}
