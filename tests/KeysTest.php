<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Keys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\KeyParser;

final class KeysTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keys(): iterable
    {
        yield 'enter' => ['Enter', "\r"];
        yield 'focus in' => ['FocusIn', "\x1b[I"];
        yield 'focus out' => ['<focusout>', "\x1b[O"];
        yield 'brackets' => ['<Enter>', "\r"];
        yield 'case' => ['ESCAPE', "\x1b"];
        yield 'tab' => ['Tab', "\t"];
        yield 'shift tab' => ['Shift+Tab', "\x1b[Z"];
        yield 'backspace' => ['Backspace', "\x7f"];
        yield 'up' => ['Up', "\x1b[A"];
        yield 'ctrl up' => ['Ctrl+Up', "\x1b[1;5A"];
        yield 'ctrl shift up' => ['Ctrl+Shift+Up', "\x1b[1;6A"];
        yield 'alt left' => ['Alt+Left', "\x1b[1;3D"];
        yield 'home' => ['Home', "\x1b[H"];
        yield 'f1' => ['F1', "\x1bOP"];
        yield 'shift f1' => ['Shift+F1', "\x1b[1;2P"];
        yield 'f5' => ['F5', "\x1b[15~"];
        yield 'ctrl f5' => ['Ctrl+F5', "\x1b[15;5~"];
        yield 'delete' => ['Delete', "\x1b[3~"];
        yield 'page down' => ['PageDown', "\x1b[6~"];
        yield 'ctrl s' => ['Ctrl+S', "\x13"];
        yield 'ctrl c lower' => ['ctrl+c', "\x03"];
        yield 'ctrl space' => ['Ctrl+Space', "\x00"];
        yield 'ctrl alt space' => ['Ctrl+Alt+Space', "\x1b\x00"];
        yield 'alt x' => ['Alt+x', "\x1bx"];
        yield 'alt capital x' => ['Alt+X', "\x1bx"];
        // Beyond ASCII, the code point, lowercased by character rather than by byte
        yield 'super capital e acute' => ['Super+É', "\x1b[233;9u"];
        yield 'ctrl e acute' => ['Ctrl+é', "\x1b[233;5u"];
        yield 'ctrl shift e acute' => ['Ctrl+Shift+é', "\x1b[233;6u"];
        yield 'alt shift x' => ['Alt+Shift+x', "\x1bX"];
        yield 'a capital alone' => ['X', 'X'];
        yield 'shift a' => ['Shift+a', 'A'];
        yield 'shift enter' => ['Shift+Enter', "\x1b[27;2;13~"];
        yield 'plus' => ['+', '+'];
        yield 'alt plus' => ['Alt++', "\x1b+"];
        yield 'unicode' => ['é', 'é'];
        yield 'blanks around' => [' Enter ', "\r"];
        yield 'blanks around brackets' => [' <Enter> ', "\r"];
        yield 'ctrl two' => ['Ctrl+2', "\x00"];
        yield 'blanks around a modifier' => ['Ctrl + S', "\x13"];
        yield 'meta' => ['Meta+x', "\x1bx"];
        yield 'option' => ['Option+x', "\x1bx"];
        yield 'control' => ['Control+s', "\x13"];
        yield 'alt tab' => ['Alt+Tab', "\x1b\t"];
        yield 'shift accented' => ['Shift+é', 'É'];
        yield 'ctrl at' => ['Ctrl+@', "\x00"];
        yield 'ctrl underscore' => ['Ctrl+_', "\x1f"];
        yield 'ctrl bracket' => ['Ctrl+[', "\x1b"];
        yield 'ctrl dash' => ['Ctrl+-', "\x1f"];
        yield 'ctrl question mark' => ['Ctrl+?', "\x7f"];
    }

    #[DataProvider('keys')]
    public function testKey(string $name, string $bytes): void
    {
        $this->assertSame($bytes, Keys::key($name));
    }

    /**
     * The bytes must mean the same key to the Tui key parser.
     *
     * @return iterable<int, array{string, string}>
     */
    public static function parsedKeys(): iterable
    {
        yield ['Enter', Key::ENTER];
        yield ['Shift+Tab', Key::shift(Key::TAB)];
        yield ['Ctrl+S', Key::ctrl('s')];
        yield ['Ctrl+Up', Key::ctrl(Key::UP)];
        yield ['Alt+Left', Key::alt(Key::LEFT)];
        yield ['F5', Key::F5];
        yield ['PageDown', Key::PAGE_DOWN];
        yield ['Page_Up', Key::PAGE_UP];
        yield ['Ctrl+Page_Down', Key::ctrl(Key::PAGE_DOWN)];
        yield ['Super+Left', Key::super(Key::LEFT)];
        yield ['Super+F5', Key::super(Key::F5)];
        yield ['Shift+Enter', Key::shift(Key::ENTER)];
    }

    #[DataProvider('parsedKeys')]
    public function testTheTuiParsesTheBytesAsTheKey(string $name, string $key): void
    {
        $this->assertTrue(new KeyParser()->matches(Keys::key($name), $key), \sprintf('"%s" is not read as "%s".', $name, $key));
    }

    public function testUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown key "Hyper"');

        Keys::key('Hyper');
    }

    public function testUnknownModifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown modifier "Hyper"');

        Keys::key('Hyper+S');
    }

    public function testSequenceMixesTextAndKeys(): void
    {
        $this->assertSame("abc\r\x1b[A<not a key> a < b", Keys::sequence('abc<Enter><Up><not a key> a < b'));
        $this->assertSame("\x13\x1bx", Keys::sequence('<Ctrl+S><Alt+x>'));
        $this->assertSame('<Hyper>', Keys::sequence('<Hyper>'));
    }

    public function testASingleCharacterInBracketsStaysText(): void
    {
        $this->assertSame("<b>bold</b>\r", Keys::sequence('<b>bold</b><Enter>'));
    }

    /**
     * Combinations without a legacy encoding go as CSI u, which the Tui key parser reads.
     *
     * @return iterable<int, array{string, string, string}>
     */
    public static function csiU(): iterable
    {
        yield ['Ctrl+Tab', "\x1b[9;5u", 'ctrl+tab'];
        yield ['Ctrl+Backspace', "\x1b[127;5u", 'ctrl+backspace'];
        yield ['Shift+Backspace', "\x1b[127;2u", 'shift+backspace'];
        yield ['Ctrl+Escape', "\x1b[27;5u", 'ctrl+escape'];
        yield ['Shift+Space', "\x1b[32;2u", 'shift+space'];
        yield ['Ctrl+Shift+Space', "\x1b[32;6u", 'ctrl+shift+space'];
        yield ['Ctrl+Shift+A', "\x1b[97;6u", 'ctrl+shift+a'];
        yield ['Ctrl+Alt+s', "\x1b[115;7u", 'ctrl+alt+s'];
        yield ['Ctrl+,', "\x1b[44;5u", 'ctrl+,'];
        yield ['Ctrl+Shift+-', "\x1b[45;6u", 'ctrl+shift+-'];
        yield ['Ctrl+Shift+=', "\x1b[61;6u", 'ctrl+shift+='];
        yield ['Ctrl+1', "\x1b[49;5u", 'ctrl+1'];
        yield ['Ctrl+Alt+.', "\x1b[46;7u", 'ctrl+alt+.'];
        yield ['Super+S', "\x1b[115;9u", 'super+s'];
        yield ['Cmd+s', "\x1b[115;9u", 'super+s'];
        yield ['Command+Shift+Z', "\x1b[122;10u", 'shift+super+z'];
        yield ['Ctrl+Super+1', "\x1b[49;13u", 'ctrl+super+1'];
        yield ['Super+Enter', "\x1b[13;9u", 'super+enter'];
        yield ['Ctrl+Super+Enter', "\x1b[13;13u", 'ctrl+super+enter'];
        yield ['Super+Tab', "\x1b[9;9u", 'super+tab'];
        yield ['Super+Space', "\x1b[32;9u", 'super+space'];
        yield ['Ctrl+Super+Space', "\x1b[32;13u", 'ctrl+super+space'];
        yield ['Super+Escape', "\x1b[27;9u", 'super+escape'];
    }

    #[DataProvider('csiU')]
    public function testCombinationsWithoutALegacyEncoding(string $name, string $bytes, string $key): void
    {
        $this->assertSame($bytes, Keys::key($name));
        $this->assertTrue(new KeyParser()->matches($bytes, $key), \sprintf('"%s" is not read as "%s".', $name, $key));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unknownKeys(): iterable
    {
        yield 'an unclosed bracket' => ['<ab', 'Unknown key "<ab"'];
        yield 'empty brackets' => ['<>', 'Unknown key "<>"'];
    }

    #[DataProvider('unknownKeys')]
    public function testUnknownKeys(string $name, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        Keys::key($name);
    }
}
