<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Check;

use Janalis\Tuink\Session;
use Janalis\Tuink\Style;

/**
 * Checks on where a text shows and whether it is colored at all.
 */
final readonly class TextChecks
{
    public function __construct(
        private Session $session,
    ) {
    }

    /**
     * Checks that every cell of the text has a foreground color, whichever it is.
     */
    public function assertColored(string $text): void
    {
        $this->session->waitUntil(
            fn (): bool => [] === \array_filter($this->session->getTextStyles($text), static fn (Style $s): bool => null === $s->foreground),
            fn (): string => \sprintf('The text "%s" is not colored: %s.', $text, $this->describe($text)),
        );
    }

    /**
     * Checks that no cell of the text has a foreground color.
     */
    public function assertNotColored(string $text): void
    {
        $this->session->waitUntil(
            fn (): bool => [] === \array_filter($this->session->getTextStyles($text), static fn (Style $s): bool => null !== $s->foreground),
            fn (): string => \sprintf('The text "%s" is colored: %s.', $text, $this->describe($text)),
        );
    }

    /**
     * Checks that the first line showing $anchor also shows $text: what trails a line of code, a row's state.
     */
    public function assertLineShowingContains(string $anchor, string $text): void
    {
        $this->session->waitUntil(
            fn (): bool => \str_contains($this->lineShowing($anchor) ?? '', $text),
            fn (): string => $this->failure($anchor, \sprintf('does not contain "%s"', $text)),
        );
    }

    /**
     * Checks that the first line showing $anchor does not show $text.
     */
    public function assertLineShowingNotContains(string $anchor, string $text): void
    {
        $this->session->waitUntil(
            fn (): bool => null !== $this->lineShowing($anchor) && !\str_contains((string) $this->lineShowing($anchor), $text),
            fn (): string => $this->failure($anchor, \sprintf('contains "%s"', $text)),
        );
    }

    /**
     * The first line of the screen that contains $anchor, or null.
     */
    public function lineShowing(string $anchor): ?string
    {
        $lines = \array_values(\array_filter($this->session->getLines(), static fn (string $line): bool => \str_contains($line, $anchor)));

        return $lines[0] ?? null;
    }

    private function failure(string $anchor, string $what): string
    {
        $line = $this->lineShowing($anchor);

        return null === $line ? \sprintf('No line shows "%s".', $anchor) : \sprintf('The line showing "%s" %s: %s', $anchor, $what, $line);
    }

    private function describe(string $text): string
    {
        $styles = \array_map(static fn (Style $s): string => $s->describe(), $this->session->getTextStyles($text));

        return 1 === \count(\array_unique($styles)) ? $styles[0] : \implode(' | ', $styles);
    }
}
