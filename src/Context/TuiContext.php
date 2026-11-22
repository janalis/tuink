<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Launch;
use Janalis\Tuink\Mouse;
use Janalis\Tuink\Selector\Element;
use Janalis\Tuink\Session;
use Symfony\Component\Tui\Tui;

/**
 * Steps to drive a Tui application and check its screen.
 *
 * Every step works on both drivers. On the process driver, the steps with CSS
 * selectors need the application to install TuiProbe; see the README.
 */
class TuiContext implements TuinkAwareContextInterface
{
    use TuinkAwareTrait;
    use WorkspaceStepsTrait;
    use TerminalStepsTrait;

    /**
     * The arguments follow the configured command, split as a shell would with single quotes; "" removes those of an
     * earlier start, and a start without arguments keeps them.
     */
    #[Given('/^I start the app(?: in an? (\d+)x(\d+) terminal)?( with the arguments? "([^"]*)")?$/')]
    public function startApp(string $columns = '', string $rows = '', string $withArguments = '', string $arguments = ''): void
    {
        $session = $this->getSession();
        if ('' !== $withArguments) {
            $session->setLaunch($session->getLaunch()->withArguments(Launch::split($arguments)));
        }
        '' === $columns ? $session->start() : $session->start((int) $columns, (int) $rows);
    }

