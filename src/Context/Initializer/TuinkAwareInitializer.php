<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context\Initializer;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Initializer\ContextInitializer;
use Janalis\Tuink\Context\TuinkAwareContext;
use Janalis\Tuink\Tuink;

/**
 * Injects the Tuink manager into contexts that implement TuinkAwareContext.
 */
final class TuinkAwareInitializer implements ContextInitializer
{
    public function __construct(
        private readonly Tuink $tuink,
    ) {
    }

    public function initializeContext(Context $context): void
    {
        if ($context instanceof TuinkAwareContext) {
            $context->setTuink($this->tuink);
        }
    }
}
