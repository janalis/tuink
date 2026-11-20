<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Launch;
use Janalis\Tuink\Mouse;
use Janalis\Tuink\Session;
use Janalis\Tuink\Tests\Fixtures\FakeDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The workspace, file, terminal and frame checks, against output the tests write.
 */
final class ChecksTest extends TestCase
{
    private FakeDriver $driver;
    private Session $session;

    protected function setUp(): void
    {
        $this->driver = new FakeDriver();
        $this->session = new Session($this->driver, 30, 8, timeout: 0.05, quietPeriod: 0.01);
    }

    protected function tearDown(): void
    {
        $this->session->close();
    }

    public function testTheLaunchGoesToTheDriverWithTheWorkspace(): void
    {
        $this->assertEquals(new Launch(), $this->session->getLaunch());
        $launch = new Launch(['a'], ['A' => '1'], kitty: true);
        $this->session->setLaunch($launch);
        $this->assertSame($launch, $this->session->getLaunch());

        $this->session->start();

        $this->assertEquals($launch->withWorkspace($this->session->getWorkspace()->path), $this->driver->launch);
    }

    public function testTheWorkspaceOutlivesRestartsButNotClose(): void
    {
        $workspace = $this->session->getWorkspace();
        $this->assertSame($workspace, $this->session->getWorkspace());
        $workspace->write('a.txt', content: 'x');

        $this->session->start();
        $this->session->restart();
        $this->assertSame(2, $this->driver->starts);
        $this->assertSame($workspace, $this->session->getWorkspace());
        $this->assertSame('x', $workspace->read('a.txt'));

        $this->session->close();
        $this->assertFalse($this->driver->isStarted());
        $this->assertDirectoryDoesNotExist($workspace->path);
        $this->assertNotSame($workspace, $this->session->getWorkspace());
        $this->session->close();
    }

    public function testFocusEvents(): void
    {
        $this->session->start();
        $this->session->press('FocusOut');
        $this->session->press('FocusIn');
        $this->session->press('<focusin>');

        $this->assertSame("\x1b[O\x1b[I\x1b[I", $this->driver->input);
    }

    public function testFocusEventsNeedTheApplication(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageIsOrContains('The application is not started.');

        $this->session->press('FocusIn');
    }

    public function testTheGrid(): void
    {
        $this->session->start();
        $this->driver->output("ab\r\n世x");

        $grid = $this->session->getGrid();
        $this->assertCount(8, $grid);
        $this->assertSame(['a', 'b'], \array_slice($grid[0], offset: 0, length: 2));
        $this->assertSame(['世', '', 'x', ' '], \array_slice($grid[1], offset: 0, length: 4));
        $this->assertSame(\array_fill(0, count: 30, value: ' '), $grid[7]);
        $this->assertCount(30, $grid[0]);
    }

    public function testOrder(): void
    {
        $this->session->start();
        $this->driver->output("one   two\r\nthree one");

        $this->session->assertOrder('one', relation: 'above', other: 'three');
        $this->session->assertOrder('two', relation: 'above', other: 'three');
        $this->session->assertOrder('three', relation: 'below', other: 'two');
        $this->session->assertOrder('one', relation: 'before', other: 'two');
        $this->session->assertOrder('two', relation: 'before', other: 'three');
        $this->session->assertOrder('two', relation: 'after', other: 'one');
        $this->session->assertOrder('three', relation: 'after', other: 'two');
        $this->addToAssertionCount(6);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function orderFailures(): iterable
    {
        yield 'same line is not above' => ['one', 'above', 'two', 'The text "one" is not above "two": it is at line 1, column 1, and "two" at line 1, column 7.'];
        yield 'not below' => ['one', 'below', 'three', 'The text "one" is not below "three": it is at line 1, column 1, and "three" at line 2, column 1.'];
        yield 'same line is not below' => ['two', 'below', 'one', 'is not below'];
        yield 'not before' => ['two', 'before', 'one', 'is not before'];
        yield 'not after' => ['one', 'after', 'two', 'is not after'];
        yield 'itself is not before' => ['one', 'before', 'one', 'is not before'];
        yield 'itself is not after' => ['one', 'after', 'one', 'is not after'];
        yield 'missing text' => ['four', 'above', 'one', 'The text "four" is not above "one": it is nowhere, and "one" at line 1, column 1.'];
        yield 'missing other' => ['one', 'above', 'four', 'and "four" nowhere.'];
    }

    #[DataProvider('orderFailures')]
    public function testOrderFailures(string $text, string $relation, string $other, string $message): void
    {
        $this->session->start();
        $this->driver->output("one   two\r\nthree");

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $this->session->assertOrder($text, $relation, $other);
    }

    public function testAnUnknownRelation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown relation "beside"; use above, below, before, after.');

        $this->session->assertOrder('a', relation: 'beside', other: 'b');
    }

