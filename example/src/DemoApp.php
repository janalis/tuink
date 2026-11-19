<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Example;

use Revolt\EventLoop;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * A small form the self-tests drive: a name, a color, a status set by a timer.
 *
 * Ctrl+C quits with code 0, Escape in the color list with code 2; Ctrl+G rings the bell.
 */
final class DemoApp
{
    private const ITEMS = [
        ['value' => 'red', 'label' => 'Red'],
        ['value' => 'green', 'label' => 'Green'],
        ['value' => 'blue', 'label' => 'Blue'],
    ];

    public int $exitCode = 0;
    public readonly Tui $tui;
    private readonly InputWidget $name;
    private readonly TextWidget $greeting;
    private readonly SelectListWidget $colors;
    private readonly TextWidget $choice;
    /** @var list<array{value: string, label: string}> */
    private readonly array $items;
    /** @var array{float, array{int, int}}|null When and where the last left press on the name field was */
    private ?array $lastPress = null;
    private bool $dragging = false;

    public function __construct(?TerminalInterface $terminal = null)
    {
        $this->tui = new Tui(terminal: $terminal);
        $tui = $this->tui;

        $status = new TextWidget('Status: loading')->setId('status');
        $size = new TextWidget('')->setId('size');
        $name = new InputWidget()
            ->setId('name')
            ->setPrompt('Name: ');
        $greeting = new TextWidget('')
            ->setId('greeting')
            ->addStyleClass('message');
        $items = self::ITEMS;
        $colors = new SelectListWidget($items)->setId('color');
        $choice = new TextWidget('')
            ->setId('choice')
            ->addStyleClass('message');
        $this->name = $name;
        $this->greeting = $greeting;
        $this->colors = $colors;
        $this->choice = $choice;
        $this->items = $items;

        $form = new ContainerWidget()->setId('form');
        $form->add($name);
        $form->add($greeting);
        $form->add($colors);

        $tui->add(
            new TextWidget('Tuink demo')
                ->addStyleClass('title')
                ->setStyle(new Style(color: '#5f87ff', bold: true)),
        );
        $tui->getTerminal()->setTitle('Tuink demo');
        $tui->add($status);
        $tui->add($size);
        $tui->add($form);
        $tui->add($choice);
        $tui->add(new TextWidget('Enter: submit  Esc: leave  Ctrl+C: quit')->setStyle(new Style(background: '#303030')));

        $name->onSubmit(static function (SubmitEvent $event) use ($tui, $greeting, $colors): void {
            $greeting->setText(\sprintf('Hello, %s!', $event->getValue()));
            if ('' !== $event->getValue()) {
                $tui->getTerminal()->setTitle(\sprintf('Tuink demo: %s', $event->getValue()));
            }
            $tui->setFocus($colors);
        });
        $colors->onSelect(static function (SelectEvent $event) use ($choice): void {
            $choice->setText(\sprintf('You picked %s', $event->getLabel()));
        });
        $colors->onCancel(function (CancelEvent $event) use ($tui): void {
            $this->exitCode = 2;
            $tui->stop();
        });
        $tui->addListener(static function (InputEvent $event) use ($tui): void {
            if ("\x03" === $event->getData()) {
                $event->stopPropagation();
                $tui->stop();

                return;
            }
            if ("\x07" === $event->getData()) {
                // Ctrl+G, the bell key
                $event->stopPropagation();
                $tui->getTerminal()->bell();
            }
        });
        $this->handleMouse();
        $tui->onTick(static function () use ($tui, $size): void {
            $text = \sprintf('Size: %dx%d', $tui->getTerminal()->getColumns(), $tui->getTerminal()->getRows());
            if ($text !== $size->getText()) {
                $size->setText($text);
            }
        });

        // Work that finishes later, as an HTTP call or a file scan would; outside input and ticks, a change must ask for its frame
        EventLoop::delay(0.3, static function () use ($tui, $status): void {
            $status->setText('Status: ready');
            $tui->requestRender();
        });

        $tui->setFocus($name);
    }

    /**
     * Symfony Tui hands SGR mouse reports to the application as input; the demo hit-tests them itself.
     *
     * A click on the name field focuses it and a double click submits it; a click on a color picks it,
     * with " with Ctrl" when Ctrl is held; the wheel moves through the colors; a right click says where it landed,
     * and so does the release that ends a drag.
     */
    private function handleMouse(): void
    {
        $this->tui->addListener(function (InputEvent $event): void {
            $match = [];
            if (!\preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $event->getData(), $match)) {
                return;
            }
            $event->stopPropagation();
            [$code, $row, $column] = [(int) $match[1], (int) $match[3] - 1, (int) $match[2] - 1];
            'M' === $match[4] ? $this->press($code, $row, $column) : $this->release($row, $column);
            $this->tui->requestRender();
        });
    }

    /**
     * @param int $row    0-based
     * @param int $column 0-based
     */
    private function press(int $code, int $row, int $column): void
    {
        $button = $code & 0b1100_0011;
        if (64 === $button || 65 === $button) {
            $this->wheel(65 === $button ? 1 : -1, $row, $column);

            return;
        }
        if (0 !== ($code & 32)) {
            // Motion with a button held
            $this->dragging = true;

            return;
        }
        if (2 === $button) {
            $this->choice->setText(\sprintf('Right click at %d:%d', $row + 1, $column + 1));

            return;
        }
        if (0 !== $button) {
            return;
        }
        if (null !== $this->within($this->name, $row, $column)) {
            $this->clickName($row, $column);

            return;
        }
        $offset = $this->within($this->colors, $row, $column);
        $item = null === $offset ? null : $this->items[$offset] ?? null;
        if (null === $offset || null === $item) {
            return;
        }
        $this->colors->setSelectedIndex($offset);
        $this->tui->setFocus($this->colors);
        $this->choice->setText(\sprintf('You picked %s%s', $item['label'], 0 !== ($code & 16) ? ' with Ctrl' : ''));
    }

    /**
     * The release that ends a drag says where it landed; a click's release does nothing.
     */
    private function release(int $row, int $column): void
    {
        if (!$this->dragging) {
            return;
        }
        $this->dragging = false;
        $this->choice->setText(\sprintf('Dragged to %d:%d', $row + 1, $column + 1));
    }

    private function wheel(int $step, int $row, int $column): void
    {
        if (null === $this->within($this->colors, $row, $column)) {
            return;
        }
        $index = (int) \array_search($this->colors->getSelectedItem()['value'] ?? null, \array_column($this->items, column_key: 'value'), strict: true);
        $this->colors->setSelectedIndex(\max(0, \min(\count($this->items) - 1, $index + $step)));
    }

    private function clickName(int $row, int $column): void
    {
        $now = \microtime(true);
        $double = null !== $this->lastPress && [$row, $column] === $this->lastPress[1] && ($now - $this->lastPress[0]) < 0.4;
        $this->lastPress = [$now, [$row, $column]];
        $this->tui->setFocus($this->name);
        if ($double) {
            $this->greeting->setText(\sprintf('Hello, %s!', $this->name->getValue()));
            $this->tui->setFocus($this->colors);
        }
    }

    /**
     * The row inside the widget the cell falls on, or null when it is outside.
     */
    private function within(AbstractWidget $widget, int $row, int $column): ?int
    {
        $rect = $this->tui->getWidgetRect($widget);

        return null !== $rect && $rect->contains($row, $column) ? $row - $rect->row : null;
    }
}
