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
 * Runs a call that reports failure through a PHP warning, keeping the warning's message instead of printing it.
 *
 * @internal
 */
final class Warnings
{
    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return array{T, ?string} What the call returned, and the last warning it raised
     */
    public static function capture(\Closure $call): array
    {
        $warning = null;
        \set_error_handler(static function (int $type, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$call(), $warning];
        } finally {
            \restore_error_handler();
        }
    }
}
