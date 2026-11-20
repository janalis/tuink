<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Box;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase
{
    public function testFindsTheFrameAndItsInside(): void
    {
        $grid = self::grid(<<<'SCREEN'
            text above
              ╭─ Help ───╮ right
              │ one      │
              │ two  2   │
              ╰──────────╯
            SCREEN);

        $box = Box::find($grid, title: 'Help');

        $this->assertNotNull($box);
        $this->assertSame([1, 2, 4, 13], [$box->top, $box->left, $box->bottom, $box->right]);
        $this->assertSame(" one\n two  2", $box->text($grid));
        $this->assertSame([3, 9], $box->locate($grid, text: '2'));
        $this->assertSame([2, 4], $box->locate($grid, text: 'one'));
        $this->assertNull($box->locate($grid, text: 'right'));
        $this->assertNull($box->locate($grid, text: 'above'));
        $this->assertNull($box->locate($grid, text: 'Help'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frames(): iterable
    {
        yield 'light' => ["┌Help┐\n│ x  │\n└────┘"];
        yield 'heavy' => ["┏Help┓\n┃ x  ┃\n┗━━━━┛"];
        yield 'double' => ["╔Help╗\n║ x  ║\n╚════╝"];
        yield 'mixed' => ["╒Help╕\n├ x  ┤\n╘════╛"];
        yield 'dashed' => ["┍Help┑\n┆ x  ┊\n┕━━━━┙"];
        yield 'rounded with a gap' => ["╭ Help ╮\n│ x    │\n│      │\n╰──────╯"];
    }

    #[DataProvider('frames')]
    public function testFrameStyles(string $screen): void
    {
        $grid = self::grid($screen);

        $this->assertStringStartsWith(' x', Box::find($grid, title: 'Help')?->text($grid) ?? 'not found');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFrames(): iterable
    {
        yield 'no frame' => ['Help'];
        yield 'no left corner' => ["─Help┐\n│ x  │\n└────┘"];
        yield 'no right corner' => ["┌Help─\n│ x  │\n└────┘"];
        yield 'title inside a frame' => ["┌────┐\n│Help│\n└────┘"];
        yield 'title after a right corner' => ["┐ Help ┐\n│      │\n└──────┘"];
        yield 'title before a left corner' => ["┌ Help ┌\n│      │\n└──────┘"];
        yield 'a side between' => ["┌│Help┐\n││    │\n└─────┘"];
        yield 'no bottom' => ["┌Help┐\n│ x  │"];
        yield 'a broken left side' => ["┌Help┐\n  x  │\n└────┘"];
        yield 'a broken right side' => ["┌Help┐\n│ x   \n└────┘"];
        yield 'a bottom without its right corner' => ["┌Help┐\n│ x  │\n└────┤\n└────┘"];
        yield 'a bottom without its left corner' => ["┌Help┐\n│ x  │\n├────┘\n└────┘"];
        yield 'the right corner at the edge' => ['┌Help'];
        yield 'the left corner at the edge' => ['Help┐'];
        yield 'a rounded right corner between' => ["╭─╮Help─╮\n│       │\n╰───────╯"];
        yield 'a heavy side between' => ["╭┃Help─╮\n│      │\n╰──────╯"];
        yield 'a rounded left corner after' => ["┌Help╭─┐\n│      │\n└──────┘"];
        yield 'a heavy side after' => ["┌Help┃─┐\n│      │\n└──────┘"];
        yield 'a left corner after, then a right one' => ["┌Help┌─┐\n│      │\n└──────┘"];
        yield 'a side before, then a left corner' => ["┌│Help─┐\n│      │\n└──────┘"];
    }

    #[DataProvider('notFrames')]
    public function testWhatIsNotAFrame(string $screen): void
    {
        $this->assertNull(Box::find(self::grid($screen), title: 'Help'));
    }

    public function testTheFirstTitleThatIsAFrameWins(): void
    {
        $grid = self::grid(<<<'SCREEN'
            Help is text here
            ┌Help┐ ┌Help┐
            │ a  │ │ b  │
            └────┘ └────┘
            SCREEN);

        $this->assertSame(' a', Box::find($grid, title: 'Help')?->text($grid));
    }

    public function testASecondFrameOnTheSameRow(): void
    {
        $grid = self::grid(<<<'SCREEN'
            ┌Help┐ ┌Help┐
            │ a    │ b  │
            └────┘ └────┘
            SCREEN);

        $this->assertSame(' b', Box::find($grid, title: 'Help')?->text($grid));
    }

    public function testTitlesMadeOfBorderCharacters(): void
    {
        $grid = self::grid("┌│x┐\n│ab│\n└──┘");
        $this->assertSame('ab', Box::find($grid, title: '│x')?->text($grid));

        $grid = self::grid("┌x│┐\n│ab│\n└──┘");
        $this->assertSame('ab', Box::find($grid, title: 'x│')?->text($grid));
    }

    public function testTextTouchingTheBorders(): void
    {
        $grid = self::grid("┌Help┐\n│abcd│\n└─x──┘");
        $box = Box::find($grid, title: 'Help');

        $this->assertNotNull($box);
        $this->assertSame('abcd', $box->text($grid));
        $this->assertSame([1, 1], $box->locate($grid, text: 'a'));
        $this->assertSame([1, 4], $box->locate($grid, text: 'd'));
        $this->assertNull($box->locate($grid, text: '│a'));
        $this->assertNull($box->locate($grid, text: 'd│'));
        $this->assertNull($box->locate($grid, text: 'x'));
    }

    public function testAnEmptyFrame(): void
    {
        $grid = self::grid("┌Help┐\n└────┘");

        $this->assertSame('', Box::find($grid, title: 'Help')?->text($grid));
        $this->assertNull(Box::find($grid, title: 'Help')?->locate($grid, text: 'x'));
    }

    public function testWideCharacters(): void
    {
        $grid = self::grid("╭─ 世界 ─╮\n│ 漢字 a │\n╰────────╯");

        $box = Box::find($grid, title: '世界');
        $this->assertNotNull($box);
        $this->assertSame(' 漢字 a', $box->text($grid));
        $this->assertSame([1, 7], $box->locate($grid, text: 'a'));
        $this->assertSame([1, 2], $box->locate($grid, text: '漢字'));
    }

    public function testOccurrences(): void
    {
        $cells = ['a', 'b', 'a', 'b', '世', '', 'x'];

        $this->assertSame([[0, 2], [2, 4]], Box::occurrences($cells, text: 'ab', from: 0, to: 7));
        $this->assertSame([[2, 4]], Box::occurrences($cells, text: 'ab', from: 1, to: 7));
        $this->assertSame([[0, 2]], Box::occurrences($cells, text: 'ab', from: 0, to: 3));
        $this->assertSame([], Box::occurrences($cells, text: 'ab', from: 0, to: 1));
        $this->assertSame([[3, 6]], Box::occurrences($cells, text: 'b世', from: 0, to: 7));
        $this->assertSame([[4, 6]], Box::occurrences($cells, text: '世', from: 0, to: 7));
        $this->assertSame([[4, 6]], Box::occurrences($cells, text: '世', from: 0, to: 6));
        $this->assertSame([[4, 5]], Box::occurrences($cells, text: '世', from: 0, to: 5));
        $this->assertSame([[4, 7]], Box::occurrences($cells, text: '世x', from: 0, to: 7));
        $this->assertSame([], Box::occurrences($cells, text: 'xy', from: 0, to: 7));
        $this->assertSame([[0, 2], [2, 4]], Box::occurrences($cells, text: 'ab'));
        $this->assertSame([[6, 7]], Box::occurrences($cells, text: 'x'));
    }

    /**
     * A grid as Session::getGrid() builds it: a cell per column, '' after a wide character.
     *
     * @return list<list<string>>
     */
    private static function grid(string $screen): array
    {
        $grid = [];
        foreach (\explode("\n", $screen) as $line) {
            $cells = [];
            foreach (\mb_str_split($line) as $char) {
                $cells[] = $char;
                if (2 === \mb_strwidth($char)) {
                    $cells[] = '';
                }
            }
            $grid[] = \array_pad($cells, length: 30, value: ' ');
        }

        return $grid;
    }
}
