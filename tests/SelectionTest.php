<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Selector\Selection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase
{
    /**
     * @return iterable<string, array{Selection, bool}>
     */
    public static function others(): iterable
    {
        yield 'the same' => [new Selection('Default', value: 'theme', index: 1), true];
        yield 'another label' => [new Selection('Other', value: 'theme', index: 1), false];
        yield 'another value' => [new Selection('Default', value: 'font', index: 1), false];
        yield 'another position' => [new Selection('Default', value: 'theme', index: 3), false];
        yield 'no value nor position' => [new Selection('Default'), false];
    }

    #[DataProvider('others')]
    public function testTheSameItemHasTheSameLabelValueAndPosition(Selection $other, bool $same): void
    {
        $this->assertSame($same, new Selection('Default', value: 'theme', index: 1)->is($other));
    }
}
