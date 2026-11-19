<?php

declare(strict_types=1);

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
 * A widget a selector matched: what it is, where it is drawn and what it shows.
 */
final class Element
{
    /**
     * @param list<string> $classes
     */
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly string $widgetClass,
        public readonly ?string $id,
        public readonly array $classes,
        public readonly ?string $label,
        public readonly ?string $value,
        /** The label of the item a list has selected */
        public readonly ?string $selected,
        public readonly bool $focused,
        public readonly ?WidgetRect $rect,
        public readonly string $text,
        /** The widget itself, when the application runs in this process */
        public readonly ?AbstractWidget $widget = null,
    ) {
    }

    /**
     * Whether the widget was drawn in the last frame.
     */
    public function isVisible(): bool
    {
        return null !== $this->rect;
    }

    /**
     * A short description for messages, like input#name.field.
     */
    public function describe(): string
    {
        return $this->name.(null === $this->id ? '' : '#'.$this->id).\implode('', \array_map(static fn (string $class): string => '.'.$class, $this->classes));
    }
}
