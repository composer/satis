<?php
namespace Composer\Satis\PackageSelection;

use Composer\Package\PackageInterface;

interface LinkProvider {
    public function getLinks(PackageInterface $package);
}