    public function testFiles(): void
    {
        $files = $this->session->files();
        $workspace = $this->session->getWorkspace();
        $workspace->write('a.txt', content: "one\ntwo\n\n");

        $files->assertExists('a.txt');
        $files->assertNotExists('b.txt');
        $files->assertContains('a.txt', text: 'two');
        $files->assertNotContains('a.txt', text: 'three');
        $files->assertEquals('a.txt', expected: "one\ntwo");
        $files->assertEquals('a.txt', expected: "one\ntwo\n");
        $this->addToAssertionCount(6);
    }

    /**
     * @return iterable<string, array{\Closure(Session): void, string}>
     */
    public static function fileFailures(): iterable
    {
        yield 'exists' => [static fn (Session $s) => $s->files()->assertExists('b.txt'), 'The file "b.txt" does not exist.'];
        yield 'does not exist' => [static fn (Session $s) => $s->files()->assertNotExists('a.txt'), 'The file "a.txt" exists.'];
        yield 'contains' => [static fn (Session $s) => $s->files()->assertContains('a.txt', text: 'three'), "The file \"a.txt\" does not contain \"three\"; it contains:\none\ntwo\n"];
        yield 'contains in a missing file' => [static fn (Session $s) => $s->files()->assertContains('b.txt', text: ''), 'The file "b.txt" does not exist.'];
        yield 'does not contain' => [static fn (Session $s) => $s->files()->assertNotContains('a.txt', text: 'two'), 'The file "a.txt" contains "two"; it contains:'];
        yield 'does not contain in a missing file' => [static fn (Session $s) => $s->files()->assertNotContains('b.txt', text: 'three'), 'The file "b.txt" does not exist.'];
        yield 'equals' => [static fn (Session $s) => $s->files()->assertEquals('a.txt', expected: 'one'), 'The file "a.txt" is not as expected; it contains:'];
        yield 'equals a missing file' => [static fn (Session $s) => $s->files()->assertEquals('b.txt', expected: ''), 'The file "b.txt" does not exist.'];
        yield 'equals an empty file' => [static fn (Session $s) => $s->files()->assertEquals('empty.txt', expected: 'x'), "The file \"empty.txt\" is not as expected; it contains:\n    (nothing)"];
    }

    /**
     * @param \Closure(Session): void $check
     */
    #[DataProvider('fileFailures')]
    public function testFileFailures(\Closure $check, string $message): void
    {
        $this->session->getWorkspace()->write('a.txt', content: "one\ntwo\n");
        $this->session->getWorkspace()->write('empty.txt', content: '');

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $check($this->session);
    }

