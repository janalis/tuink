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

    private const NAMES = ['black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white'];
    private const CODES = [1 => 'bold', 2 => 'dim', 3 => 'italic', 4 => 'underlined', 21 => 'underlined', 5 => 'blinking', 7 => 'reversed', 8 => 'hidden', 9 => 'struck through'];

    /**
     * @param list<string> $attributes
     */
    public function __construct(
        public readonly array $attributes = [],
        public readonly ?string $foreground = null,
        public readonly ?string $background = null,
    ) {}

    public static function fromSgr(string $sgr): self
    {
        $match = [];
        if (!\preg_match('/^\x1b\[([0-9;:]*)m$/', $sgr, $match) || '' === $match[1]) {
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
                $attributes[self::CODES[$code]] = true;

                continue;
            }
            if (38 === $code || 48 === $code || 58 === $code) {
                [$color, $used] = self::extendedColor($codes, $i + 1);
                $i += $used;
                $foreground = 38 === $code ? $color : $foreground;
                $background = 48 === $code ? $color : $background;

                continue;
            }
            $foreground = self::basicColor($code, normal: 30, bright: 90) ?? $foreground;
            $background = self::basicColor($code, normal: 40, bright: 100) ?? $background;
        }

        return new self(\array_keys($attributes), $foreground, $background);
    }

    /**
     * The color of a basic SGR code: $normal to $normal + 7, or the bright $bright to $bright + 7.
     */
    private static function basicColor(int $code, int $normal, int $bright): ?string
    {
        return match (true) {
            $code >= $normal && $code <= ($normal + 7) => self::NAMES[$code - $normal],
            $code >= $bright && $code <= ($bright + 7) => 'bright-'.self::NAMES[$code - $bright],
            default => null,
        };
    }

    /**
     * The color after 38, 48 or 58: 5;n for the palette, 2;r;g;b for true color.
     *
     * @param list<string> $codes
     *
     * @return array{?string, int} The color, and how many codes it used
     */
    private static function extendedColor(array $codes, int $at): array
    {
        return match ($codes[$at] ?? null) {
            '5' => [self::name((string) (int) ($codes[$at + 1] ?? 0)), 2],
            '2' => [\sprintf('#%02x%02x%02x', (int) ($codes[$at + 1] ?? 0), (int) ($codes[$at + 2] ?? 0), (int) ($codes[$at + 3] ?? 0)), 4],
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
            if ($index < 8) {
                return self::NAMES[$index];
            }
            if ($index < 16) {
                return 'bright-'.self::NAMES[$index - 8];
            }

            return (string) $index;
        }
        $color = (string) \preg_replace('/[\s_]+/', replacement: '-', subject: $color);
        $base = \str_starts_with($color, 'bright-') ? \substr($color, offset: 7) : $color;
        if (!\in_array($base, self::NAMES, strict: true)) {
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
}
