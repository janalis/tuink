<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

/**
 * Holds back the incomplete tail of terminal output until the rest arrives.
 *
 * A pipe hands output over in arbitrary chunks, which can end in the middle of
 * an escape sequence or of a UTF-8 character. ScreenBuffer parses each write on
 * its own, so a split "\x1b[38;5;233m" would print "5;233m" as text.
 */
final class OutputBuffer
{
    private string $pending = '';

    /**
     * Returns the output that is complete so far, keeping back an unfinished tail.
     */
    public function feed(string $data): string
    {
        $data = $this->pending.$data;
        $cut = self::completeLength($data);
        $this->pending = substr($data, $cut);

        return substr($data, 0, $cut);
    }

    /**
     * Returns everything held back, complete or not.
     */
    public function flush(): string
    {
        $pending = $this->pending;
        $this->pending = '';

        return $pending;
    }

    private static function completeLength(string $data): int
    {
        $length = \strlen($data);

        // A string sequence (OSC, DCS…) still open: its terminator may itself be split, as ESC then \
        if (preg_match('/\x1b[\]P_^X](?:(?!\x07|\x1b\\\\).)*$/s', $data, $match, \PREG_OFFSET_CAPTURE)) {
            return $match[0][1];
        }

        $escape = strrpos($data, "\x1b");
        if (false !== $escape && !self::isCompleteEscape($data, $escape)) {
            return $escape;
        }

        // A UTF-8 lead byte in the last three bytes whose character runs past the end
        for ($i = max(0, $length - 3); $i < $length; ++$i) {
            $byte = \ord($data[$i]);
            $needed = match (true) {
                $byte >= 0xF0 && $byte <= 0xF7 => 4,
                $byte >= 0xE0 => 3,
                $byte >= 0xC0 => 2,
                default => 0,
            };
            if ($needed > 0 && $i + $needed > $length && $byte <= 0xF7) {
                return $i;
            }
        }

        return $length;
    }

    private static function isCompleteEscape(string $data, int $start): bool
    {
        $length = \strlen($data);
        if ($start + 1 >= $length) {
            return false;
        }

        $kind = $data[$start + 1];

        // CSI: parameters and intermediates, then a final byte in @..~
        if ('[' === $kind) {
            for ($i = $start + 2; $i < $length; ++$i) {
                $byte = \ord($data[$i]);
                if ($byte >= 0x40 && $byte <= 0x7E) {
                    return true;
                }
            }

            return false;
        }

        // OSC, DCS, APC, PM, SOS: an open one was caught above
        if (\in_array($kind, [']', 'P', '_', '^', 'X'], true)) {
            return true;
        }

        // SS3 and charset designations take one more byte
        if (\in_array($kind, ['O', '(', ')', '*', '+', '#', ' '], true)) {
            return $start + 2 < $length;
        }

        return true;
    }
}
