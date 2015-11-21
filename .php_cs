<?php

$header = <<<EOF
This file is part of composer/statis.

(c) Composer <https://github.com/composer>

For the full copyright and license information, please view
the LICENSE file that was distributed with this source code.
EOF;

$finder = Symfony\CS\Finder\DefaultFinder::create()
    ->files()
    ->name('*.php')
    ->exclude('vendor')
    ->in(__DIR__)
;

/* fabpot/php-cs-fixer:^2.0-dev */
return Symfony\CS\Config\Config::create('Satis', 'Satis style guide')
    ->setUsingCache(false)
    ->setUsingLinter(true)
    ->setRiskyAllowed(true)
    ->setRules(array(
        '@PSR2' => true,
        'concat_without_spaces' => true,
        'duplicate_semicolon' => true,
        'extra_empty_lines' => true,
        'header_comment' => array('header' => $header),
        'include' => true,
        'long_array_syntax' => true,
        'method_separation' => true,
        'multiline_array_trailing_comma' => true,
        'namespace_no_leading_whitespace' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_empty_lines_after_phpdocs' => true,
        'object_operator' => true,
        'operators_spaces' => true,
        'ordered_use' => true,
        'phpdoc_indent' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_package' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_type_to_var' => true,
        'return' => true,
        'remove_leading_slash_use' => true,
        'remove_lines_between_uses' => true,
        'single_array_no_trailing_comma' => true,
        'single_blank_line_before_namespace' => true,
        'spaces_cast' => true,
        'standardize_not_equal' => true,
        'ternary_spaces' => true,
        'unused_use' => true,
        'whitespacy_lines' => true,
    ))
    ->finder($finder)
;
