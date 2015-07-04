<?php
namespace Composer\Satis\PackageSelection;
use Composer\Package\PackageInterface;

class RequireDevDepsLinkProvider implements LinkProvider
{
    public function getLinks(PackageInterface $package)
    {
        return $package->getDevRequires();
    }
}
