<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Exception;

/**
 * Thrown when the screen does not show what a step expects; the message ends with the screen.
 */
final class ExpectationException extends \RuntimeException
{
    public function __construct(string $message, string $screenDump)
    {
        parent::__construct($message."\n\n".$screenDump);
    }
}
