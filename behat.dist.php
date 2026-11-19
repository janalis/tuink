<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Janalis\Tuink\Context\TuiContext;
use Janalis\Tuink\ServiceContainer\TuinkExtension;

$tuink = [
    'in_process' => ['factory' => 'example/factory.php'],
    'process' => ['command' => ['php', 'example/bin/demo']],
];

return new Config()
    ->withProfile(
        new Profile('default')
            ->withSuite(
                new Suite('default')
                    ->withPaths('%paths.base%/features')
                    ->withContexts(TuiContext::class, InjectedContext::class),
            )
            ->withExtension(new Extension(TuinkExtension::class, $tuink)),
    )
    ->withProfile(new Profile('process')
        ->withExtension(new Extension(TuinkExtension::class, ['default_driver' => 'process'] + $tuink)));
