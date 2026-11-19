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
use Janalis\Tuink\Selector\Element;
use Janalis\Tuink\Session;
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

    #[When('/^I focus (?:the (?:widget|element) )?"([^"]+)"$/')]
    public function focus(string $selector): void
    {
        $session = $this->getSession();
        $this->getTui()->setFocus($session->findOne($selector)->widget);
        $session->settle();
    }

    #[Then('/^the (?:widget|element) "([^"]+)" should be focused$/')]
    public function assertFocused(string $selector): void
    {
        $session = $this->getSession();
        $tui = $this->getTui();
        $session->waitUntil(
            static fn (): bool => [] !== array_filter($session->find($selector), static fn (Element $e): bool => $e->widget === $tui->getFocus()),
            static fn (): string => \sprintf('No element matching "%s" has the focus; %s has it.', $selector, self::describe($session, $tui->getFocus())),
        );
    }

    #[Then('/^the (?:widget|element) "([^"]+)" should have the value "([^"]*)"$/')]
    public function assertValue(string $selector, string $value): void
    {
        $session = $this->getSession();
        $widget = $session->findOne($selector)->widget;
        if (!method_exists($widget, 'getValue')) {
            throw new \LogicException(\sprintf('The element "%s" (%s) has no value.', $selector, $widget::class));
        }
        $session->waitUntil(
            static fn (): bool => $widget->getValue() === $value,
            static fn (): string => \sprintf('The element "%s" has the value "%s", not "%s".', $selector, (string) $widget->getValue(), $value),
        );
    }

    #[Then('/^I should see "([^"]*)" in "([^"]+)"$/')]
    public function assertSeeIn(string $text, string $selector): void
    {
        $this->getSession()->assertSeeIn($selector, $text);
    }

    #[Then('/^I should not see "([^"]*)" in "([^"]+)"$/')]
    public function assertNotSeeIn(string $text, string $selector): void
    {
        $this->getSession()->assertNotSeeIn($selector, $text);
    }

    #[Then('/^I should see an element matching "([^"]+)"$/')]
    public function assertElementVisible(string $selector): void
    {
        $this->getSession()->assertElementVisible($selector);
    }

    #[Then('/^I should not see an element matching "([^"]+)"$/')]
    public function assertElementNotVisible(string $selector): void
    {
        $this->getSession()->assertElementNotVisible($selector);
    }

    #[Then('/^there should be (\d+) elements? matching "([^"]+)"$/')]
    public function assertElementCount(int $count, string $selector): void
    {
        $this->getSession()->assertElementCount($selector, $count);
    }

    #[Then('/^the element "([^"]+)" should show:$/')]
    public function assertElementShows(string $selector, PyStringNode $text): void
    {
        $this->getSession()->assertElementShows($selector, $text->getRaw());
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

    private static function describe(Session $session, ?AbstractWidget $widget): string
    {
        if (null === $widget) {
            return 'nothing';
        }
        foreach ($session->find('*') as $element) {
            if ($element->widget === $widget) {
                return $element->describe();
            }
        }

        return $widget::class;
    }
}
