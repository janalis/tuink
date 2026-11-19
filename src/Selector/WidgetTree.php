<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Selector;

use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ParentInterface;

/**
 * The widget tree of a Tui as an XML document CSS selectors can query.
 *
 * Each widget becomes an element:
 *
 *  - named after its class, without the "Widget" suffix, in kebab-case:
 *    SelectListWidget is select-list, App\Ui\FileTree is file-tree;
 *  - with the attributes id, class (its style classes), label, value (for
 *    widgets whose getValue() returns a string or a number) and widget (its
 *    class name);
 *  - with tuink-* attributes for its state and where it was drawn, which
 *    :focus, :visible and the text lookup read.
 *
 * The root container, which Tui::add() adds to, is the document element. The
 * document carries no text: what a widget shows is read from the screen the
 * driver decoded, so it is the same whether the tree came from this process or
 * from the application's probe.
 */
final class WidgetTree
{
    public const INDEX = 'tuink-index';
    public const FOCUS = 'tuink-focus';
    public const VISIBLE = 'tuink-visible';
    public const LEAF = 'tuink-leaf';
    public const ROW = 'tuink-row';
    public const COLUMN = 'tuink-col';
    public const ROWS = 'tuink-rows';
    public const COLUMNS = 'tuink-columns';

    /**
     * @param list<AbstractWidget> $widgets The widgets by tuink-index, when they live in this process
     */
    public function __construct(
        public readonly \DOMDocument $document,
        public readonly array $widgets = [],
    ) {
    }

    public static function fromTui(Tui $tui): self
    {
        $document = new \DOMDocument();
        $widgets = [];
        $document->appendChild(self::mirror($tui, $document, self::root($tui), $widgets));

        return new self($document, $widgets);
    }

    public static function fromXml(string $xml): self
    {
        $document = new \DOMDocument();
        if ('' === $xml || !@$document->loadXML($xml, \LIBXML_NONET)) {
            throw new \UnexpectedValueException('The widget tree is not valid XML.');
        }

        return new self($document);
    }

    public function toXml(): string
    {
        return (string) $this->document->saveXML($this->document->documentElement);
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
     * @param list<AbstractWidget> $widgets
     */
    private static function mirror(Tui $tui, \DOMDocument $document, AbstractWidget $widget, array &$widgets): \DOMElement
    {
        $index = \count($widgets);
        $widgets[] = $widget;

        $node = $document->createElement(self::elementName($widget::class));
        $node->setAttribute(self::INDEX, (string) $index);
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
            $node->setAttribute(self::FOCUS, '');
        }

        $rect = $tui->getWidgetRect($widget);
        if (null !== $rect && $rect->rows > 0 && $rect->columns > 0) {
            $node->setAttribute(self::VISIBLE, '');
            $node->setAttribute(self::ROW, (string) $rect->row);
            $node->setAttribute(self::COLUMN, (string) $rect->col);
            $node->setAttribute(self::ROWS, (string) $rect->rows);
            $node->setAttribute(self::COLUMNS, (string) $rect->columns);
        }

        $children = $widget instanceof ParentInterface ? $widget->all() : [];
        if ([] === $children) {
            $node->setAttribute(self::LEAF, '');
        }
        foreach ($children as $child) {
            $node->appendChild(self::mirror($tui, $document, $child, $widgets));
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
        // Releases without Tui::getRoot() keep the root container private: read the property then
        if (method_exists($tui, 'getRoot')) {
            $root = $tui->getRoot();
        } else {
            try {
                $root = (new \ReflectionProperty(Tui::class, 'root'))->getValue($tui);
            } catch (\ReflectionException $e) {
                throw new \LogicException('This version of symfony/tui has neither Tui::getRoot() nor a root property tuink can read.', 0, $e);
            }
        }
        if (!$root instanceof AbstractWidget) {
            throw new \LogicException('This version of symfony/tui has neither Tui::getRoot() nor a root property tuink can read.');
        }

        return $root;
    }
}
