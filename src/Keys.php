<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

/**
 * Turns key names into the bytes a terminal sends for them.
 *
 * Names are case-insensitive and joined with "+": "Enter", "Ctrl+S",
 * "Shift+Tab", "Alt+Left", "Ctrl+Shift+Up", "F5", or a single character.
 * Angle brackets are optional, so "<Ctrl+S>" works too.
 */
final class Keys
{
    private const NAMED = [
        'enter' => "\r",
        'return' => "\r",
        'escape' => "\x1b",
        'esc' => "\x1b",
        'tab' => "\t",
        'space' => ' ',
        'backspace' => "\x7f",
        'insert' => "\x1b[2~",
        'delete' => "\x1b[3~",
        'del' => "\x1b[3~",
        'pageup' => "\x1b[5~",
        'pagedown' => "\x1b[6~",
        'f5' => "\x1b[15~",
        'f6' => "\x1b[17~",
        'f7' => "\x1b[18~",
        'f8' => "\x1b[19~",
        'f9' => "\x1b[20~",
        'f10' => "\x1b[21~",
        'f11' => "\x1b[23~",
        'f12' => "\x1b[24~",
    ];

    /** Keys sent as CSI 1;<modifier><final> when modified, and as CSI <final> or SS3 <final> bare. */
    private const CURSOR = [
        'up' => 'A',
        'down' => 'B',
        'right' => 'C',
        'left' => 'D',
        'home' => 'H',
        'end' => 'F',
        'f1' => 'P',
        'f2' => 'Q',
        'f3' => 'R',
        'f4' => 'S',
    ];

    /** Keys sent as CSI <number>~ bare, CSI <number>;<modifier>~ modified. */
    private const TILDE = [
        'insert' => 2,
        'delete' => 3,
        'del' => 3,
        'pageup' => 5,
        'pagedown' => 6,
        'f5' => 15,
        'f6' => 17,
        'f7' => 18,
        'f8' => 19,
        'f9' => 20,
        'f10' => 21,
        'f11' => 23,
        'f12' => 24,
    ];

    /**
     * Returns the bytes for one key, like "Ctrl+S" or "<Enter>".
     */
    public static function key(string $name): string
    {
        $spec = trim($name);
        if (str_starts_with($spec, '<') && str_ends_with($spec, '>') && \strlen($spec) > 2) {
            $spec = substr($spec, 1, -1);
        }
        if ('' === $spec) {
            throw new \InvalidArgumentException('A key name cannot be empty.');
        }

        // "+" alone, or a trailing "+" as in "Ctrl++", names the plus key itself
        $parts = '+' === $spec ? ['+'] : explode('+', $spec);
        if ('' === end($parts) && \count($parts) > 1) {
            array_pop($parts);
            $parts[\count($parts) - 1] = '+';
        }

        $base = array_pop($parts);
        $shift = $alt = $ctrl = false;
        foreach ($parts as $modifier) {
            match (strtolower(trim($modifier))) {
                'shift' => $shift = true,
                'alt', 'meta', 'option' => $alt = true,
                'ctrl', 'control' => $ctrl = true,
                default => throw new \InvalidArgumentException(\sprintf('Unknown modifier "%s" in key "%s".', $modifier, $name)),
            };
        }

        $lower = strtolower($base);
        $modifier = 1 + ($shift ? 1 : 0) + ($alt ? 2 : 0) + ($ctrl ? 4 : 0);

        if (isset(self::CURSOR[$lower])) {
            $final = self::CURSOR[$lower];
            if (1 !== $modifier) {
                return "\x1b[1;{$modifier}{$final}";
            }

            return str_starts_with($lower, 'f') ? "\x1bO{$final}" : "\x1b[{$final}";
        }

        if (isset(self::TILDE[$lower]) && 1 !== $modifier) {
            return "\x1b[".self::TILDE[$lower].";{$modifier}~";
        }

        if ('tab' === $lower && $shift && !$alt && !$ctrl) {
            return "\x1b[Z";
        }

        if ('enter' === $lower || 'return' === $lower) {
            if (1 !== $modifier) {
                // xterm modifyOtherKeys form, which the Tui key parser understands
                return "\x1b[27;{$modifier};13~";
            }

            return "\r";
        }

        if ('space' === $lower && $ctrl && !$shift) {
            return ($alt ? "\x1b" : '')."\x00";
        }

        if (isset(self::NAMED[$lower])) {
            if ($ctrl || $shift) {
                // Legacy encodings have no room for these: send the CSI u form (kitty keyboard protocol), which the Tui key parser reads
                return \sprintf("\x1b[%d;%du", \ord(self::NAMED[$lower][-1]), $modifier);
            }

            return ($alt ? "\x1b" : '').self::NAMED[$lower];
        }

        if (1 !== mb_strlen($base)) {
            throw new \InvalidArgumentException(\sprintf('Unknown key "%s".', $name));
        }

        if ($ctrl && ($shift || $alt) && 1 === \strlen($base) && ctype_alpha($base)) {
            // A control code cannot carry Shift, nor Alt cleanly: CSI u names the letter and every modifier
            return \sprintf("\x1b[%d;%du", \ord(strtolower($base)), $modifier);
        }

        $char = $shift ? mb_strtoupper($base) : $base;
        if ($ctrl) {
            $char = self::control($char, $name);
        }

        return ($alt ? "\x1b" : '').$char;
    }

    /**
     * Returns the bytes for a text mixing literal characters and <Key> names, like "abc<Enter>".
     *
     * A key name is a word of two or more characters ("<Enter>", "<F5>") or
     * anything with a modifier ("<Ctrl+S>", "<Alt+x>"); "<b>" and any "<" that
     * does not open a key name stay text.
     */
    public static function sequence(string $text): string
    {
        return (string) preg_replace_callback(
            '/<((?:[A-Za-z]+\+)+[^\s<>]+|[A-Za-z][A-Za-z0-9]+)>/',
            static function (array $match): string {
                try {
                    return self::key($match[1]);
                } catch (\InvalidArgumentException) {
                    return $match[0];
                }
            },
            $text,
        );
    }

    private static function control(string $char, string $name): string
    {
        $code = 1 === \strlen($char) ? \ord(strtoupper($char)) : 0;
        if ($code >= 64 && $code <= 95) {
            // Ctrl clears the two high bits: Ctrl+@ is NUL, Ctrl+A is 1, Ctrl+_ is 31
            return \chr($code - 64);
        }

        return match ($char) {
            ' ', '2' => "\x00",
            '-' => "\x1f",
            '?' => "\x7f",
            default => throw new \InvalidArgumentException(\sprintf('Key "%s" has no control code.', $name)),
        };
    }
}
