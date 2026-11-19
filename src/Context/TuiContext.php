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
use Janalis\Tuink\Mouse;
use Janalis\Tuink\Selector\Element;
use Symfony\Component\Tui\Tui;

/**
 * Steps to drive a Tui application and check its screen.
 *
 * Every step works on both drivers. On the process driver, the steps with CSS
 * selectors need the application to install TuiProbe; see the README.
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

    #[When('/^I (double-|triple-|right-|middle-)?click "([^"]+)"(?: with "([^"]+)")?$/')]
    public function click(string $kind, string $selector, string $modifiers = ''): void
    {
        [$button, $count] = self::clickKind($kind);
        $this->getSession()->clickElement($selector, $button, $count, self::modifiers($modifiers));
    }

    #[When('/^I (double-|triple-|right-|middle-)?click on the text "([^"]+)"(?: with "([^"]+)")?$/')]
    public function clickText(string $kind, string $text, string $modifiers = ''): void
    {
        [$button, $count] = self::clickKind($kind);
        $this->getSession()->clickText($text, $button, $count, self::modifiers($modifiers));
    }

    #[When('/^I (double-|triple-|right-|middle-)?click at line (\d+), column (\d+)(?: with "([^"]+)")?$/')]
    public function clickAt(string $kind, int $line, int $column, string $modifiers = ''): void
    {
        [$button, $count] = self::clickKind($kind);
        $this->getSession()->click($line, $column, $button, $count, self::modifiers($modifiers));
    }

    #[When('/^I scroll (up|down|left|right)(?: (\d+) times?)? (?:in|over) "([^"]+)"$/')]
    public function scroll(string $direction, int|string $times, string $selector): void
    {
        $this->getSession()->scrollElement($selector, $direction, '' === $times ? 1 : (int) $times);
    }

    #[When('/^I scroll (up|down|left|right)(?: (\d+) times?)? at line (\d+), column (\d+)$/')]
    public function scrollAt(string $direction, int|string $times, int $line, int $column): void
    {
        $this->getSession()->scroll($line, $column, $direction, '' === $times ? 1 : (int) $times);
    }

    #[When('/^I drag "([^"]+)" to "([^"]+)"$/')]
    public function drag(string $from, string $to): void
    {
        $this->getSession()->dragElement($from, $to);
    }

    #[When('/^I drag from line (\d+), column (\d+) to line (\d+), column (\d+)$/')]
    public function dragAt(int $fromLine, int $fromColumn, int $toLine, int $toColumn): void
    {
        $this->getSession()->drag($fromLine, $fromColumn, $toLine, $toColumn);
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
        $this->getSession()->focus($selector);
    }

    #[Then('/^the (?:widget|element) "([^"]+)" should be focused$/')]
    public function assertFocused(string $selector): void
    {
        $session = $this->getSession();
        $session->waitUntil(
            static fn (): bool => [] !== array_filter($session->find($selector), static fn (Element $e): bool => $e->focused),
            static fn (): string => \sprintf('No element matching "%s" has the focus; %s has it.', $selector, implode(', ', array_map(static fn (Element $e): string => $e->describe(), $session->find(':focus'))) ?: 'nothing'),
        );
    }

    #[Then('/^the (?:widget|element) "([^"]+)" should have the value "([^"]*)"$/')]
    public function assertValue(string $selector, string $value): void
    {
        $session = $this->getSession();
        $element = $session->findOne($selector);
        if (null === $element->value) {
            throw new \LogicException(\sprintf('The element "%s" (%s) has no value.', $selector, $element->widgetClass));
        }
        $session->waitUntil(
            static fn (): bool => $session->findOne($selector)->value === $value,
            static fn (): string => \sprintf('The element "%s" has the value "%s", not "%s".', $selector, $session->findOne($selector)->value ?? '', $value),
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

    /**
     * @return array{string, int} The button and the number of clicks
     */
    private static function clickKind(string $kind): array
    {
        return match ($kind) {
            'double-' => [Mouse::LEFT, 2],
            'triple-' => [Mouse::LEFT, 3],
            'right-' => [Mouse::RIGHT, 1],
            'middle-' => [Mouse::MIDDLE, 1],
            default => [Mouse::LEFT, 1],
        };
    }

    /**
     * @return list<string>
     */
    private static function modifiers(string $modifiers): array
    {
        return '' === $modifiers ? [] : array_map(trim(...), explode('+', $modifiers));
    }
}
