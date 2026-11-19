<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context;

use Behat\Behat\Context\Context;
use Janalis\Tuink\Tuink;

/**
 * A context that receives the Tuink manager when it is created.
 */
interface TuinkAwareContext extends Context
{
    public function setTuink(Tuink $tuink): void;
}
