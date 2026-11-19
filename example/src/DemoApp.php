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
        $colors = (new SelectListWidget([
            ['value' => 'red', 'label' => 'Red'],
            ['value' => 'green', 'label' => 'Green'],
            ['value' => 'blue', 'label' => 'Blue'],
        ]))->setId('color');
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
}
