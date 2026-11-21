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
 * Turns key names into the bytes a terminal sends for them.
 *
 * Names are case-insensitive and joined with "+": "Enter", "Ctrl+S",
 * "Shift+Tab", "Alt+Left", "Ctrl+Shift+Up", "Super+S", "F5", or a single
 * character. "PageUp" may also be written "Page_Up".
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
        'page_up' => "\x1b[5~",
        'page_down' => "\x1b[6~",
        'f5' => "\x1b[15~",
        'f6' => "\x1b[17~",
        'f7' => "\x1b[18~",
        'f8' => "\x1b[19~",
        'f9' => "\x1b[20~",
        'f10' => "\x1b[21~",
        'f11' => "\x1b[23~",
        'f12' => "\x1b[24~",
        // What a terminal sends once focus events are on, when its window gains or loses the focus
        'focusin' => "\x1b[I",
        'focusout' => "\x1b[O",
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
        'page_up' => 5,
        'page_down' => 6,
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
        [$base, $modifier] = self::parse($name);

        return self::special(\strtolower($base), $modifier) ?? self::character($base, $modifier, $name);
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
        return (string) \preg_replace_callback(
            '/<(?:[A-Za-z]+\+)+[^\s<>]+>|<[A-Za-z][A-Za-z0-9]+>/',
            static function (array $match): string {
                try {
                    return self::key($match[0]);
                } catch (\InvalidArgumentException) {
                    return $match[0];
                }
            },
            $text,
        );
    }

    /**
     * Splits "<Ctrl+Shift+Up>" into its key and the xterm modifier parameter: 1, plus 1 for Shift, 2 for Alt, 4 for Ctrl, 8 for Super.
     *
     * @return array{string, int}
     */
    private static function parse(string $name): array
    {
        $spec = \trim($name);
        if (\str_starts_with($spec, '<') && \str_ends_with($spec, '>') && \strlen($spec) > 2) {
            $spec = \substr($spec, offset: 1, length: -1);
        }
        if ('' === $spec) {
            throw new \InvalidArgumentException('A key name cannot be empty.');
        }

        // "+" alone, or a trailing "+" as in "Ctrl++", names the plus key itself
        $parts = '+' === $spec ? ['+'] : \explode('+', $spec);
        if ('' === \end($parts)) {
            \array_pop($parts);
            $parts[\count($parts) - 1] = '+';
        }
        $base = \trim(\array_pop($parts) ?? '');

        $bits = 0;
        foreach ($parts as $part) {
            $bits |= match (\strtolower(\trim($part))) {
                'shift' => 1,
                'alt', 'meta', 'option' => 2,
                'ctrl', 'control' => 4,
                // Cmd on macOS, the Windows key elsewhere: only the kitty keyboard protocol carries it
                'super', 'cmd', 'command' => 8,
                default => throw new \InvalidArgumentException(\sprintf('Unknown modifier "%s" in key "%s".', $part, $name)),
            };
        }

        return [$base, 1 + $bits];
    }

    /**
     * The bytes of a named key, or null for a single character.
     */
    private static function special(string $lower, int $modifier): ?string
    {
        $bits = $modifier - 1;
        if (\array_key_exists($lower, self::CURSOR)) {
            $final = self::CURSOR[$lower];
            if (1 !== $modifier) {
                return "\x1b[1;{$modifier}{$final}";
            }

            return \str_starts_with($lower, 'f') ? "\x1bO{$final}" : "\x1b[{$final}";
        }
        if (\array_key_exists($lower, self::TILDE) && 1 !== $modifier) {
            return "\x1b[".self::TILDE[$lower].";{$modifier}~";
        }
        if ('tab' === $lower && 1 === $bits) {
            return "\x1b[Z";
        }
        if ('enter' === $lower || 'return' === $lower) {
            // Modified, the xterm modifyOtherKeys form, which the Tui key parser understands; with Super, only CSI u is read
            return match (true) {
                1 === $modifier => "\r",
                0 !== ($bits & 8) => "\x1b[13;{$modifier}u",
                default => "\x1b[27;{$modifier};13~",
            };
        }
        if ('space' === $lower && 4 === ($bits & 13)) {
            return self::alt($bits)."\x00";
        }
        if (!\array_key_exists($lower, self::NAMED)) {
            return null;
        }
        if (0 !== ($bits & 13)) {
            // Legacy encodings have no room for Shift, Ctrl or Super here: send the CSI u form (kitty keyboard protocol), which the Tui key parser reads
            return \sprintf("\x1b[%d;%du", \ord(self::NAMED[$lower]), $modifier);
        }

        return self::alt($bits).self::NAMED[$lower];
    }

    private static function character(string $base, int $modifier, string $name): string
    {
        if (1 !== \mb_strlen($base)) {
            throw new \InvalidArgumentException(\sprintf('Unknown key "%s".', $name));
        }
        $bits = $modifier - 1;
        if (0 !== ($bits & 8)) {
            // No legacy encoding carries Super: CSI u names the unshifted character and every modifier
            return \sprintf("\x1b[%d;%du", \mb_ord(\mb_strtolower($base)), $modifier);
        }
        if (0 !== ($bits & 4) && 0 !== ($bits & 3) && 1 === \strlen($base) && \ctype_alpha($base)) {
            // A control code cannot carry Shift, nor Alt cleanly: CSI u names the letter and every modifier
            return \sprintf("\x1b[%d;%du", \ord(\strtolower($base)), $modifier);
        }

        $char = 0 !== ($bits & 1) ? \mb_strtoupper($base) : $base;
        if (0 !== ($bits & 4)) {
            $control = self::control($char);
            if (null === $control) {
                // No control code, as for Ctrl+, or Ctrl+1: a terminal speaking the kitty keyboard protocol sends CSI u
                return \sprintf("\x1b[%d;%du", \mb_ord($base), $modifier);
            }
            $char = $control;
        }

        return self::alt($bits).$char;
    }

    /**
     * ESC, which a terminal sends before a key held with Alt.
     */
    private static function alt(int $bits): string
    {
        return 0 !== ($bits & 2) ? "\x1b" : '';
    }

    private static function control(string $char): ?string
    {
        // Ctrl clears the two high bits of @, A to Z and [ \ ] ^ _: Ctrl+@ is NUL, Ctrl+A is 1, Ctrl+_ is 31
        $upper = \strtoupper($char);
        if ('@' <= $upper && $upper <= '_') {
            return \chr(\ord($upper) - 64);
        }

        return match ($char) {
            '2' => "\x00",
            '-' => "\x1f",
            '?' => "\x7f",
            default => null,
        };
    }
}
