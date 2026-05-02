<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Migration fixtures are deliberately formatted to match what
    // iManager 1.x's `var_export()` actually emits (long-array syntax,
    // no strict_types, var_export-style spacing). Don't reformat them.
    ->notPath('Fixtures/v1')
    ->append([__DIR__ . '/bin/imanager']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'phpdoc_align' => true,
        'phpdoc_separation' => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
