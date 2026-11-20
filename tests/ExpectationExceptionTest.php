<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Exception\ExpectationException;
use PHPUnit\Framework\TestCase;

final class ExpectationExceptionTest extends TestCase
{
    public function testTheMessageEndsWithTheScreen(): void
    {
        $this->assertSame("Not found.\n\nScreen", new ExpectationException('Not found.', screenDump: 'Screen')->getMessage());
    }
}
