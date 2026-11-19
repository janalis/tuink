<?php

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
        yield 'alt x' => ['Alt+x', "\x1bx"];
        yield 'shift a' => ['Shift+a', 'A'];
        yield 'shift enter' => ['Shift+Enter', "\x1b[27;2;13~"];
        yield 'plus' => ['+', '+'];
        yield 'alt plus' => ['Alt++', "\x1b+"];
        yield 'unicode' => ['é', 'é'];
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
        yield ['Shift+Enter', Key::shift(Key::ENTER)];
    }

    #[DataProvider('parsedKeys')]
    public function testTheTuiParsesTheBytesAsTheKey(string $name, string $key): void
    {
        $this->assertTrue((new KeyParser())->matches(Keys::key($name), $key), \sprintf('"%s" is not read as "%s".', $name, $key));
    }

    public function testUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown key "Hyper"');

        Keys::key('Hyper');
    }

    public function testUnknownModifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown modifier "Super"');

        Keys::key('Super+S');
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
        yield ['Ctrl+Shift+A', "\x1b[97;6u", 'ctrl+shift+a'];
        yield ['Ctrl+Alt+s', "\x1b[115;7u", 'ctrl+alt+s'];
    }

    #[DataProvider('csiU')]
    public function testCombinationsWithoutALegacyEncoding(string $name, string $bytes, string $key): void
    {
        $this->assertSame($bytes, Keys::key($name));
        $this->assertTrue((new KeyParser())->matches($bytes, $key), \sprintf('"%s" is not read as "%s".', $name, $key));
    }
}
