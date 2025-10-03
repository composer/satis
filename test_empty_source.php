<?php
require_once 'vendor/autoload.php';

use Composer\Package\CompletePackage;

// Create a package with empty source fields like phpstan/phpstan
$package = new CompletePackage('test/package', '1.0.0.0', '1.0.0');
$package->setSourceType('');
$package->setSourceUrl('');
$package->setSourceReference('');

// Let's see what methods we can call to check source/dist status
echo "Source type: '" . $package->getSourceType() . "'\n";
echo "Source URL: '" . $package->getSourceUrl() . "'\n";
echo "Source reference: '" . $package->getSourceReference() . "'\n";
echo "Dist type: '" . $package->getDistType() . "'\n";
echo "Dist URL: '" . $package->getDistUrl() . "'\n";
echo "Dist reference: '" . $package->getDistReference() . "'\n";

// Check if package has valid source or dist
echo "Has source: " . (($package->getSourceType() && $package->getSourceUrl()) ? 'yes' : 'no') . "\n";
echo "Has dist: " . (($package->getDistType() && $package->getDistUrl()) ? 'yes' : 'no') . "\n";