    public function testTheClipboard(): void
    {
        $this->session->start();
        $terminal = $this->session->terminal();
        $terminal->assertClipboard(null);

        $this->driver->output("\x1b]52;c;".\base64_encode('copied')."\x07");
        $terminal->assertClipboard('copied');
        $this->addToAssertionCount(2);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function clipboardFailures(): iterable
    {
        yield 'nothing copied' => [null, 'x', 'Nothing was copied to the clipboard; "x" was expected.'];
        yield 'something copied' => ['abc', null, '"abc" was copied to the clipboard.'];
        yield 'something else copied' => ['abc', 'x', 'The clipboard holds "abc", not "x".'];
    }

    #[DataProvider('clipboardFailures')]
    public function testClipboardFailures(?string $copied, ?string $expected, string $message): void
    {
        $this->session->start();
        if (null !== $copied) {
            $this->driver->output("\x1b]52;c;".\base64_encode($copied)."\x07");
        }

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $this->session->terminal()->assertClipboard($expected);
    }

    public function testModes(): void
    {
        $this->session->start();
        $terminal = $this->session->terminal();
        $this->assertFalse($terminal->isModeOn('mouse clicks'));

        $this->driver->output("\x1b[?1000;1006h\x1b[?47h");
        $this->assertTrue($terminal->isModeOn('mouse clicks'));
        $this->assertTrue($terminal->isModeOn('SGR mouse'));
        $this->assertTrue($terminal->isModeOn('alternate screen'));
        $this->assertFalse($terminal->isModeOn('mouse drags'));
        $terminal->assertModeOn('SGR mouse');
        $terminal->assertModeOff('mouse drags');

        $this->driver->output("\x1b[?1002h\x1b[?1000l");
        $this->assertTrue($terminal->isModeOn('mouse clicks'));
        $this->assertTrue($terminal->isModeOn('mouse drags'));
        $this->assertFalse($terminal->isModeOn('mouse motion'));

        $this->driver->output("\x1b[?1003h\x1b[?1004h\x1b[?2004h\x1b[?1049h\x1b[?1047h");
        foreach (\array_keys($terminal::MODES) as $name) {
            $this->assertTrue($terminal->isModeOn($name), $name);
        }
    }

    public function testModeFailures(): void
    {
        $this->session->start();
        $this->driver->output("\x1b[?2004h");

        try {
            $this->session->terminal()->assertModeOff('bracketed paste');
            $this->fail('The mode is on.');
        } catch (ExpectationException $e) {
            $this->assertStringStartsWith('The bracketed paste mode is on.', $e->getMessage());
        }

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains('The focus events mode is off.');
        $this->session->terminal()->assertModeOn('focus events');
    }

    public function testAnUnknownMode(): void
    {
        $this->session->start();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown terminal mode "kitty"; use alternate screen, mouse clicks, mouse drags, mouse motion, SGR mouse, bracketed paste, focus events.');
        $this->session->terminal()->assertModeOff('kitty');
    }

    public function testTheCursor(): void
    {
        $this->session->start();
        $terminal = $this->session->terminal();
        // A terminal shows its cursor until told otherwise
        $this->assertTrue($terminal->isCursorVisible());
        $this->assertSame([1, 1], $terminal->getCursor());

        $this->driver->output("\x1b[?25l\x1b[3;5H");
        $this->assertFalse($terminal->isCursorVisible());
        $this->assertSame([3, 5], $terminal->getCursor());
        $terminal->assertCursorHidden();

        $this->driver->output("\x1b[?25h\x1b[4 q");
        $terminal->assertCursorVisible();
        $terminal->assertCursorAt(3, column: 5);
        $terminal->assertCursorShape('underline');
        $this->addToAssertionCount(4);
    }

    /**
     * @return iterable<string, array{string, \Closure(Session): void, string}>
     */
    public static function cursorFailures(): iterable
    {
        yield 'visible' => ["\x1b[?25l", static fn (Session $s) => $s->terminal()->assertCursorVisible(), 'The cursor is hidden.'];
        yield 'hidden' => ['', static fn (Session $s) => $s->terminal()->assertCursorHidden(), 'The cursor is visible.'];
        yield 'elsewhere' => ["\x1b[2;3H", static fn (Session $s) => $s->terminal()->assertCursorAt(2, column: 4), 'The cursor is at line 2, column 3, not line 2, column 4.'];
        yield 'on another line' => ["\x1b[2;3H", static fn (Session $s) => $s->terminal()->assertCursorAt(1, column: 3), 'The cursor is at line 2, column 3, not line 1, column 3.'];
        yield 'hidden where expected' => ["\x1b[2;3H\x1b[?25l", static fn (Session $s) => $s->terminal()->assertCursorAt(2, column: 3), 'The cursor is hidden; it was expected at line 2, column 3.'];
        yield 'default shape' => ['', static fn (Session $s) => $s->terminal()->assertCursorShape('bar'), 'The cursor is the terminal\'s default, not bar.'];
        yield 'another shape' => ["\x1b[5 q", static fn (Session $s) => $s->terminal()->assertCursorShape('bar'), 'The cursor is blinking bar, not bar.'];
    }

    /**
     * @param \Closure(Session): void $check
     */
    #[DataProvider('cursorFailures')]
    public function testCursorFailures(string $output, \Closure $check, string $message): void
    {
        $this->session->start();
        $this->driver->output($output);

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $check($this->session);
    }

    public function testAnUnknownCursorShape(): void
    {
        $this->session->start();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown cursor shape "beam"; use blinking block, block, blinking underline, underline, blinking bar, bar.');
        $this->session->terminal()->assertCursorShape('beam');
    }

    public function testFrames(): void
    {
        $this->session->start();
        $box = $this->session->box('Help');
        $this->assertNull($box->getText());
        $box->assertNotVisible();

        $this->driver->output("Help\r\n╭ Help ──╮\r\n│ one    │\r\n│   two  │\r\n╰────────╯");
        $box->assertVisible();
        $this->assertSame(" one\n   two", $box->getText());
        $box->assertSee('two');
        $box->assertNotSee('Help');
        $box->assertShows(" one  \n   two\n\n");

        $box->clickText('two', button: Mouse::RIGHT, count: 1, modifiers: ['Ctrl']);
        $box->clickText('one');
        $this->assertSame(Mouse::click(4, column: 5, button: Mouse::RIGHT, count: 1, modifiers: ['Ctrl']).Mouse::click(3, column: 3), $this->driver->input);
    }

    /**
     * @return iterable<string, array{string, \Closure(Session): void, string}>
     */
    public static function frameFailures(): iterable
    {
        $frame = "╭ Help ╮\r\n│ one  │\r\n╰──────╯";
        yield 'not visible' => ['', static fn (Session $s) => $s->box('Help')->assertVisible(), 'No frame titled "Help" is on the screen.'];
        yield 'visible' => [$frame, static fn (Session $s) => $s->box('Help')->assertNotVisible(), 'A frame titled "Help" is on the screen.'];
        yield 'see without a frame' => ['one', static fn (Session $s) => $s->box('Help')->assertSee('one'), 'No frame titled "Help" is on the screen.'];
        yield 'see' => [$frame, static fn (Session $s) => $s->box('Help')->assertSee('two'), "The frame titled \"Help\" does not show \"two\"; it shows:\n one"];
        yield 'not see without a frame' => ['', static fn (Session $s) => $s->box('Help')->assertNotSee('one'), 'No frame titled "Help" is on the screen.'];
        yield 'not see' => [$frame, static fn (Session $s) => $s->box('Help')->assertNotSee('one'), 'The frame titled "Help" shows "one"; it shows:'];
        yield 'shows' => [$frame, static fn (Session $s) => $s->box('Help')->assertShows('two'), "The frame titled \"Help\" is not:\ntwo; it shows:\n one"];
        yield 'shows without a frame' => ['', static fn (Session $s) => $s->box('Help')->assertShows(''), 'No frame titled "Help" is on the screen.'];
        yield 'click' => [$frame, static fn (Session $s) => $s->box('Help')->clickText('two'), 'The frame titled "Help" does not show "two"'];
        yield 'click without a frame' => ['two', static fn (Session $s) => $s->box('Help')->clickText('two'), 'No frame titled "Help" is on the screen.'];
    }

    /**
     * @param \Closure(Session): void $check
     */
    #[DataProvider('frameFailures')]
    public function testFrameFailures(string $output, \Closure $check, string $message): void
    {
        $this->session->start();
        $this->driver->output($output);

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $check($this->session);
    }
}
