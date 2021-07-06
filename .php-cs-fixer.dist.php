<?php declare(strict_types=1);

$header = <<<EOF
This file is part of composer/satis.

(c) Composer <https://github.com/composer>

For the full copyright and license information, please view
the LICENSE file that was distributed with this source code.
EOF;

$finder = (new PhpCsFixer\Finder())
    ->files()
    ->name('*.php')
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/views',
    ])
;

return (new PhpCsFixer\Config('satis'))
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        // default
        '@PSR2' => true,
        '@Symfony' => true,
        // additionally
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => false,
        'declare_strict_types' => true,
        'header_comment' => ['header' => $header],
        'no_unused_imports' => false,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'ordered_imports' => true,
        'phpdoc_align' => false,
        'phpdoc_order' => true,
        'phpdoc_summary' => false,
        'simplified_null_return' => false,
        'ternary_to_null_coalescing' => true,
    ])
;
