<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Driver;

use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Selector\WidgetTree;

/**
 * A driver that can show the application's widget tree, for CSS selectors.
 */
interface WidgetTreeProviderInterface
{
    /**
     * The widget tree as of the last frame.
     *
     * @throws UnsupportedDriverException When the application does not share its widgets
     */
    public function getWidgetTree(): WidgetTree;

    /**
     * Gives the focus to a widget, by its index in the last tree.
     */
    public function focusWidget(int $index): void;
}
