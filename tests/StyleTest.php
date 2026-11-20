<?php

declare(strict_types=1);

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
        yield 'text before the codes' => ["x\x1b[1m", [], null, null];
        yield 'text after the codes' => ["\x1b[1mx", [], null, null];
        yield 'an attribute written twice' => ["\x1b[1;4;21;3m", ['bold', 'underlined', 'italic'], null, null];
        yield 'a later color wins' => ["\x1b[31;32;41;1m", ['bold'], 'green', 'red'];
        yield 'a truncated palette color' => ["\x1b[38;5m", [], null, null];
        yield 'a truncated true color' => ["\x1b[48;2;1;2m", [], null, null];
        yield 'a true color after other codes' => ["\x1b[1;38;2;1;2;3m", ['bold'], '#010203', null];
        yield 'an unknown color mode' => ["\x1b[38;9;1;2;3m", ['struck through', 'bold', 'dim', 'italic'], null, null];
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
        yield 'padded index' => ['07', 'white'];
        yield 'blanks around' => [' red ', 'red'];
    }

    #[DataProvider('names')]
    public function testName(string $color, string $name): void
    {
        $this->assertSame($name, Style::name($color));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownColors(): iterable
    {
        yield 'name' => ['teal'];
        yield 'text before hex' => ['x#ffffff'];
        yield 'text after hex' => ['#ffffffx'];
        yield 'text before short hex' => ['x#fff'];
        yield 'text after short hex' => ['#fffx'];
    }

    #[DataProvider('unknownColors')]
    public function testUnknownColor(string $color): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown color');

        Style::name($color);
    }

    public function testDescribe(): void
    {
        $this->assertSame('plain', Style::fromSgr('')->describe());
        $this->assertSame('bold, #5f87ff, on blue', Style::fromSgr("\x1b[1;38;2;95;135;255;44m")->describe());
    }
}
