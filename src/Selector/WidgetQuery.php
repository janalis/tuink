<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Selector;

use Symfony\Component\CssSelector\Exception\ParseException;
use Symfony\Component\CssSelector\XPath\Translator;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * Finds widgets in a WidgetTree with CSS selectors, through symfony/css-selector.
 *
 * Before the query, each widget without children gets as text what the screen
 * shows where it was drawn, so :contains("text") works; :focus and :visible
 * match its state.
 */
final class WidgetQuery
{
    private static ?Translator $translator = null;

    /**
     * @param \Closure(WidgetRect): string $textOf The screen text inside a rectangle
     */
    public function __construct(
        private readonly \Closure $textOf,
    ) {
    }

    /**
     * @return list<Element> The matching widgets, in tree order
     */
    public function select(WidgetTree $tree, string $selector): array
    {
        try {
            $xpath = self::translator()->cssToXPath($selector);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid CSS selector "%s": %s', $selector, $e->getMessage()), 0, $e);
        }

        $document = $tree->document;
        $finder = new \DOMXPath($document);
        $elements = [];
        foreach ($finder->query('//*') ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $elements[(int) $node->getAttribute(WidgetTree::INDEX)] = $this->element($tree, $node);
            }
        }

        $matches = $finder->query($xpath);
        if (false === $matches) {
            throw new \InvalidArgumentException(\sprintf('Invalid CSS selector "%s".', $selector));
        }

        $found = [];
        foreach ($matches as $node) {
            if ($node instanceof \DOMElement) {
                $found[] = $elements[(int) $node->getAttribute(WidgetTree::INDEX)];
            }
        }

        return $found;
    }

    private function element(WidgetTree $tree, \DOMElement $node): Element
    {
        $rect = null;
        $text = '';
        if ($node->hasAttribute(WidgetTree::VISIBLE)) {
            $rect = new WidgetRect(
                (int) $node->getAttribute(WidgetTree::ROW),
                (int) $node->getAttribute(WidgetTree::COLUMN),
                (int) $node->getAttribute(WidgetTree::COLUMNS),
                (int) $node->getAttribute(WidgetTree::ROWS),
            );
            $text = ($this->textOf)($rect);
            if ($node->hasAttribute(WidgetTree::LEAF) && '' !== $text && !$node->hasChildNodes()) {
                $node->appendChild($node->ownerDocument?->createTextNode($text) ?? throw new \LogicException('A detached element.'));
            }
        }

        $index = (int) $node->getAttribute(WidgetTree::INDEX);
        $class = $node->getAttribute('class');

        return new Element(
            index: $index,
            name: $node->localName ?? $node->nodeName,
            widgetClass: $node->getAttribute('widget'),
            id: $node->hasAttribute('id') ? $node->getAttribute('id') : null,
            classes: '' === $class ? [] : explode(' ', $class),
            label: $node->hasAttribute('label') ? $node->getAttribute('label') : null,
            value: $node->hasAttribute('value') ? $node->getAttribute('value') : null,
            focused: $node->hasAttribute(WidgetTree::FOCUS),
            rect: $rect,
            text: $text,
            widget: $tree->widgets[$index] ?? null,
        );
    }

    private static function translator(): Translator
    {
        if (null === self::$translator) {
            self::$translator = new Translator();
            self::$translator->registerExtension(new TuiPseudoClassExtension());
        }

        return self::$translator;
    }
}
