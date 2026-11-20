<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Util;

/**
 * @internal
 */
final class Text
{
    /**
     * Screen text as checks compare it: trailing spaces and trailing empty lines removed.
     */
    public static function normalize(string $text): string
    {
        return \rtrim(\implode("\n", \array_map(rtrim(...), \explode("\n", $text))), characters: "\n");
    }
}
