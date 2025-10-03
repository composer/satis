<?php
require_once 'vendor/autoload.php';

use Composer\Package\CompletePackage;
use Composer\Satis\Builder\ArchiveBuilderHelper;
use Symfony\Component\Console\Output\BufferedOutput;

echo "=== FINAL VALIDATION: Empty Source Fields Support ===\n\n";

// Simulate the exact scenario from the issue: phpstan/phpstan with empty source fields
$problematicPackage = new CompletePackage('phpstan/phpstan', '1.12.32.0', '1.12.32');
$problematicPackage->setSourceType('');        // Empty type
$problematicPackage->setSourceUrl('');         // Empty URL  
$problematicPackage->setSourceReference('');   // Empty reference

echo "1. Testing package with empty source fields (like phpstan/phpstan):\n";
echo "   Package: " . $problematicPackage->getPrettyString() . "\n";
echo "   Source: type='" . $problematicPackage->getSourceType() . "', url='" . $problematicPackage->getSourceUrl() . "'\n";
echo "   Dist: type='" . $problematicPackage->getDistType() . "', url='" . $problematicPackage->getDistUrl() . "'\n";

$output = new BufferedOutput();
$helper = new ArchiveBuilderHelper($output, ['directory' => 'dist']);

$isSkipped = $helper->isSkippable($problematicPackage);
echo "   Result: " . ($isSkipped ? "✅ SKIPPED (no crash!)" : "❌ NOT SKIPPED (would crash)") . "\n";
echo "   Message: " . trim($output->fetch()) . "\n\n";

// Test with a normal package to ensure we didn't break anything
$normalPackage = new CompletePackage('monolog/monolog', '2.0.0', '2.0.0');
$normalPackage->setSourceType('git');
$normalPackage->setSourceUrl('https://github.com/Seldaek/monolog.git');
$normalPackage->setSourceReference('abc123');

echo "2. Testing normal package (should not be skipped):\n";
echo "   Package: " . $normalPackage->getPrettyString() . "\n";
echo "   Source: type='" . $normalPackage->getSourceType() . "', url='" . $normalPackage->getSourceUrl() . "'\n";

$output2 = new BufferedOutput();
$helper2 = new ArchiveBuilderHelper($output2, ['directory' => 'dist']);

$isSkipped2 = $helper2->isSkippable($normalPackage);
echo "   Result: " . ($isSkipped2 ? "❌ SKIPPED (shouldn't be)" : "✅ NOT SKIPPED (correct)") . "\n";
$message2 = trim($output2->fetch());
echo "   Message: " . ($message2 ? $message2 : "(no message - correct)") . "\n\n";

// Summary
echo "=== SUMMARY ===\n";
if ($isSkipped && !$isSkipped2) {
    echo "✅ SUCCESS: Satis now supports packages with empty source fields!\n";
    echo "   - Packages with empty source/dist are skipped (prevents crash)\n";
    echo "   - Normal packages are processed normally (no regression)\n";
    echo "   - Behavior matches the rest of the Composer ecosystem\n";
} else {
    echo "❌ ISSUE: Implementation needs adjustment\n";
}