    /**
     * Stops the application; the workspace stays, so the next steps can check what it saved.
     */
    #[When('I stop the app')]
    public function stopApp(): void
    {
        $this->getSession()->stop();
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

    #[When('/^I (double-|triple-|right-|middle-)?click on the last "([^"]+)" on line (\d+)(?: with "([^"]+)")?$/')]
    public function clickLastOnLine(string $kind, string $text, int $line, string $modifiers = ''): void
    {
        [$button, $count] = self::clickKind($kind);
        $this->getSession()->text()->clickLastOnLine($text, $line, $button, $count, self::modifiers($modifiers));
    }

    #[When('/^I (double-|triple-|right-|middle-)?click on the text "([^"]+)" in the (?:box|frame|dialog|panel|window) "([^"]+)"(?: with "([^"]+)")?$/')]
    public function clickTextInBox(string $kind, string $text, string $title, string $modifiers = ''): void
    {
        [$button, $count] = self::clickKind($kind);
        $this->getSession()->box($title)->clickText($text, $button, $count, self::modifiers($modifiers));
    }

    #[When('/^I (double-|triple-|right-|middle-)?click at line (\d+), column (\d+)(?: with "([^"]+)")?$/')]
    public function clickAt(string $kind, int $line, int $column, string $modifiers = ''): void
    {
        [$button, $count] = self::clickKind($kind);
        $this->getSession()->click($line, $column, $button, $count, self::modifiers($modifiers));
    }

    #[When('/^I scroll (up|down|left|right)(?: (\d+) times?)? (?:in|over) "([^"]+)"(?: with "([^"]+)")?$/')]
    public function scroll(string $direction, int|string $times, string $selector, string $modifiers = ''): void
    {
        $this->getSession()->scrollElement($selector, $direction, '' === $times ? 1 : (int) $times, self::modifiers($modifiers));
    }

    #[When('/^I scroll (up|down|left|right)(?: (\d+) times?)? at line (\d+), column (\d+)(?: with "([^"]+)")?$/')]
    public function scrollAt(string $direction, int|string $times, int $line, int $column, string $modifiers = ''): void
    {
        $this->getSession()->scroll($line, $column, $direction, '' === $times ? 1 : (int) $times, self::modifiers($modifiers));
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

    #[When('/^I hover (?:over )?"([^"]+)"$/')]
    public function hover(string $selector): void
    {
        $this->getSession()->hoverElement($selector);
    }

    #[When('/^I hover over the text "([^"]+)"$/')]
    public function hoverText(string $text): void
    {
        $this->getSession()->hoverText($text);
    }

    #[When('/^I hover at line (\d+), column (\d+)$/')]
    public function hoverAt(int $line, int $column): void
    {
        $this->getSession()->hover($line, $column);
    }

    #[When('/^I fill in "([^"]+)" with "([^"]*)"$/')]
    public function fillIn(string $selector, string $text): void
    {
        $this->getSession()->fillIn($selector, $text);
    }

    #[When('I fill in the following:')]
    public function fillInTable(TableNode $fields): void
    {
        foreach ($fields->getRowsHash() as $selector => $text) {
            if (!\is_string($text)) {
                throw new \InvalidArgumentException(\sprintf('Each row needs two cells, a selector and a text; the row of "%s" has more.', $selector));
            }
            $this->getSession()->fillIn($selector, $text);
        }
    }

    #[When('/^I select "([^"]+)" from "([^"]+)"$/')]
    public function selectOption(string $label, string $selector): void
    {
        $this->getSession()->selectOption($selector, $label);
    }

    #[When('I restart the app')]
    public function restartApp(): void
    {
        $this->getSession()->restart();
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
        $deadline = \microtime(true) + $seconds;
        while (\microtime(true) < $deadline && $session->getDriver()->isRunning()) {
            $session->getDriver()->pump(\min(0.01, $deadline - \microtime(true)));
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

    #[Then('I should not see text matching :pattern')]
    public function assertNotSeeMatch(string $pattern): void
    {
        $this->getSession()->assertNotSeeMatch($pattern);
    }

    #[Then('/^I should see "([^"]*)" (\d+) times?$/')]
    public function assertSeeCount(string $text, int $count): void
    {
        $this->getSession()->assertSeeCount($text, $count);
    }

    #[Then('/^line (\d+) should not contain "([^"]*)"$/')]
    public function assertLineNotContains(int $number, string $text): void
    {
        $this->getSession()->assertLineNotContains($number, $text);
    }

    #[Then('/^the "([^"]+)" field should (not )?contain "([^"]+)"$/')]
    public function assertFieldContains(string $selector, string $not, string $text): void
    {
        '' === $not ? $this->getSession()->assertFieldContains($selector, $text) : $this->getSession()->assertFieldNotContains($selector, $text);
    }

    #[Then('/^the text "([^"]+)" should (not )?be (bold|dim|italic|underlined|blinking|reversed|hidden|struck through)$/')]
    public function assertTextStyle(string $text, string $not, string $attribute): void
    {
        '' === $not ? $this->getSession()->assertTextStyle($text, $attribute) : $this->getSession()->assertTextWithoutStyle($text, $attribute);
    }

    #[Then('/^the text "([^"]+)" should be (?:colored|coloured) "([^"]+)"$/')]
    public function assertTextColor(string $text, string $color): void
    {
        $this->getSession()->assertTextColor($text, $color);
    }

    #[Then('/^the text "([^"]+)" should have (a|no) colou?r$/')]
    public function assertTextHasColor(string $text, string $which): void
    {
        'a' === $which ? $this->getSession()->text()->assertColored($text) : $this->getSession()->text()->assertNotColored($text);
    }

    #[Then('/^the line showing "([^"]+)" should (not )?contain "([^"]+)"$/')]
    public function assertLineShowingContains(string $anchor, string $not, string $text): void
    {
        '' === $not ? $this->getSession()->text()->assertLineShowingContains($anchor, $text) : $this->getSession()->text()->assertLineShowingNotContains($anchor, $text);
    }

    #[Then('/^the text "([^"]+)" should have the background "([^"]+)"$/')]
    public function assertTextBackground(string $text, string $color): void
    {
        $this->getSession()->assertTextBackground($text, $color);
    }

    #[Then('/^the terminal (?:bell )?should have rung(?: the bell)?(?: (\d+) times?)?$/')]
    public function assertBell(int|string $times = ''): void
    {
        $this->getSession()->assertBell('' === $times ? null : (int) $times);
    }

    #[Then('the terminal title should be :title')]
    public function assertTitle(string $title): void
    {
        $this->getSession()->assertTitle($title);
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

    #[Then('/^the (?:widget|element) "([^"]+)" should (not )?be focused$/')]
    public function assertFocused(string $selector, string $not = ''): void
    {
        $session = $this->getSession();
        $focused = '' === $not;
        if (!$focused) {
            $session->settle();
        }
        $session->waitUntil(
            static fn (): bool => ([] !== \array_filter($session->find($selector), static fn (Element $e): bool => $e->focused)) === $focused,
            static fn (): string => \sprintf(
                $focused ? 'No element matching "%s" has the focus; %s has it.' : 'An element matching "%s" has the focus: %s.',
                $selector,
                self::describeFocus($session),
            ),
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

    #[Then('/^the element "([^"]+)" should (not )?contain "([^"]+)"$/')]
    public function assertElementContains(string $selector, string $not, string $text): void
    {
        '' === $not ? $this->getSession()->assertSeeIn($selector, $text) : $this->getSession()->assertNotSeeIn($selector, $text);
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
        return '' === $modifiers ? [] : \array_map(trim(...), \explode('+', $modifiers));
    }

    private static function describeFocus(Session $session): string
    {
        $focused = \array_map(static fn (Element $e): string => $e->describe(), $session->find(':focus'));

        return [] === $focused ? 'nothing' : \implode(', ', $focused);
    }
}
