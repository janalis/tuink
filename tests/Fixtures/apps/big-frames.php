<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// After the first key, a timer keeps writing far more than a pty buffer holds, so the application blocks on its
// own output whenever the driver stops reading.

use Janalis\Tuink\Probe\TuiProbe;
use Revolt\EventLoop;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

require \dirname(__DIR__, levels: 3).'/vendor/autoload.php';

$tui = new Tui();
$text = new TextWidget('')->setId('page');
$tui->add($text);
$page = static fn (string $char): string => \implode("\n", \array_fill(start_index: 0, count: 90, value: \str_repeat($char, times: 190)));
$text->setText($page('a'));
$tui->addListener(static function (InputEvent $event) use ($tui, $text, $page): void {
    $text->setText($page($event->getData()));
    $tui->requestRender();
    EventLoop::repeat(0.05, static fn () => $tui->getTerminal()->write(\str_repeat("\x1b[0m", times: 50_000)));
});
TuiProbe::install($tui);
$tui->run();
