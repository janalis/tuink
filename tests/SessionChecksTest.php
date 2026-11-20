<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Example\DemoApp;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Session;
use Janalis\Tuink\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * What Session checks accept and how they fail.
 */
final class SessionChecksTest extends TestCase
{
    private ?Session $session = null;
    private ?string $directory = null;

    /** The CI and TUINK_UPDATE_SNAPSHOTS values the tests found, restored after each */
    private string|false $ci = false;
    private string|false $update = false;

    protected function setUp(): void
    {
        // Snapshot behavior depends on them, and CI sets CI=true
        $this->ci = \getenv('CI');
        $this->update = \getenv('TUINK_UPDATE_SNAPSHOTS');
        \putenv('CI');
        \putenv('TUINK_UPDATE_SNAPSHOTS');
    }

    protected function tearDown(): void
    {
        \putenv(false === $this->ci ? 'CI' : 'CI='.$this->ci);
        \putenv(false === $this->update ? 'TUINK_UPDATE_SNAPSHOTS' : 'TUINK_UPDATE_SNAPSHOTS='.$this->update);
        $this->session?->stop();
        if (null !== $this->directory) {
            \exec('rm -rf '.\escapeshellarg($this->directory));
        }
    }

    public function testTextStylesFollowTheCells(): void
    {
        $session = $this->styled();

        $this->assertSame(['bold', 'bold', 'bold', 'bold'], \array_map(self::describe(...), $session->getTextStyles('Bold')));
        $this->assertSame(['bold', 'plain', 'plain'], \array_map(self::describe(...), $session->getTextStyles('d p')));
        // A wide character has one style, though it fills two cells
        $this->assertSame(['red'], \array_map(self::describe(...), $session->getTextStyles('世')));
    }

    public function testStyleChecksThatPass(): void
    {
        $session = $this->styled();
        $session->assertTextStyle('Bold', attribute: 'bold');
        $session->assertTextWithoutStyle('plain', attribute: 'bold');
        $session->assertTextColor('世', color: 'red');
        $session->assertTextBackground('Blue', color: 'blue');

        $this->addToAssertionCount(4);
    }

    /**
     * @return iterable<string, array{\Closure(Session): void, string}>
     */
    public static function styleFailures(): iterable
    {
        yield 'one cell differs' => [static fn (Session $s) => $s->assertTextStyle('d p', attribute: 'bold'), 'The text "d p" is not bold: bold | plain | plain.'];
        yield 'a single cell' => [static fn (Session $s) => $s->assertTextStyle('世', attribute: 'bold'), 'The text "世" is not bold: red.'];
        yield 'without' => [static fn (Session $s) => $s->assertTextWithoutStyle('Bold', attribute: 'bold'), 'The text "Bold" is bold: bold.'];
        yield 'color' => [static fn (Session $s) => $s->assertTextColor('Bold', color: 'red'), 'The text "Bold" does not have the foreground color red: bold.'];
        yield 'background' => [static fn (Session $s) => $s->assertTextBackground('plain', color: 'blue'), 'The text "plain" does not have the background color blue: plain.'];
    }

    /**
     * @param \Closure(Session): void $check
     */
    #[DataProvider('styleFailures')]
    public function testStyleFailures(\Closure $check, string $message): void
    {
        $session = $this->styled();

        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains($message);
        $check($session);
    }

    /**
     * @return iterable<string, array{\Closure(Session): void}>
     */
    public static function unknownAttributes(): iterable
    {
        yield 'with' => [static fn (Session $s) => $s->assertTextStyle('Bold', attribute: 'sparkly')];
        yield 'without' => [static fn (Session $s) => $s->assertTextWithoutStyle('Bold', attribute: 'sparkly')];
    }

    /**
     * @param \Closure(Session): void $check
     */
    #[DataProvider('unknownAttributes')]
    public function testUnknownTextAttributes(\Closure $check): void
    {
        $session = $this->styled();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown text attribute "sparkly"; use bold, dim, italic, underlined, blinking, reversed, hidden, struck through.');
        $check($session);
    }

    public function testTheBell(): void
    {
        $session = $this->demo();
        $session->press('Ctrl+G');
        $session->assertBell();
        $session->assertBell(1);
        $session->press('Ctrl+G');
        $session->assertBell(2);

        $this->assertSame(2, $session->getDriver()->getObserver()->getBellCount());
    }

    public function testAssertLineEqualsFails(): void
    {
        $this->expectException(ExpectationException::class);
        $this->expectExceptionMessageIsOrContains('Line 1 is not "Tuink".');

        $this->demo(timeout: 0.05)->assertLineEquals(1, text: 'Tuink');
    }

