<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

/**
 * Notices what a terminal shows outside its cells: bells, the window title, the
 * clipboard (OSC 52), the modes the application set, the cursor's shape and the
 * kitty keyboard protocol queries.
 *
 * It reads complete output only (see OutputBuffer). A BEL ends an OSC
 * sequence as often as it rings, so string sequences are skipped first.
 */
final class TerminalObserver
{
    /** DECSCUSR shapes, by parameter; 0 is the terminal's default, a blinking block in most */
    public const CURSOR_SHAPES = [0 => 'blinking block', 1 => 'blinking block', 2 => 'block', 3 => 'blinking underline', 4 => 'underline', 5 => 'blinking bar', 6 => 'bar'];

    private int $bells = 0;
    private ?string $title = null;
    private ?string $clipboard = null;
    /** @var array<int, bool> DEC private modes, by number, as last set or reset */
    private array $modes = [];
    private ?int $cursorShape = null;
    private int $kittyQueries = 0;

    public function observe(string $output): void
    {
        // OSC 0 and OSC 2 set the window title (OSC 1 is the icon name)
        $matches = [];
        if (\preg_match_all('/\x1b\](?:0|2);([^\x07\x1b]*)(?:\x07|\x1b\\\\)/', $output, $matches)) {
            $this->title = \array_pop($matches[1]);
        }

        // OSC 52 writes the clipboard as base64; "?" asks for it instead
        $matches = [];
        if (\preg_match_all('/\x1b\]52;[a-z0-9]*;([A-Za-z0-9+\/=]*)(?:\x07|\x1b\\\\)/', $output, $matches)) {
            $this->clipboard = (string) \base64_decode((string) \array_pop($matches[1]), strict: true);
        }

        $matches = [];
        \preg_match_all('/\x1b\[\?([\d;]+)([hl])/', $output, $matches, \PREG_SET_ORDER);
        foreach ($matches as [, $modes, $set]) {
            foreach (\explode(';', $modes) as $mode) {
                $this->modes[(int) $mode] = 'h' === $set;
            }
        }

        $matches = [];
        if (\preg_match_all('/\x1b\[(\d*) q/', $output, $matches)) {
            $this->cursorShape = (int) \array_pop($matches[1]);
        }

        $this->kittyQueries += \substr_count($output, needle: "\x1b[?u");

        // A BEL that ends a string sequence (OSC, DCS…) is matched with it and does not count
        $bells = [];
        \preg_match_all('/\x1b[\]P_^X][^\x07\x1b]*(?:\x07|\x1b\\\\)|(\x07)/', $output, $bells);
        $this->bells += \count(\array_filter($bells[1]));
    }

    public function getBellCount(): int
    {
        return $this->bells;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * What the application last copied to the clipboard, null if it never did.
     */
    public function getClipboard(): ?string
    {
        return $this->clipboard;
    }

    /**
     * Whether the application last set (true) or reset (false) a DEC private mode, null if it never touched it.
     */
    public function getMode(int $mode): ?bool
    {
        return $this->modes[$mode] ?? null;
    }

    /**
     * The cursor shape the application last asked for, null if it never did.
     */
    public function getCursorShape(): ?string
    {
        return null === $this->cursorShape ? null : self::CURSOR_SHAPES[$this->cursorShape] ?? \sprintf('shape %d', $this->cursorShape);
    }

    /**
     * How many times the application asked whether the terminal speaks the kitty keyboard protocol.
     */
    public function getKittyQueryCount(): int
    {
        return $this->kittyQueries;
    }
}
