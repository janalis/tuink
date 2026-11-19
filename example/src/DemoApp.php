<?php

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
 * Ctrl+C quits with code 0, Escape in the color list with code 2.
 */
final class DemoApp
{
    public int $exitCode = 0;
    public readonly Tui $tui;

    public function __construct(?TerminalInterface $terminal = null)
    {
        $tui = $this->tui = new Tui(terminal: $terminal);

        $status = (new TextWidget('Status: loading'))->setId('status');
        $size = (new TextWidget(''))->setId('size');
        $name = (new InputWidget())->setId('name')->setPrompt('Name: ');
        $greeting = (new TextWidget(''))->setId('greeting')->addStyleClass('message');
        $items = [
            ['value' => 'red', 'label' => 'Red'],
            ['value' => 'green', 'label' => 'Green'],
            ['value' => 'blue', 'label' => 'Blue'],
        ];
        $colors = (new SelectListWidget($items))->setId('color');
        $choice = (new TextWidget(''))->setId('choice')->addStyleClass('message');

        $form = (new ContainerWidget())->setId('form');
        $form->add($name);
        $form->add($greeting);
        $form->add($colors);

        $tui->add((new TextWidget('Tuink demo'))->addStyleClass('title'));
        $tui->add($status);
        $tui->add($size);
        $tui->add($form);
        $tui->add($choice);
        $tui->add(new TextWidget('Enter: submit  Esc: leave  Ctrl+C: quit'));

        $name->onSubmit(static function (SubmitEvent $event) use ($tui, $greeting, $colors): void {
            $greeting->setText(\sprintf('Hello, %s!', $event->getValue()));
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
            }
        });
        $this->handleMouse($tui, $name, $greeting, $colors, $items, $choice);
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
     *
     * @param list<array{value: string, label: string}> $items The colors, in list order
     */
    private function handleMouse(Tui $tui, InputWidget $name, TextWidget $greeting, SelectListWidget $colors, array $items, TextWidget $choice): void
    {
        $lastPress = null;
        $dragging = false;
        $tui->addListener(static function (InputEvent $event) use ($tui, $name, $greeting, $colors, $items, $choice, &$lastPress, &$dragging): void {
            if (!preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])$/', $event->getData(), $match)) {
                return;
            }
            $event->stopPropagation();
            [$code, $column, $row, $press] = [(int) $match[1], (int) $match[2] - 1, (int) $match[3] - 1, 'M' === $match[4]];
            $button = $code & 0b11000011;
            $within = static function (AbstractWidget $widget) use ($tui, $row, $column): ?int {
                $rect = $tui->getWidgetRect($widget);

                return null !== $rect && $rect->contains($row, $column) ? $row - $rect->row : null;
            };

            if (64 === $button || 65 === $button) {
                if (null !== $within($colors)) {
                    $index = (int) array_search($colors->getSelectedItem()['value'] ?? null, array_column($items, 'value'), true);
                    $colors->setSelectedIndex(max(0, min(\count($items) - 1, $index + (65 === $button ? 1 : -1))));
                }
            } elseif (0 !== ($code & 32)) {
                // Motion with a button held
                $dragging = true;

                return;
            } elseif (!$press) {
                if ($dragging) {
                    $dragging = false;
                    $choice->setText(\sprintf('Dragged to %d:%d', $row + 1, $column + 1));
                    $tui->requestRender();
                }

                return;
            } elseif (2 === $button) {
                $choice->setText(\sprintf('Right click at %d:%d', $row + 1, $column + 1));
            } elseif (0 === $button && null !== $within($name)) {
                $now = microtime(true);
                $double = null !== $lastPress && [$row, $column] === $lastPress[1] && $now - $lastPress[0] < 0.4;
                $lastPress = [$now, [$row, $column]];
                $tui->setFocus($name);
                if ($double) {
                    $greeting->setText(\sprintf('Hello, %s!', $name->getValue()));
                    $tui->setFocus($colors);
                }
            } elseif (0 === $button && null !== $offset = $within($colors)) {
                $item = $items[$offset] ?? null;
                if (null !== $item) {
                    $colors->setSelectedIndex($offset);
                    $tui->setFocus($colors);
                    $choice->setText(\sprintf('You picked %s%s', $item['label'], 0 !== ($code & 16) ? ' with Ctrl' : ''));
                }
            }
            $tui->requestRender();
        });
    }
}
