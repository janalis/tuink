<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Selector;

use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * A widget a selector matched, with where it is drawn and what it shows.
 */
final class Element
{
    public function __construct(
        public readonly AbstractWidget $widget,
        public readonly string $name,
        public readonly ?WidgetRect $rect,
        public readonly string $text,
    ) {
    }

    /**
     * Whether the widget was drawn in the last frame.
     */
    public function isVisible(): bool
    {
        return null !== $this->rect && $this->rect->rows > 0 && $this->rect->columns > 0;
    }

    /**
     * A short description for messages, like input#name.field.
     */
    public function describe(): string
    {
        $id = $this->widget->getId();

        return $this->name.(null === $id ? '' : '#'.$id).implode('', array_map(static fn (string $class): string => '.'.$class, $this->widget->getStyleClasses()));
    }
}
