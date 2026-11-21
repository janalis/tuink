<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Selector;

/**
 * The item a list has selected.
 *
 * Two items can share a label, like the same setting under two groups; their
 * value tells them apart, and their position does when the list tells it.
 */
final readonly class Selection
{
    public function __construct(
        public string $label,
        public ?string $value = null,
        /** The item's position in the list, when the list tells it */
        public ?int $index = null,
    ) {
    }

    /**
     * Whether both name the same item, as far as the list tells.
     */
    public function is(self $other): bool
    {
        return $this->label === $other->label && $this->value === $other->value && $this->index === $other->index;
    }
}