    public function testScreensCompareAcrossLineEndings(): void
    {
        $session = $this->demo();
        $expected = \str_replace("\n", replace: "\r\n", subject: $session->getScreenText());

        $session->assertScreenEquals($expected);
        $this->addToAssertionCount(1);
    }

    public function testLineNumbersAreAlignedPastNine(): void
    {
        $session = $this->demo(timeout: 0.05);

        try {
            $session->assertScreenEquals(\implode("\n", \range(start: 1, end: 10)));
            $this->fail('An expectation exception was expected.');
        } catch (ExpectationException $e) {
            $this->assertStringContainsString("The screen is not:\n 1 | 1\n 2 | 2\n", $e->getMessage());
            $this->assertStringContainsString("\n10 | 10\n", $e->getMessage());
        }
    }

    public function testWaitUntilPolls(): void
    {
        $session = $this->demo();
        $calls = 0;
        $session->waitUntil(static function () use (&$calls): bool {
            return ++$calls >= 3;
        }, message: 'never');

        $this->assertSame(3, $calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSnapshotNames(): iterable
    {
        yield 'leading character' => ['!start'];
        yield 'trailing character' => ['start!'];
        yield 'parent directory' => ['../start'];
    }

    #[DataProvider('invalidSnapshotNames')]
    public function testInvalidSnapshotNames(string $name): void
    {
        $session = $this->demoWithSnapshots();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('Invalid snapshot name "%s".', $name));
        $session->assertMatchesSnapshot($name);
    }

    public function testASnapshotFileEndsWithANewLine(): void
    {
        $session = $this->demoWithSnapshots();
        $session->assertMatchesSnapshot('start');

        $this->assertSame($session->snapshot()."\n", \file_get_contents($this->directory().'/start.txt'));
    }

    public function testASnapshotDirectoryThatCannotBeCreated(): void
    {
        $session = $this->demoWithSnapshots();
        // A file where the directory should be
        \file_put_contents($this->directory().'/blocked', data: '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('Cannot create the snapshot directory "%s/blocked": ', $this->directory()));
        try {
            $session->assertMatchesSnapshot('blocked/start');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('unknown error', $e->getMessage());

            throw $e;
        }
    }

    private static function describe(Style $style): string
    {
        return $style->describe();
    }

    private function directory(): string
    {
        return $this->directory ?? throw new \LogicException('No directory.');
    }

    private function demoWithSnapshots(): Session
    {
        $this->directory = \sys_get_temp_dir().'/tuink-checks-'.\bin2hex(\random_bytes(4));
        \mkdir($this->directory);

        return $this->demo();
    }

    private function demo(float $timeout = 1.0): Session
    {
        $this->session = new Session(
            new InProcessDriver(static fn (VirtualTerminal $terminal): Tui => new DemoApp($terminal)->tui),
            timeout: $timeout,
            quietPeriod: 0.02,
            snapshotDir: $this->directory,
        );
        $this->session->start();

        return $this->session;
    }

    public function testTheLastLineCanBeRead(): void
    {
        $this->assertSame('', $this->demo()->getLine(24));
    }

    public function testATimeoutOfItsOwn(): void
    {
        $session = $this->demo(timeout: 30.0);
        $start = \microtime(true);

        try {
            $session->waitUntil(static fn (): bool => false, message: 'never', timeout: 0.05);
            $this->fail('An expectation exception was expected.');
        } catch (ExpectationException) {
            $this->assertLessThan(5.0, \microtime(true) - $start);
        }
    }

    public function testStylesPastAWideCharacter(): void
    {
        $this->assertSame(['red', 'plain', 'on blue'], \array_map(self::describe(...), $this->styled()->getTextStyles('世 B')));
    }

    public function testARegionStopsAtItsWidth(): void
    {
        $this->assertSame('Bo', $this->styled()->getRegion(line: 1, column: 1, width: 2, height: 1));
    }

    private function styled(): Session
    {
        $this->session = new Session(
            new InProcessDriver(static function (VirtualTerminal $terminal): Tui {
                $tui = new Tui(terminal: $terminal);
                $tui->add(new TextWidget("\x1b[1mBold\x1b[0m plain"));
                $tui->add(new TextWidget("\x1b[31m世\x1b[0m \x1b[44mBlue\x1b[0m"));

                return $tui;
            }),
            30,
            5,
            0.05,
            0.02,
        );
        $this->session->start();

        return $this->session;
    }
}
