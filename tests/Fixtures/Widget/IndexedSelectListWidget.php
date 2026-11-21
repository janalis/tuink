<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests\Fixtures\Widget;

use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * A select list that tells the position of its selected item, as newer releases of symfony/tui do.
 */
final class IndexedSelectListWidget extends SelectListWidget
{
    public function getSelectedIndex(): int
    {
        // SelectListWidget keeps it private before symfony/tui has the getter
        /** @var \Closure(): int $read */
        $read = \Closure::bind(fn (): int => $this->selectedIndex, $this, SelectListWidget::class);

        return $read();
    }
}
