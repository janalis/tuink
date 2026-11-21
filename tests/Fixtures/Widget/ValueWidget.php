<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests\Fixtures\Widget;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A widget whose value, selected item and its position are whatever a test gives, as a custom widget's could be.
 */
final class ValueWidget extends AbstractWidget
{
    public function __construct(
        private readonly mixed $value,
        private readonly mixed $selectedItem = null,
        private readonly mixed $selectedIndex = null,
    ) {
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getSelectedItem(): mixed
    {
        return $this->selectedItem;
    }

    public function getSelectedIndex(): mixed
    {
        return $this->selectedIndex;
    }

    public function render(RenderContext $context): array
    {
        return ['value'];
    }
}
