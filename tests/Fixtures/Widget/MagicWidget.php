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
 * A widget with getters that are not plain public methods: getValue() only __call() answers, and the position of its
 * selected item is private.
 */
final class MagicWidget extends AbstractWidget
{
    public function __call(string $name, array $arguments): mixed
    {
        return 'getValue' === $name ? 'magic' : null;
    }

    /**
     * @return array{label: string}
     */
    public function getSelectedItem(): array
    {
        return ['label' => 'Red'];
    }

    public function render(RenderContext $context): array
    {
        return ['magic', (string) $this->getSelectedIndex()];
    }

    private function getSelectedIndex(): int
    {
        return 7;
    }
}
