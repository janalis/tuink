<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Exception;

/**
 * Thrown when a step needs a capability the current driver does not have.
 */
final class UnsupportedDriverException extends \LogicException
{
}
