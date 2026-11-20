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
 * Notices what a terminal shows outside its cells: bells and the window title.
 *
 * It reads complete output only (see OutputBuffer). A BEL ends an OSC
 * sequence as often as it rings, so string sequences are skipped first.
 */
final class TerminalObserver
{
    private int $bells = 0;
    private ?string $title = null;

    public function observe(string $output): void
    {
        // OSC 0 and OSC 2 set the window title (OSC 1 is the icon name)
        $matches = [];
        if (\preg_match_all('/\x1b\](?:0|2);([^\x07\x1b]*)(?:\x07|\x1b\\\\)/', $output, $matches)) {
            $this->title = \array_pop($matches[1]);
        }

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
}
