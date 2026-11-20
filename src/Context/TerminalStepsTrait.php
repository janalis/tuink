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
use Behat\Step\Then;
use Behat\Step\When;
use Janalis\Tuink\Session;

/**
 * Steps for framed boxes, the layout, and what the application told the terminal: clipboard, modes, cursor, focus.
 */
trait TerminalStepsTrait
{
    #[Then('/^I should see "([^"]*)" in the (?:box|frame|dialog|panel|window) "([^"]+)"$/')]
    public function assertSeeInBox(string $text, string $title): void
    {
        $this->getSession()->box($title)->assertSee($text);
    }

    #[Then('/^I should not see "([^"]*)" in the (?:box|frame|dialog|panel|window) "([^"]+)"$/')]
    public function assertNotSeeInBox(string $text, string $title): void
    {
        $this->getSession()->box($title)->assertNotSee($text);
    }

    #[Then('/^I should (not )?see the (?:box|frame|dialog|panel|window) "([^"]+)"$/')]
    public function assertBoxVisible(string $not, string $title): void
    {
        '' === $not ? $this->getSession()->box($title)->assertVisible() : $this->getSession()->box($title)->assertNotVisible();
    }

    #[Then('/^the (?:box|frame|dialog|panel|window) "([^"]+)" should show:$/')]
    public function assertBoxShows(string $title, PyStringNode $text): void
    {
        $this->getSession()->box($title)->assertShows($text->getRaw());
    }

    #[Then('/^I should see "([^"]+)" (above|below|before|after) "([^"]+)"$/')]
    public function assertOrder(string $text, string $relation, string $other): void
    {
        $this->getSession()->assertOrder($text, $relation, $other);
    }

    #[Then('/^the clipboard should (?:contain|be) "([^"]*)"$/')]
    public function assertClipboard(string $text): void
    {
        $this->getSession()->terminal()->assertClipboard($text);
    }

    #[Then('/^the clipboard should (?:contain|be):$/')]
    public function assertClipboardText(PyStringNode $text): void
    {
        $this->getSession()->terminal()->assertClipboard($text->getRaw());
    }

    #[Then('nothing should have been copied to the clipboard')]
    public function assertNothingCopied(): void
    {
        $this->getSession()->terminal()->assertClipboard(null);
    }

    #[Then('/^the "([^"]+)" mode should be (on|off)$/')]
    public function assertMode(string $mode, string $state): void
    {
        'on' === $state ? $this->getSession()->terminal()->assertModeOn($mode) : $this->getSession()->terminal()->assertModeOff($mode);
    }

    #[Then('/^the cursor should be (visible|hidden)$/')]
    public function assertCursorVisible(string $state): void
    {
        'visible' === $state ? $this->getSession()->terminal()->assertCursorVisible() : $this->getSession()->terminal()->assertCursorHidden();
    }

    #[Then('/^the cursor should be at line (\d+), column (\d+)$/')]
    public function assertCursorAt(int $line, int $column): void
    {
        $this->getSession()->terminal()->assertCursorAt($line, $column);
    }

    #[Then('/^the cursor should be an? ((?:blinking )?(?:block|underline|bar))$/')]
    public function assertCursorShape(string $shape): void
    {
        $this->getSession()->terminal()->assertCursorShape($shape);
    }

    #[When('/^the terminal (gains|loses) (?:the )?focus$/')]
    public function focusTerminal(string $change): void
    {
        $this->getSession()->press('gains' === $change ? 'FocusIn' : 'FocusOut');
    }

    abstract protected function getSession(): Session;
}
