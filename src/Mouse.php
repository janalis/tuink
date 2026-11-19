<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

/**
 * Turns mouse actions into the SGR (mode 1006) reports a terminal sends.
 *
 * A report is ESC [ < button ; column ; row M for a press and m for a release,
 * with 1-based coordinates. Symfony Tui passes them whole to the application
 * as InputEvent data; the application decides what a click means.
 */
final class Mouse
{
    public const LEFT = 'left';
    public const MIDDLE = 'middle';
    public const RIGHT = 'right';

    private const BUTTONS = [self::LEFT => 0, self::MIDDLE => 1, self::RIGHT => 2];
    private const WHEEL = ['up' => 64, 'down' => 65, 'left' => 66, 'right' => 67];
    private const MODIFIERS = ['shift' => 4, 'alt' => 8, 'meta' => 8, 'option' => 8, 'ctrl' => 16, 'control' => 16];

    /**
     * The press and release of a click, repeated for a double or triple click.
     *
     * @param list<string> $modifiers Shift, Alt, Ctrl
     */
    public static function click(int $line, int $column, string $button = self::LEFT, int $count = 1, array $modifiers = []): string
    {
        $code = (self::BUTTONS[\strtolower($button)] ?? throw new \InvalidArgumentException(\sprintf('Unknown mouse button "%s"; use left, middle or right.', $button))) + self::modifiers($modifiers);
        self::check($line, $column);

        return \str_repeat(self::press($code, $line, $column).self::release($code, $line, $column), \max(1, $count));
    }

    /**
     * The pointer moving over a cell with no button held, as hovering reports it (mode 1003).
     */
    public static function move(int $line, int $column): string
    {
        self::check($line, $column);

        return self::press(35, $line, $column);
    }

    /**
     * Wheel notches; terminals report them as presses without release.
     *
     * @param list<string> $modifiers
     */
    public static function scroll(int $line, int $column, string $direction, int $notches = 1, array $modifiers = []): string
    {
        $code =
            (self::WHEEL[\strtolower($direction)] ?? throw new \InvalidArgumentException(\sprintf('Unknown scroll direction "%s"; use up, down, left or right.', $direction)))
            + self::modifiers($modifiers);
        self::check($line, $column);

        return \str_repeat(self::press($code, $line, $column), \max(1, $notches));
    }

    /**
     * A press at one cell, motion with the button held through the others, and a release at the last.
     *
     * @param list<array{int, int}> $path [line, column] cells, from the press to the release
     */
    public static function drag(array $path, string $button = self::LEFT): string
    {
        if (\count($path) < 2) {
            throw new \InvalidArgumentException('A drag needs at least two cells.');
        }
        $code = self::BUTTONS[\strtolower($button)] ?? throw new \InvalidArgumentException(\sprintf('Unknown mouse button "%s"; use left, middle or right.', $button));

        $bytes = '';
        $last = \count($path) - 1;
        foreach ($path as $i => [$line, $column]) {
            self::check($line, $column);
            $bytes .= match ($i) {
                0 => self::press($code, $line, $column),
                $last => self::press($code + 32, $line, $column).self::release($code, $line, $column),
                default => self::press($code + 32, $line, $column),
            };
        }

        return $bytes;
    }

    /**
     * @param list<string> $modifiers
     */
    private static function modifiers(array $modifiers): int
    {
        $code = 0;
        foreach (\array_unique(\array_map(strtolower(...), $modifiers)) as $modifier) {
            $code |= self::MODIFIERS[$modifier] ?? throw new \InvalidArgumentException(\sprintf('Unknown modifier "%s"; use Shift, Alt or Ctrl.', $modifier));
        }

        return $code;
    }

    private static function check(int $line, int $column): void
    {
        if ($line < 1 || $column < 1) {
            throw new \InvalidArgumentException(\sprintf('Mouse positions start at line 1, column 1, not line %d, column %d.', $line, $column));
        }
    }

    private static function press(int $code, int $line, int $column): string
    {
        return \sprintf("\x1b[<%d;%d;%dM", $code, $column, $line);
    }

    private static function release(int $code, int $line, int $column): string
    {
        return \sprintf("\x1b[<%d;%d;%dm", $code, $column, $line);
    }
}
