<?php

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Symfony\Component\Tui\Exception\InvalidArgumentException as TuiInvalidArgumentException;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Steps to drive a Tui application and check its screen.
 *
 * Every step works on both drivers, except the widget steps, which need the
 * application in the same process (@in_process).
 */
class TuiContext implements TuinkAwareContext
{
    use TuinkAwareTrait;

    #[Given('I start the app')]
    public function startApp(): void
    {
        $this->getSession()->start();
    }

    #[Given('/^I start the app in an? (\d+)x(\d+) terminal$/')]
    public function startAppWithSize(int $columns, int $rows): void
    {
        $this->getSession()->start($columns, $rows);
    }

    #[When('I stop the app')]
    public function stopApp(): void
    {
        $this->getTuink()->reset();
    }

    #[When('I type :text')]
    public function type(string $text): void
    {
        $this->getSession()->type($text);
    }

    #[When('/^I press "([^"]+)"(?: (\d+) times?)?$/')]
    public function press(string $key, int|string $times = 1): void
    {
        $this->getSession()->press($key, '' === $times ? 1 : (int) $times);
    }

    #[When('I send :text')]
    public function send(string $text): void
    {
        $this->getSession()->send($text);
    }

    #[When('I paste :text')]
    public function paste(string $text): void
    {
        $this->getSession()->paste($text);
    }

    #[When('I paste:')]
    public function pasteBlock(PyStringNode $text): void
    {
        $this->getSession()->paste($text->getRaw());
    }

    #[When('/^I resize the terminal to (\d+)x(\d+)$/')]
    public function resize(int $columns, int $rows): void
    {
        $this->getSession()->resize($columns, $rows);
    }

    #[When('/^I wait (\d+(?:\.\d+)?) seconds?$/')]
    public function wait(float $seconds): void
    {
        $session = $this->getSession();
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline && $session->getDriver()->isRunning()) {
            $session->getDriver()->pump(min(0.01, $deadline - microtime(true)));
        }
    }

    #[Then('I should see :text')]
    public function assertSee(string $text): void
    {
        $this->getSession()->assertSee($text);
    }

    #[Then('I should eventually see :text')]
    public function assertEventuallySee(string $text): void
    {
        // Every check waits up to the timeout; this wording says so in the scenario
        $this->getSession()->assertSee($text);
    }

    #[Then('I should not see :text')]
    public function assertNotSee(string $text): void
    {
        $this->getSession()->assertNotSee($text);
    }

    #[Then('I should see text matching :pattern')]
    public function assertSeeMatch(string $pattern): void
    {
        $this->getSession()->assertSeeMatch($pattern);
    }

    #[Then('/^line (\d+) should contain "([^"]*)"$/')]
    public function assertLineContains(int $number, string $text): void
    {
        $this->getSession()->assertLineContains($number, $text);
    }

    #[Then('/^line (\d+) should be "([^"]*)"$/')]
    public function assertLineEquals(int $number, string $text): void
    {
        $this->getSession()->assertLineEquals($number, $text);
    }

    #[Then('the screen should be:')]
    #[Then('the screen should match:')]
    public function assertScreenEquals(PyStringNode $screen): void
    {
        $this->getSession()->assertScreenEquals($screen->getRaw());
    }

    #[Then('/^the screen should match (?:the )?snapshot "([^"]+)"$/')]
    public function assertMatchesSnapshot(string $name): void
    {
        $this->getSession()->assertMatchesSnapshot($name);
    }

    #[Then('the app should be running')]
    public function assertRunning(): void
    {
        $session = $this->getSession();
        $session->settle();
        if (!$session->getDriver()->isRunning()) {
            throw new ExpectationException('The application is not running.', $session->dump());
        }
    }

    #[Then('the app should have exited')]
    public function assertExited(): void
    {
        $this->getSession()->assertExited();
    }

    #[Then('/^the app should have exited with code (\d+)$/')]
    public function assertExitedWithCode(int $code): void
    {
        $this->getSession()->assertExited($code);
    }

    #[Then('/^the region at line (\d+), column (\d+) should be:$/')]
    public function assertRegionEquals(int $line, int $column, PyStringNode $region): void
    {
        $this->getSession()->assertRegionEquals($line, $column, $region->getRaw());
    }

    #[Then('print the screen')]
    public function printScreen(): void
    {
        echo $this->getSession()->dump(), "\n";
    }

    #[When('/^I focus (?:the widget )?"([^"]+)"$/')]
    public function focusWidget(string $id): void
    {
        $tui = $this->getTui();
        $tui->setFocus($this->getWidget($tui, $id));
        $this->getSession()->settle();
    }

    #[Then('the widget :id should be focused')]
    public function assertWidgetFocused(string $id): void
    {
        $tui = $this->getTui();
        $widget = $this->getWidget($tui, $id);
        $this->getSession()->waitUntil(
            static fn (): bool => $tui->getFocus() === $widget,
            static fn (): string => \sprintf('The widget "%s" is not focused; the focus is on %s.', $id, self::describe($tui->getFocus())),
        );
    }

    #[Then('the widget :id should have the value :value')]
    public function assertWidgetValue(string $id, string $value): void
    {
        $widget = $this->getWidget($this->getTui(), $id);
        if (!method_exists($widget, 'getValue')) {
            throw new \LogicException(\sprintf('The widget "%s" (%s) has no value.', $id, $widget::class));
        }
        $this->getSession()->waitUntil(
            static fn (): bool => $widget->getValue() === $value,
            static fn (): string => \sprintf('The widget "%s" has the value "%s", not "%s".', $id, (string) $widget->getValue(), $value),
        );
    }

    /**
     * The application's Tui, when it runs in this process.
     */
    protected function getTui(): Tui
    {
        $driver = $this->getSession()->getDriver();
        if (!$driver instanceof InProcessDriver) {
            throw new UnsupportedDriverException(\sprintf('This step needs the in_process driver, the scenario runs on "%s"; tag it @in_process.', $driver->getName()));
        }

        return $driver->getTui();
    }

    private function getWidget(Tui $tui, string $id): AbstractWidget
    {
        try {
            return $tui->getById($id);
        } catch (TuiInvalidArgumentException $e) {
            throw new ExpectationException(\sprintf('No widget has the id "%s".', $id), $this->getSession()->dump());
        }
    }

    private static function describe(?AbstractWidget $widget): string
    {
        if (null === $widget) {
            return 'nothing';
        }

        return null === $widget->getId() ? $widget::class : \sprintf('"%s" (%s)', $widget->getId(), $widget::class);
    }
}
