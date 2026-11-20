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
 * Holds back the incomplete tail of terminal output until the rest arrives.
 *
 * A pipe hands output over in arbitrary chunks, which can end in the middle of
 * an escape sequence or of a UTF-8 character. ScreenBuffer parses each write on
 * its own, so a split "\x1b[38;5;233m" would print "5;233m" as text.
 */
final class OutputBuffer
{
    /** What output can end with that the next chunk completes */
    private const UNFINISHED = [
        // A string sequence (OSC, DCS, APC, PM, SOS) not yet terminated by BEL or ST; ST may itself be split, as ESC then \
        '/\x1b[\]P_^X](?:(?!\x07|\x1b\\\\).)*$/s',
        // ESC alone, a CSI without its final byte (@ to ~), or SS3 and charset designations without their byte
        '/\x1b(?:\[[\x20-\x3F]*|[O()*+# ])?$/',
        // A UTF-8 lead byte missing continuation bytes
        '/(?:[\xC0-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF7][\x80-\xBF]{0,2})$/',
    ];

    private string $pending = '';

    /**
     * Returns the output that is complete so far, keeping back an unfinished tail.
     */
    public function feed(string $data): string
    {
        $data = $this->pending.$data;
        $cut = self::completeLength($data);
        $this->pending = \substr($data, $cut);

        return \substr($data, offset: 0, length: $cut);
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
        foreach (self::UNFINISHED as $pattern) {
            $match = [];
            if (\preg_match($pattern, $data, $match, \PREG_OFFSET_CAPTURE)) {
                return (int) $match[0][1];
            }
        }

        return \strlen($data);
    }
}
