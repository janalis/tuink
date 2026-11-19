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

return new Config()
    ->withProfile(new Profile('default')
        ->withSuite(
            new Suite('default')
                ->withPaths('%paths.base%/features')
                ->withContexts(TuiContext::class),
        )
        ->withExtension(new Extension(TuinkExtension::class, [
            'timeout' => 0.2,
            'in_process' => ['factory' => '../../../example/factory.php'],
            // The demo installs TuiProbe; an empty TUINK_PROBE stands for an application that does not
            'process' => ['command' => 'php ../../../example/bin/demo', 'env' => ['TUINK_PROBE' => '']],
        ])));
