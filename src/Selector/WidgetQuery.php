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
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ParentInterface;

/**
 * Finds the widgets of a Tui with CSS selectors.
 *
 * The widget tree is mirrored into an XML document, which symfony/css-selector
 * queries. Each widget becomes an element:
 *
 *  - named after its class, without the "Widget" suffix, in kebab-case:
 *    SelectListWidget is select-list, App\Ui\FileTree is file-tree;
 *  - with the attributes id, class (its style classes), label, value (for
 *    widgets with a string getValue()) and widget (its class name);
 *  - matching :focus when it has the focus, and :visible when it was drawn;
 *  - holding, when it has no children, the text the screen shows where it was
 *    drawn, so :contains("text") works.
 *
 * The root container, which Tui::add() adds to, is the document element.
 */
final class WidgetQuery
{
    private static ?Translator $translator = null;

    /**
     * @param \Closure(WidgetRect): string $textOf The screen text inside a rectangle
     */
    public function __construct(
        private readonly Tui $tui,
        private readonly \Closure $textOf,
    ) {
    }

    /**
     * @return list<Element> The matching widgets, in tree order
     */
    public function select(string $selector): array
    {
        try {
            $xpath = self::translator()->cssToXPath($selector);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('Invalid CSS selector "%s": %s', $selector, $e->getMessage()), 0, $e);
        }

        $document = new \DOMDocument();
        /** @var array<int, Element> $elements */
        $elements = [];
        $document->appendChild($this->mirror($document, self::root($this->tui), $elements));

        $matches = (new \DOMXPath($document))->query($xpath);
        if (false === $matches) {
            throw new \InvalidArgumentException(\sprintf('Invalid CSS selector "%s".', $selector));
        }

        $found = [];
        foreach ($matches as $node) {
            if ($node instanceof \DOMElement) {
                $found[] = $elements[(int) $node->getAttribute('tuink-index')];
            }
        }

        return $found;
    }

    /**
     * The element name a widget class gets: SelectListWidget is select-list.
     *
     * @param string $class A widget class name; the class need not be loaded
     */
    public static function elementName(string $class): string
    {
        $short = substr($class, (int) strrpos('\\'.$class, '\\'));
        if (str_ends_with($short, 'Widget') && 'Widget' !== $short) {
            $short = substr($short, 0, -6);
        }

        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $short));
    }

    /**
     * @param array<int, Element> $elements
     */
    private function mirror(\DOMDocument $document, AbstractWidget $widget, array &$elements): \DOMElement
    {
        $name = self::elementName($widget::class);
        $rect = $this->tui->getWidgetRect($widget);
        $children = $widget instanceof ParentInterface ? $widget->all() : [];
        $visible = null !== $rect && $rect->rows > 0 && $rect->columns > 0;
        $text = $visible ? ($this->textOf)($rect) : '';

        $index = \count($elements);
        $elements[$index] = new Element($widget, $name, $rect, $text);

        $node = $document->createElement($name);
        $node->setAttribute('tuink-index', (string) $index);
        $node->setAttribute('widget', $widget::class);
        if (null !== $id = $widget->getId()) {
            $node->setAttribute('id', $id);
        }
        if ([] !== $classes = $widget->getStyleClasses()) {
            $node->setAttribute('class', implode(' ', $classes));
        }
        if (null !== $label = $widget->getLabel()) {
            $node->setAttribute('label', $label);
        }
        if (null !== $value = self::value($widget)) {
            $node->setAttribute('value', $value);
        }
        if (\in_array('focus', $widget->getStateFlags(), true)) {
            $node->setAttribute(TuiPseudoClassExtension::FOCUS_ATTRIBUTE, '');
        }
        if ($visible) {
            $node->setAttribute(TuiPseudoClassExtension::VISIBLE_ATTRIBUTE, '');
        }

        if ([] === $children) {
            if ('' !== $text) {
                $node->appendChild($document->createTextNode($text));
            }
        } else {
            foreach ($children as $child) {
                $node->appendChild($this->mirror($document, $child, $elements));
            }
        }

        return $node;
    }

    private static function value(AbstractWidget $widget): ?string
    {
        if (!method_exists($widget, 'getValue')) {
            return null;
        }
        $value = $widget->getValue();

        return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : null;
    }

    private static function root(Tui $tui): AbstractWidget
    {
        // Tui keeps its root container private; it is what Tui::add() adds to
        try {
            $root = (new \ReflectionProperty(Tui::class, 'root'))->getValue($tui);
        } catch (\ReflectionException $e) {
            throw new \LogicException('This version of symfony/tui has no root container tuink can read.', 0, $e);
        }
        if (!$root instanceof AbstractWidget) {
            throw new \LogicException('This version of symfony/tui has no root container tuink can read.');
        }

        return $root;
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
