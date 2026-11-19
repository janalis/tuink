<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, ?string, ?string}>
     */
    public static function sgr(): iterable
    {
        yield 'plain' => ['', [], null, null];
        yield 'reset only' => ["\x1b[m", [], null, null];
        yield 'attributes' => ["\x1b[1;2;3;4;5;7;8;9m", ['bold', 'dim', 'italic', 'underlined', 'blinking', 'reversed', 'hidden', 'struck through'], null, null];
        yield 'curly underline' => ["\x1b[4:3m", ['underlined'], null, null];
        yield 'double underline' => ["\x1b[21m", ['underlined'], null, null];
        yield 'named' => ["\x1b[31;44m", [], 'red', 'blue'];
        yield 'bright' => ["\x1b[92;103m", [], 'bright-green', 'bright-yellow'];
        yield 'palette' => ["\x1b[38;5;208;48;5;9m", [], '208', 'bright-red'];
        yield 'true color' => ["\x1b[1;38;2;95;135;255m", ['bold'], '#5f87ff', null];
        yield 'underline color is skipped' => ["\x1b[58;2;1;2;3;31m", [], 'red', null];
    }

    /**
     * @param list<string> $attributes
     */
    #[DataProvider('sgr')]
    public function testFromSgr(string $sgr, array $attributes, ?string $foreground, ?string $background): void
    {
        $style = Style::fromSgr($sgr);

        $this->assertSame($attributes, $style->attributes);
        $this->assertSame($foreground, $style->foreground);
        $this->assertSame($background, $style->background);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'name' => ['Red', 'red'];
        yield 'bright with a space' => ['bright red', 'bright-red'];
        yield 'bright with an underscore' => ['Bright_Blue', 'bright-blue'];
        yield 'hex' => ['#5F87FF', '#5f87ff'];
        yield 'short hex' => ['#fa0', '#ffaa00'];
        yield 'low index' => ['1', 'red'];
        yield 'bright index' => ['9', 'bright-red'];
        yield 'index' => ['208', '208'];
    }

    #[DataProvider('names')]
    public function testName(string $color, string $name): void
    {
        $this->assertSame($name, Style::name($color));
    }

    public function testUnknownColor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown color "teal"');

        Style::name('teal');
    }

    public function testDescribe(): void
    {
        $this->assertSame('plain', Style::fromSgr('')->describe());
        $this->assertSame('bold, #5f87ff, on blue', Style::fromSgr("\x1b[1;38;2;95;135;255;44m")->describe());
    }
}
