<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context;

use Janalis\Tuink\Session;
use Janalis\Tuink\Tuink;

/**
 * Implements TuinkAwareContextInterface.
 */
trait TuinkAwareTrait
{
    private ?Tuink $tuink = null;

    public function setTuink(Tuink $tuink): void
    {
        $this->tuink = $tuink;
    }

    protected function getTuink(): Tuink
    {
        return $this->tuink ?? throw new \LogicException(\sprintf('The Tuink manager was not injected into "%s"; is the Tuink extension enabled in behat.yml?', static::class));
    }

    protected function getSession(): Session
    {
        return $this->getTuink()->getSession();
    }
}
