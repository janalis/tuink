<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/example'])
    ->append([__FILE__, __DIR__.'/behat.dist.php', __DIR__.'/example/bin/demo']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'header_comment' => [
            'header' => "This file is part of the tuink package.\n\nFor the full copyright and license information, please view the LICENSE\nfile that was distributed with this source code.",
        ],
    ])
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setFinder($finder);
