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
 * The style of a screen cell, read from the SGR codes ScreenBuffer keeps for it.
 *
 * Colors are named after what they are: "red" or "bright-red" for the 16
 * standard colors (SGR 30–37, 90–97, or palette indexes 0–15), the palette
 * index as a number for the other 240 ("208"), and "#rrggbb" for true color.
 */
final class Style
{
    public const ATTRIBUTES = ['bold', 'dim', 'italic', 'underlined', 'blinking', 'reversed', 'hidden', 'struck through'];

    /** The 16 standard colors, by palette index */
    private const PALETTE = [
        'black',
        'red',
        'green',
        'yellow',
        'blue',
        'magenta',
        'cyan',
        'white',
        'bright-black',
        'bright-red',
        'bright-green',
        'bright-yellow',
        'bright-blue',
        'bright-magenta',
        'bright-cyan',
        'bright-white',
    ];
    private const CODES = [1 => 'bold', 2 => 'dim', 3 => 'italic', 4 => 'underlined', 21 => 'underlined', 5 => 'blinking', 7 => 'reversed', 8 => 'hidden', 9 => 'struck through'];
    private const FOREGROUND = [
        30 => 'black',
        31 => 'red',
        32 => 'green',
        33 => 'yellow',
        34 => 'blue',
        35 => 'magenta',
        36 => 'cyan',
        37 => 'white',
        90 => 'bright-black',
        91 => 'bright-red',
        92 => 'bright-green',
        93 => 'bright-yellow',
        94 => 'bright-blue',
        95 => 'bright-magenta',
        96 => 'bright-cyan',
        97 => 'bright-white',
    ];
    private const BACKGROUND = [
        40 => 'black',
        41 => 'red',
        42 => 'green',
        43 => 'yellow',
        44 => 'blue',
        45 => 'magenta',
        46 => 'cyan',
        47 => 'white',
        100 => 'bright-black',
        101 => 'bright-red',
        102 => 'bright-green',
        103 => 'bright-yellow',
        104 => 'bright-blue',
        105 => 'bright-magenta',
        106 => 'bright-cyan',
        107 => 'bright-white',
    ];

    /**
     * @param list<string> $attributes
     */
    public function __construct(
        public readonly array $attributes = [],
        public readonly ?string $foreground = null,
        public readonly ?string $background = null,
    ) {
    }

    public static function fromSgr(string $sgr): self
    {
        $match = [];
        if (!\preg_match('/^\x1b\[([0-9;:]*)m$/', $sgr, $match)) {
            return new self();
        }

        $codes = \explode(';', $match[1]);
        $attributes = [];
        $foreground = null;
        $background = null;
        for ($i = 0, $count = \count($codes); $i < $count; ++$i) {
            // Underline variants are written 4:3 (curly) and the like
            $code = (int) \explode(':', $codes[$i])[0];
            if (\array_key_exists($code, self::CODES)) {
                $attributes[] = self::CODES[$code];

                continue;
            }
            if (38 === $code || 48 === $code || 58 === $code) {
                [$color, $used] = self::extendedColor($codes, $i + 1);
                $i += $used;
                $foreground = 38 === $code ? $color : $foreground;
                $background = 48 === $code ? $color : $background;

                continue;
            }
            $foreground = self::FOREGROUND[$code] ?? $foreground;
            $background = self::BACKGROUND[$code] ?? $background;
        }

        return new self(\array_values(\array_unique($attributes)), $foreground, $background);
    }

    /**
     * The color after 38, 48 or 58: 5;n for the palette, 2;r;g;b for true color.
     *
     * A truncated color has run out of codes, so it takes all that are left and yields null.
     *
     * @param list<string> $codes
     *
     * @return array{?string, int} The color, and how many codes it used
     */
    private static function extendedColor(array $codes, int $at): array
    {
        $left = \count($codes) - $at;

        return match ($codes[$at] ?? null) {
            '5' => $left > 1 ? [self::name($codes[$at + 1]), 2] : [null, $left],
            '2' => $left > 3 ? [\sprintf('#%02x%02x%02x', (int) $codes[$at + 1], (int) $codes[$at + 2], (int) $codes[$at + 3]), 4] : [null, $left],
            default => [null, 0],
        };
    }

    /**
     * The canonical name of a color as a step writes it: "Red", "bright red", "#FF0000", "9" or "208".
     */
    public static function name(string $color): string
    {
        $color = \strtolower(\trim($color));
        if (\preg_match('/^#[0-9a-f]{6}$/', $color)) {
            return $color;
        }
        $m = [];
        if (\preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m)) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }
        if (\ctype_digit($color)) {
            $index = (int) $color;

            return self::PALETTE[$index] ?? (string) $index;
        }
        $color = \strtr($color, from: ' _', to: '--');
        if (!\in_array($color, self::PALETTE, strict: true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown color "%s": use a name like "red" or "bright red", a palette index, or "#rrggbb".', $color));
        }

        return $color;
    }

    public function has(string $attribute): bool
    {
        return \in_array($attribute, $this->attributes, strict: true);
    }

    public function describe(): string
    {
        $parts = $this->attributes;
        if (null !== $this->foreground) {
            $parts[] = $this->foreground;
        }
        if (null !== $this->background) {
            $parts[] = 'on '.$this->background;
        }

        return [] === $parts ? 'plain' : \implode(', ', $parts);
    }

    /**
     * What the cells of a text look like: one description when they all look the same, else one per cell.
     *
     * @param list<self> $styles
     */
    public static function describeAll(array $styles): string
    {
        $descriptions = \array_map(static fn (self $s): string => $s->describe(), $styles);

        return 1 === \count(\array_unique($descriptions)) ? $descriptions[0] : \implode(' | ', $descriptions);
    }
}
