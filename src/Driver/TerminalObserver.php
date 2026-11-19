<?php

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
        if ('' === $output) {
            return;
        }

        // OSC 0 and OSC 2 set the window title (OSC 1 is the icon name)
        if (preg_match_all('/\x1b\](?:0|2);([^\x07\x1b]*)(?:\x07|\x1b\\\\)/', $output, $matches)) {
            $this->title = end($matches[1]);
        }

        $rest = (string) preg_replace('/\x1b[\]P_^X][^\x07\x1b]*(?:\x07|\x1b\\\\)/', '', $output);
        $this->bells += substr_count($rest, "\x07");
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
