<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Check;

use Janalis\Tuink\Driver\TerminalObserver;
use Janalis\Tuink\Session;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * Checks on what the application told the terminal besides cells: the clipboard, modes and the cursor.
 */
final readonly class TerminalChecks
{
    /** The DEC private modes behind each name; a name is on when any of its modes is set */
    public const MODES = [
        'alternate screen' => [1049, 1047, 47],
        'mouse clicks' => [1000, 1002, 1003],
        'mouse drags' => [1002, 1003],
        'mouse motion' => [1003],
        'SGR mouse' => [1006],
        'bracketed paste' => [2004],
        'focus events' => [1004],
    ];

    public function __construct(
        private Session $session,
    ) {
    }

    /**
     * Checks what the application last copied with OSC 52, or with null that it copied nothing.
     */
    public function assertClipboard(?string $expected): void
    {
        $observer = $this->observer();
        $this->session->waitUntil(
            static fn (): bool => $observer->getClipboard() === $expected,
            static fn (): string => match (true) {
                null === $observer->getClipboard() => \sprintf('Nothing was copied to the clipboard; "%s" was expected.', $expected),
                null === $expected => \sprintf('"%s" was copied to the clipboard.', $observer->getClipboard()),
                default => \sprintf('The clipboard holds "%s", not "%s".', $observer->getClipboard(), $expected),
            },
        );
    }

    public function isModeOn(string $name): bool
    {
        $modes = self::MODES[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown terminal mode "%s"; use %s.', $name, \implode(', ', \array_keys(self::MODES))));
        $observer = $this->observer();

        return [] !== \array_filter($modes, static fn (int $mode): bool => true === $observer->getMode($mode));
    }

    public function assertMode(string $name, bool $on = true): void
    {
        $this->session->waitUntil(
            fn (): bool => $this->isModeOn($name) === $on,
            \sprintf('The %s mode is %s.', $name, $on ? 'off' : 'on'),
        );
    }

    /**
     * Whether the cursor shows; a terminal shows it until told otherwise.
     */
    public function isCursorVisible(): bool
    {
        return false !== $this->observer()->getMode(25);
    }

    /**
     * Where the terminal's cursor is, as a 1-based [line, column].
     *
     * @return array{int, int}
     */
    public function getCursor(): array
    {
        $screen = $this->session->getDriver()->getScreen();

        // ScreenBuffer tracks the cursor but does not tell
        $read = static fn (string $property): int => \intval(new \ReflectionProperty(ScreenBuffer::class, $property)->getValue($screen)) + 1;

        return [$read('cursorRow'), $read('cursorCol')];
    }

    public function assertCursorVisible(bool $visible = true): void
    {
        $this->session->waitUntil(
            fn (): bool => $this->isCursorVisible() === $visible,
            \sprintf('The cursor is %s.', $visible ? 'hidden' : 'visible'),
        );
    }

    /**
     * Checks the cursor shows at a cell, numbered from line 1, column 1.
     */
    public function assertCursorAt(int $line, int $column): void
    {
        $this->session->waitUntil(
            fn (): bool => $this->isCursorVisible() && $this->getCursor() === [$line, $column],
            fn (): string => (
                $this->isCursorVisible()
                    ? \sprintf('The cursor is at line %d, column %d, not line %d, column %d.', ...[...$this->getCursor(), $line, $column])
                    : \sprintf('The cursor is hidden; it was expected at line %d, column %d.', $line, $column)
            ),
        );
    }

    /**
     * Checks the cursor shape the application asked for: "block", "underline" or "bar", optionally "blinking …".
     */
    public function assertCursorShape(string $shape): void
    {
        if (!\in_array($shape, TerminalObserver::CURSOR_SHAPES, strict: true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown cursor shape "%s"; use %s.', $shape, \implode(', ', \array_unique(TerminalObserver::CURSOR_SHAPES))));
        }
        $observer = $this->observer();
        $this->session->waitUntil(
            static fn (): bool => $observer->getCursorShape() === $shape,
            static fn (): string => \sprintf('The cursor is %s, not %s.', $observer->getCursorShape() ?? 'the terminal\'s default', $shape),
        );
    }

    private function observer(): TerminalObserver
    {
        return $this->session->getDriver()->getObserver();
    }
}
