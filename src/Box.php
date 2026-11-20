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
 * A frame drawn with box-drawing characters, found by the title on its top border.
 *
 *     ╭─ Settings ──╮
 *     │ Theme: dark │
 *     ╰─────────────╯
 *
 * Coordinates are 0-based cells of a grid: one string per cell, '' where a wide
 * character's second half sits.
 */
final readonly class Box
{
    private const TOP_LEFT = ['┌', '╭', '╔', '┏', '╒', '╓', '┍', '┎'];
    private const TOP_RIGHT = ['┐', '╮', '╗', '┓', '╕', '╖', '┑', '┒'];
    private const BOTTOM_LEFT = ['└', '╰', '╚', '┗', '╘', '╙', '┕', '┖'];
    private const BOTTOM_RIGHT = ['┘', '╯', '╝', '┛', '╛', '╜', '┙', '┚'];
    private const LEFT_SIDE = ['│', '┃', '║', '╎', '╏', '┆', '┇', '┊', '┋', '├', '┝', '┞', '┟', '┠', '┡', '┢', '┣', '╞', '╟', '╠'];
    private const RIGHT_SIDE = ['│', '┃', '║', '╎', '╏', '┆', '┇', '┊', '┋', '┤', '┥', '┦', '┧', '┨', '┩', '┪', '┫', '╡', '╢', '╣'];

    private function __construct(
        public int $top,
        public int $left,
        public int $bottom,
        public int $right,
    ) {
    }

    /**
     * The first box, reading top to bottom, whose top border carries the title.
     *
     * @param list<list<string>> $grid
     */
    public static function find(array $grid, string $title): ?self
    {
        foreach ($grid as $row => $cells) {
            foreach (self::occurrences($cells, $title) as [$start, $end]) {
                $box = self::around($grid, $row, $start, $end);
                if (null !== $box) {
                    return $box;
                }
            }
        }

        return null;
    }

    /**
     * Where a text starts and ends in a row of cells, between two columns (the whole row by default), as [first cell, cell after the last].
     *
     * @param list<string> $cells
     *
     * @return list<array{int, int}>
     */
    public static function occurrences(array $cells, string $text, int $from = 0, ?int $to = null): array
    {
        $to ??= \count($cells);
        $found = [];
        for ($start = $from; $start < $to; ++$start) {
            if ('' === $cells[$start]) {
                continue;
            }
            $read = '';
            for ($col = $start; $col < $to && \strlen($read) < \strlen($text); ++$col) {
                $read .= $cells[$col];
            }
            if (\str_starts_with($read, $text)) {
                // A wide character's second half belongs to it
                while ($col < $to && '' === $cells[$col]) {
                    ++$col;
                }
                $found[] = [$start, $col];
            }
        }

        return $found;
    }

    /**
     * The text inside the frame, one line per row, trailing spaces trimmed.
     *
     * @param list<list<string>> $grid
     */
    public function text(array $grid): string
    {
        $lines = [];
        for ($row = $this->top + 1; $row < $this->bottom; ++$row) {
            $lines[] = \rtrim(\implode('', \array_slice($grid[$row], $this->left + 1, $this->right - $this->left - 1)));
        }

        return \implode("\n", $lines);
    }

    /**
     * The 0-based [row, column] where the text first shows inside the frame.
     *
     * @param list<list<string>> $grid
     *
     * @return array{int, int}|null
     */
    public function locate(array $grid, string $text): ?array
    {
        for ($row = $this->top + 1; $row < $this->bottom; ++$row) {
            $found = self::occurrences($grid[$row], $text, $this->left + 1, $this->right);
            if ([] !== $found) {
                return [$row, $found[0][0]];
            }
        }

        return null;
    }

    /**
     * The box whose top border holds the title at $row, between $start and $end.
     *
     * @param list<list<string>> $grid
     */
    private static function around(array $grid, int $row, int $start, int $end): ?self
    {
        $cells = $grid[$row];
        $left = $start - 1;
        while ($left >= 0 && !\in_array($cells[$left], self::TOP_LEFT, strict: true)) {
            if (\in_array($cells[$left], [...self::TOP_RIGHT, ...self::LEFT_SIDE], strict: true)) {
                return null;
            }
            --$left;
        }
        $right = $end;
        while ($right < \count($cells) && !\in_array($cells[$right], self::TOP_RIGHT, strict: true)) {
            if (\in_array($cells[$right], [...self::TOP_LEFT, ...self::RIGHT_SIDE], strict: true)) {
                return null;
            }
            ++$right;
        }
        if ($left < 0 || $right >= \count($cells)) {
            return null;
        }

        for ($bottom = $row + 1; $bottom < \count($grid); ++$bottom) {
            $first = $grid[$bottom][$left];
            $last = $grid[$bottom][$right];
            if (\in_array($first, self::BOTTOM_LEFT, strict: true) && \in_array($last, self::BOTTOM_RIGHT, strict: true)) {
                return new self($row, $left, $bottom, $right);
            }
            if (!\in_array($first, self::LEFT_SIDE, strict: true) || !\in_array($last, self::RIGHT_SIDE, strict: true)) {
                return null;
            }
        }

        return null;
    }
}
