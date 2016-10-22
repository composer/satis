<?php

$header = <<<EOF
This file is part of composer/satis.

(c) Composer <https://github.com/composer>

For the full copyright and license information, please view
the LICENSE file that was distributed with this source code.
EOF;

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
    ->in(__DIR__.'/views')
;

return PhpCsFixer\Config::create('Satis', 'Satis style guide')
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setRules([
        // default
        '@PSR2' => true,
        '@Symfony' => true,
        // additionally
        'concat_with_spaces' => true,
        'concat_without_spaces' => false,
        'header_comment' => ['header' => $header],
        'no_unused_imports' => false,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_imports' => true,
        'phpdoc_align' => false,
        'phpdoc_order' => true,
        'phpdoc_params' => false,
        'phpdoc_summary' => false,
        'short_array_syntax' => true,
        'simplified_null_return' => false,
    ])
    ->finder($finder)
;
