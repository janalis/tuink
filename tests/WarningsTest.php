<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Util\Warnings;
use PHPUnit\Framework\TestCase;

final class WarningsTest extends TestCase
{
    public function testTheWarningIsKeptAndHandled(): void
    {
        \error_clear_last();
        $path = \sys_get_temp_dir().'/tuink-missing-'.\bin2hex(\random_bytes(4));

        [$result, $warning] = Warnings::capture(static fn (): string|false => \file_get_contents($path));

        $this->assertFalse($result);
        $this->assertIsString($warning);
        $this->assertStringContainsString('Failed to open stream', $warning);
        // Handled: PHP's own handler did not see it
        $this->assertNull(\error_get_last());
    }

    public function testNoWarning(): void
    {
        $this->assertSame([42, null], Warnings::capture(static fn (): int => 42));
    }
}
