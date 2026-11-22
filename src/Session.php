<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

use Janalis\Tuink\Check\BoxChecks;
use Janalis\Tuink\Check\FileChecks;
use Janalis\Tuink\Check\TerminalChecks;
use Janalis\Tuink\Driver\DriverInterface;
use Janalis\Tuink\Driver\TerminalTakeover;
use Janalis\Tuink\Driver\WidgetTreeProviderInterface;
use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\ExpectationException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Selector\Element;
use Janalis\Tuink\Selector\Selection;
use Janalis\Tuink\Selector\WidgetQuery;
use Janalis\Tuink\Util\Text;
use Janalis\Tuink\Util\Warnings;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\Tui\Render\WidgetRect;

/**
 * Drives one application run and asserts on what it shows.
 *
 * Every action settles afterwards: it lets the application work until its
 * output has been quiet for a moment. Assertions wait too, retrying until
 * the timeout, so a frame that comes late does not fail a step.
 */
final class Session
{
    private Launch $launch;
    private ?Workspace $workspace = null;

    public function __construct(
        private readonly DriverInterface $driver,
        private int $columns = 80,
        private int $rows = 24,
        private readonly float $timeout = 2.0,
        private readonly float $quietPeriod = 0.05,
        private readonly ?string $snapshotDir = null,
        private readonly float $startTimeout = 10.0,
    ) {
        $this->launch = new Launch();
    }

    /**
     * The arguments, environment and terminal features the next start uses.
     */
    public function getLaunch(): Launch
    {
        return $this->launch;
    }

    /**
     * Sets what the next start uses; "%workspace%" in arguments and variables is the workspace's path.
     */
    public function setLaunch(Launch $launch): void
    {
        $this->launch = $launch;
    }

    /**
     * The scenario's directory of files, created on first use; it outlives restarts and goes away with close().
     */
    public function getWorkspace(): Workspace
    {
        return $this->workspace ??= Workspace::create();
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    /**
     * Seconds a check waits for the screen to show what it expects.
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * Seconds a start waits for the application to put the terminal in raw mode.
     */
    public function getStartTimeout(): float
    {
        return $this->startTimeout;
    }

    /**
     * Seconds without output after which the application counts as settled.
     */
    public function getQuietPeriod(): float
    {
        return $this->quietPeriod;
    }

    public function start(?int $columns = null, ?int $rows = null): void
    {
        $this->columns = $columns ?? $this->columns;
        $this->rows = $rows ?? $this->rows;
        $this->driver->start($this->columns, $this->rows, $this->launch->withWorkspace($this->getWorkspace()->path));
        // Starting PHP on a loaded machine takes seconds, longer than a step may wait
        TerminalTakeover::await($this->driver, $this->startTimeout);
        // The first output may have been the whole frame: quiet counts from now then
        $this->settleFrom('' === $this->getScreenText() ? null : \microtime(true));
    }

    public function stop(): void
    {
        if ($this->driver->isStarted()) {
            $this->driver->stop();
        }
    }

    /**
     * Stops the application and removes the workspace, at the end of a scenario.
     *
     * The workspace outlives stop(), so a scenario can check what the application saved; a session dropped without
     * close() removes it when it is destroyed.
     */
    public function close(): void
    {
        $this->stop();
        $this->workspace?->destroy();
        $this->workspace = null;
    }

    public function __destruct()
    {
        $this->workspace?->destroy();
    }

    /**
     * Checks on the files of the workspace.
     */
    public function files(): FileChecks
    {
        return new FileChecks($this);
    }

    /**
     * Checks on what the application told the terminal: clipboard, modes, cursor.
     */
    public function terminal(): TerminalChecks
    {
        return new TerminalChecks($this);
    }

    /**
     * Reads, checks and clicks inside the frame whose top border carries the title.
     */
    public function box(string $title): BoxChecks
    {
        return new BoxChecks($this, $title);
    }

    public function isStarted(): bool
    {
        return $this->driver->isStarted();
    }

    /**
     * Types text literally, as characters.
     */
    public function type(string $text): void
    {
        $this->ensureRunning();
        $this->driver->send($text);
        $this->settle();
    }

    /**
     * Presses a key, like "Enter", "Ctrl+S" or "Shift+Tab", one or more times.
     */
    public function press(string $key, int $times = 1): void
    {
        $bytes = Keys::key($key);
        for ($i = 0; $i < $times; ++$i) {
            $this->ensureRunning();
            $this->driver->send($bytes);
        }
        $this->settle();
    }

    /**
     * Sends a text mixing characters and key names, like "abc<Enter>".
     */
    public function send(string $text): void
    {
        $this->ensureRunning();
        $this->driver->send(Keys::sequence($text));
        $this->settle();
    }

    /**
     * Pastes text as a terminal does with bracketed paste.
     */
    public function paste(string $text): void
    {
        $this->ensureRunning();
        $this->driver->send("\x1b[200~".$text."\x1b[201~");
        $this->settle();
    }

    /**
     * Clicks at a cell, numbered from line 1, column 1.
     *
     * @param list<string> $modifiers Shift, Alt, Ctrl
     */
    public function click(int $line, int $column, string $button = Mouse::LEFT, int $count = 1, array $modifiers = []): void
    {
        $this->ensureRunning();
        $this->checkOnScreen($line, $column);
        $this->driver->send(Mouse::click($line, $column, $button, $count, $modifiers));
        $this->settle();
    }

    /**
     * Clicks the middle of the one widget matching the selector.
     *
     * @param list<string> $modifiers
     */
    public function clickElement(string $selector, string $button = Mouse::LEFT, int $count = 1, array $modifiers = []): void
    {
        [$line, $column] = $this->elementCenter($selector);
        $this->click($line, $column, $button, $count, $modifiers);
    }

    /**
     * Clicks the first character of the first place the screen shows the text, reading left to right, top to bottom.
     *
     * @param list<string> $modifiers
     */
    public function clickText(string $text, string $button = Mouse::LEFT, int $count = 1, array $modifiers = []): void
    {
        [$line, $column] = $this->locateOrFail($text);
        $this->click($line, $column, $button, $count, $modifiers);
    }

    /**
     * Turns the wheel over a cell.
     *
     * @param list<string> $modifiers Shift, Alt, Ctrl
     */
    public function scroll(int $line, int $column, string $direction, int $notches = 1, array $modifiers = []): void
    {
        $this->ensureRunning();
        $this->checkOnScreen($line, $column);
        $this->driver->send(Mouse::scroll($line, $column, $direction, $notches, $modifiers));
        $this->settle();
    }

    /**
     * Turns the wheel over the middle of the one widget matching the selector.
     *
     * @param list<string> $modifiers
     */
    public function scrollElement(string $selector, string $direction, int $notches = 1, array $modifiers = []): void
    {
        [$line, $column] = $this->elementCenter($selector);
        $this->scroll($line, $column, $direction, $notches, $modifiers);
    }

    /**
     * Drags from one cell to another along a straight line, one cell at a time.
     */
    public function drag(int $fromLine, int $fromColumn, int $toLine, int $toColumn, string $button = Mouse::LEFT): void
    {
        $this->ensureRunning();
        $this->checkOnScreen($fromLine, $fromColumn);
        $this->checkOnScreen($toLine, $toColumn);
        // One cell per step along the longer axis; a drag that does not move still presses, moves and releases in place
        $steps = \max(\abs($toLine - $fromLine), \abs($toColumn - $fromColumn), 1);
        $path = [];
        for ($i = 0; $i <= $steps; ++$i) {
            $path[] = [$fromLine + (int) \round((($toLine - $fromLine) * $i) / $steps), $fromColumn + (int) \round((($toColumn - $fromColumn) * $i) / $steps)];
        }
        $this->driver->send(Mouse::drag($path, $button));
        $this->settle();
    }

    /**
     * Drags from the middle of one widget to the middle of another.
     */
    public function dragElement(string $from, string $to, string $button = Mouse::LEFT): void
    {
        [$fromLine, $fromColumn] = $this->elementCenter($from);
        [$toLine, $toColumn] = $this->elementCenter($to);
        $this->drag($fromLine, $fromColumn, $toLine, $toColumn, $button);
    }

    /**
     * Moves the pointer over a cell without a button, as hovering does.
     */
    public function hover(int $line, int $column): void
    {
        $this->ensureRunning();
        $this->checkOnScreen($line, $column);
        $this->driver->send(Mouse::move($line, $column));
        $this->settle();
    }

    public function hoverElement(string $selector): void
    {
        [$line, $column] = $this->elementCenter($selector);
        $this->hover($line, $column);
    }

    public function hoverText(string $text): void
    {
        [$line, $column] = $this->locateOrFail($text);
        $this->hover($line, $column);
    }

    /**
     * Focuses the field, clears its line (End, then Ctrl+U) and types the text.
     */
    public function fillIn(string $selector, string $text): void
    {
        $this->focus($selector);
        $this->driver->send(Keys::key('End').Keys::key('Ctrl+U'));
        $this->driver->pump(0.0);
        $this->type($text);
    }

    /**
     * Moves the selection of a list to the item with that label, with the arrow keys, and confirms it with Enter.
     *
     * Items are told apart by their label, value and position. Without positions (a getSelectedIndex(), which released
     * versions of SelectListWidget do not have), two items with the same label and value look like one: the walk can
     * take one for the other, end early and report items it did not reach as missing. So can an application whose
     * TuiProbe predates values, with items that share a label.
     */
    public function selectOption(string $selector, string $label): void
    {
        $this->focus($selector);
        $selected = fn (): Selection => $this->findOne($selector)->selection ?? throw new \LogicException(\sprintf('The element "%s" has no selected item; it is not a list.', $selector));
        // Two items can share a label: the selection tells them apart by their value, and their position if the list
        // tells it. A probe from before values were shared sends the label alone, which is all there is to go by then.
        $in = static fn (Selection $item, array $items): bool => \array_any($items, $item->is(...));

        // Walk down, then up; a direction ends when the selection comes back to an item it passed: it stopped at the end of
        // the list, or wrapped around. Back where the walk down started, it saw every item; otherwise the walk up passes
        // again over what the walk down saw, to reach the items above.
        $current = $selected();
        $seen = [$current];
        foreach (['Down', 'Up'] as $key) {
            $walked = [$current];
            while ($current->label !== $label) {
                $this->press($key);
                $current = $selected();
                if ($in($current, $walked)) {
                    if (\count($walked) > 1 && $walked[0]->is($current)) {
                        break 2;
                    }

                    continue 2;
                }
                $walked[] = $current;
                if (!$in($current, $seen)) {
                    $seen[] = $current;
                }
            }
            $this->press('Enter');

            return;
        }

        throw new ExpectationException(
            \sprintf('The list "%s" has no item "%s"; its items are "%s".', $selector, $label, \implode('", "', \array_map(static fn (Selection $item): string => $item->label, $seen))),
            $this->dump(),
        );
    }

    /**
     * Stops the application and starts it again in a terminal of the same size.
     */
    public function restart(): void
    {
        $this->stop();
        $this->start($this->columns, $this->rows);
    }

    public function resize(int $columns, int $rows): void
    {
        $this->ensureRunning();
        $this->columns = $columns;
        $this->rows = $rows;
        $this->driver->resize($columns, $rows);
        $this->settleFrom(null);
    }

    /**
     * Lets the application run until its output has been quiet for a moment, or the timeout passed.
     */
    public function settle(): void
    {
        $this->settleFrom(\microtime(true));
    }

    /**
     * The screen as cells, by 0-based row and column: one string per cell, '' where a wide character's second half sits.
     *
     * @return list<list<string>>
     */
    public function getGrid(): array
    {
        $cells = $this->driver->getScreen()->getCells();
        $grid = [];
        for ($row = 0; $row < $this->rows; ++$row) {
            $line = [];
            for ($col = 0; $col < $this->columns; ++$col) {
                $line[] = $cells[$row][$col]['char'] ?? ' ';
            }
            $grid[] = $line;
        }

        return $grid;
    }

    /**
     * Checks where a text shows relative to another: "above", "below", "before" or "after" in reading order.
     */
    public function assertOrder(string $text, string $relation, string $other): void
    {
        if (!\in_array($relation, ['above', 'below', 'before', 'after'], strict: true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown relation "%s"; use above, below, before, after.', $relation));
        }
        $where = fn (string $needle): string => null === ($at = $this->locate($needle)) ? 'nowhere' : \sprintf('at line %d, column %d', ...$at);
        $this->waitUntil(
            function () use ($text, $relation, $other): bool {
                $at = $this->locate($text);
                $otherAt = $this->locate($other);
                if (null === $at || null === $otherAt) {
                    return false;
                }
                // Reading order: by line, then by column
                $order = $at <=> $otherAt;

                return match ($relation) {
                    'above' => $at[0] < $otherAt[0],
                    'below' => $at[0] > $otherAt[0],
                    'before' => -1 === $order,
                    default => 1 === $order,
                };
            },
            static fn (): string => \sprintf('The text "%s" is not %s "%s": it is %s, and "%s" %s.', $text, $relation, $other, $where($text), $other, $where($other)),
        );
    }

    /**
     * The screen as plain text, without trailing spaces or trailing blank lines.
     */
    public function getScreenText(): string
    {
        return $this->driver->getScreen()->getScreen();
    }

    /**
     * @return array<string> Every row of the screen, by row from 0, without trailing spaces
     */
    public function getLines(): array
    {
        return \array_map(rtrim(...), $this->driver->getScreen()->getLines());
    }

    /**
     * One row, numbered from 1.
     */
    public function getLine(int $number): string
    {
        $lines = $this->getLines();
        if ($number < 1 || $number > \count($lines)) {
            throw new \OutOfRangeException(\sprintf('The screen has %d lines, there is no line %d.', \count($lines), $number));
        }

        return $lines[$number - 1];
    }

    /**
     * Waits until $condition holds, or fails with $message and the screen.
     *
     * @param callable(self): bool      $condition
     * @param string|\Closure(): string $message   Built at failure time when a closure, to describe the final state
     */
    public function waitUntil(callable $condition, string|\Closure $message, ?float $timeout = null): void
    {
        $deadline = \microtime(true) + ($timeout ?? $this->timeout);

        while (true) {
            if ($condition($this)) {
                return;
            }
            if (\microtime(true) >= $deadline) {
                throw new ExpectationException(\is_string($message) ? $message : $message(), $this->dump());
            }
            if ($this->driver->isStarted()) {
                $this->driver->pump(0.01);
            }
            if (!$this->driver->isRunning()) {
                // pump() returns at once when nothing runs, and the screen will not change: do not spin until the deadline
                \usleep(10_000);
            }
        }
    }

    public function assertSee(string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => \str_contains($s->getScreenText(), $text),
            \sprintf('The text "%s" was not found on the screen.', $text),
        );
    }

    public function assertNotSee(string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => !\str_contains($s->getScreenText(), $text),
            \sprintf('The text "%s" was found on the screen.', $text),
        );
    }

    public function assertSeeMatch(string $pattern): void
    {
        $this->waitUntil(
            static fn (self $s): bool => 1 === \preg_match($pattern, $s->getScreenText()),
            \sprintf('No text on the screen matches %s.', $pattern),
        );
    }

    public function assertNotSeeMatch(string $pattern): void
    {
        $this->waitUntil(
            static fn (self $s): bool => 1 !== \preg_match($pattern, $s->getScreenText()),
            \sprintf('Text on the screen matches %s.', $pattern),
        );
    }

    /**
     * Checks how many times the screen shows the text, counting each line separately.
     */
    public function assertSeeCount(string $text, int $count): void
    {
        $occurrences = static fn (self $s): int => \array_sum(\array_map(static fn (string $line): int => \substr_count($line, $text), $s->getLines()));
        $this->waitUntil(
            static fn (self $s): bool => $occurrences($s) === $count,
            fn (): string => \sprintf('The text "%s" is on the screen %d times, not %d.', $text, $occurrences($this), $count),
        );
    }

    public function assertLineNotContains(int $number, string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => !\str_contains($s->lineOrEmpty($number), $text),
            \sprintf('Line %d contains "%s".', $number, $text),
        );
    }

    /**
     * Checks the value of the one field matching the selector contains the text, or does not.
     */
    public function assertFieldContains(string $selector, string $text): void
    {
        $this->waitUntil(
            fn (): bool => \str_contains($this->fieldValue($selector), $text),
            fn (): string => \sprintf('The field "%s" has the value "%s", which does not contain "%s".', $selector, $this->fieldValue($selector), $text),
        );
    }

    public function assertFieldNotContains(string $selector, string $text): void
    {
        $this->waitUntil(
            fn (): bool => !\str_contains($this->fieldValue($selector), $text),
            fn (): string => \sprintf('The field "%s" has the value "%s", which contains "%s".', $selector, $this->fieldValue($selector), $text),
        );
    }

    /**
     * The style of each cell where the screen first shows the text.
     *
     * @return list<Style>
     */
    public function getTextStyles(string $text): array
    {
        [$line, $column] = $this->locateOrFail($text);
        $cells = $this->driver->getScreen()->getCells()[$line - 1] ?? [];
        $width = new UnicodeString($text)->width();
        $styles = [];
        for ($col = $column - 1; $col < ($column - 1 + $width); ++$col) {
            // Skip the second cell of a wide character, which only repeats the first
            if ('' === ($cells[$col]['char'] ?? ' ')) {
                continue;
            }

            $styles[] = Style::fromSgr($cells[$col]['style'] ?? '');
        }

        return $styles;
    }

    /**
     * Checks that every cell of the text has the attribute (bold, underlined…), or that none has it.
     */
    public function assertTextStyle(string $text, string $attribute): void
    {
        self::checkAttribute($attribute);
        $this->waitUntil(
            fn (): bool => [] === \array_filter($this->getTextStyles($text), static fn (Style $s): bool => !$s->has($attribute)),
            fn (): string => \sprintf('The text "%s" is not %s: %s.', $text, $attribute, $this->describeStyles($text)),
        );
    }

    /**
     * Checks that no cell of the text has the attribute.
     */
    public function assertTextWithoutStyle(string $text, string $attribute): void
    {
        self::checkAttribute($attribute);
        $this->waitUntil(
            fn (): bool => [] === \array_filter($this->getTextStyles($text), static fn (Style $s): bool => $s->has($attribute)),
            fn (): string => \sprintf('The text "%s" is %s: %s.', $text, $attribute, $this->describeStyles($text)),
        );
    }

    /**
     * Checks the foreground, or the background, color of every cell of the text.
     */
    public function assertTextColor(string $text, string $color): void
    {
        $color = Style::name($color);
        $this->waitUntil(
            fn (): bool => [] === \array_filter($this->getTextStyles($text), static fn (Style $s): bool => $s->foreground !== $color),
            fn (): string => \sprintf('The text "%s" does not have the foreground color %s: %s.', $text, $color, $this->describeStyles($text)),
        );
    }

    /**
     * Checks the background color of every cell of the text.
     */
    public function assertTextBackground(string $text, string $color): void
    {
        $color = Style::name($color);
        $this->waitUntil(
            fn (): bool => [] === \array_filter($this->getTextStyles($text), static fn (Style $s): bool => $s->background !== $color),
            fn (): string => \sprintf('The text "%s" does not have the background color %s: %s.', $text, $color, $this->describeStyles($text)),
        );
    }

    /**
     * Checks how many times the terminal bell rang since the application started, or that it rang at all.
     */
    public function assertBell(?int $count = null): void
    {
        $observer = $this->driver->getObserver();
        $this->waitUntil(
            static fn (): bool => null === $count ? $observer->getBellCount() > 0 : $observer->getBellCount() === $count,
            static fn (): string => \sprintf('The bell rang %d times%s.', $observer->getBellCount(), null === $count ? '' : \sprintf(', not %d', $count)),
        );
    }

    public function assertTitle(string $title): void
    {
        $observer = $this->driver->getObserver();
        $this->waitUntil(
            static fn (): bool => $observer->getTitle() === $title,
            static fn (): string => null === $observer->getTitle() ? \sprintf('No title was set; "%s" was expected.', $title) : \sprintf('The title is "%s", not "%s".', $observer->getTitle(), $title),
        );
    }

    public function assertLineContains(int $number, string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => \str_contains($s->lineOrEmpty($number), $text),
            \sprintf('Line %d does not contain "%s".', $number, $text),
        );
    }

    public function assertLineEquals(int $number, string $text): void
    {
        $this->waitUntil(
            static fn (self $s): bool => $s->lineOrEmpty($number) === \rtrim($text),
            \sprintf('Line %d is not "%s".', $number, $text),
        );
    }

    /**
     * Compares the whole screen, ignoring trailing spaces and trailing blank lines.
     */
    public function assertScreenEquals(string $expected): void
    {
        $expected = Text::normalize($expected);
        $this->waitUntil(
            static fn (self $s): bool => Text::normalize($s->getScreenText()) === $expected,
            "The screen is not:\n".self::number($expected),
        );
    }

    /**
     * Compares a rectangle of the screen whose top-left cell is at $line, $column (both from 1).
     *
     * The rectangle is as tall as $expected has lines and as wide as its widest
     * line; trailing spaces are ignored on both sides.
     */
    public function assertRegionEquals(int $line, int $column, string $expected): void
    {
        if ($line < 1 || $column < 1) {
            throw new \InvalidArgumentException(\sprintf('A region starts at line 1, column 1 or further, not at line %d, column %d.', $line, $column));
        }

        $expected = Text::normalize($expected);
        $rows = \explode("\n", $expected);
        $width = \max(\array_map(static fn (string $row): int => new UnicodeString($row)->width(), $rows));

        $this->waitUntil(
            fn (): bool => $this->getRegion($line, $column, $width, \count($rows)) === $expected,
            fn (): string => \sprintf(
                "The region at line %d, column %d is not:\n%s\n\nIt is:\n%s",
                $line,
                $column,
                self::number($expected),
                self::number($this->getRegion($line, $column, $width, \count($rows))),
            ),
        );
    }

    /**
     * The text of a rectangle of the screen, by display columns, trailing spaces trimmed.
     */
    public function getRegion(int $line, int $column, int $width, int $height): string
    {
        $grid = $this->getGrid();
        $rows = [];
        for ($row = $line - 1; $row < ($line - 1 + $height); ++$row) {
            $text = '';
            for ($col = $column - 1; $col < ($column - 1 + $width); ++$col) {
                $text .= $grid[$row][$col] ?? ' ';
            }
            $rows[] = $text;
        }

        return Text::normalize(\implode("\n", $rows));
    }

    /**
     * The widgets matching a CSS selector, in tree order; see WidgetTree for what selectors see.
     *
     * The in_process driver reads the widgets directly; the process driver asks
     * the application, whose entry point must install TuiProbe.
     *
     * @return list<Element>
     */
    public function find(string $selector): array
    {
        $driver = $this->driver;
        if (!$driver instanceof WidgetTreeProviderInterface) {
            throw new UnsupportedDriverException(\sprintf('The "%s" driver cannot show widgets, so CSS selectors do not work on it.', $driver->getName()));
        }

        $query = new WidgetQuery(fn (WidgetRect $rect): string => $this->getRegion($rect->row + 1, $rect->col + 1, $rect->columns, $rect->rows));

        return $query->select($driver->getWidgetTree(), $selector);
    }

    /**
     * Gives the focus to the one widget matching the selector.
     */
    public function focus(string $selector): void
    {
        $this->ensureRunning();
        $element = $this->findOne($selector);
        $driver = $this->driver;
        if (!$driver instanceof WidgetTreeProviderInterface) {
            throw new UnsupportedDriverException(\sprintf('The "%s" driver cannot focus widgets.', $driver->getName()));
        }
        $driver->focusWidget($element->index);
        $this->settle();
    }

    /**
     * The one widget a selector matches, waiting until there is exactly one.
     */
    public function findOne(string $selector): Element
    {
        $this->waitUntil(
            fn (): bool => 1 === \count($this->find($selector)),
            fn (): string => $this->describeMatches($selector, expectation: 'one was expected'),
        );
        $found = $this->find($selector);
        if (1 !== \count($found)) {
            throw new ExpectationException($this->describeMatches($selector, expectation: 'one was expected'), $this->dump());
        }

        return $found[0];
    }

    public function assertElementCount(string $selector, int $count): void
    {
        $this->waitUntil(
            fn (): bool => \count($this->find($selector)) === $count,
            fn (): string => $this->describeMatches($selector, \sprintf('not %d', $count)),
        );
    }

    /**
     * Checks that a widget matching the selector is drawn.
     */
    public function assertElementVisible(string $selector): void
    {
        $this->waitUntil(
            fn (): bool => [] !== $this->findVisible($selector),
            \sprintf('No element matching "%s" is on the screen.', $selector),
        );
    }

    /**
     * Checks that no widget matching the selector is drawn; matching widgets that are not drawn are fine.
     */
    public function assertElementNotVisible(string $selector): void
    {
        $this->waitUntil(
            fn (): bool => [] === $this->findVisible($selector),
            fn (): string => \sprintf('Elements matching "%s" are on the screen: %s.', $selector, self::describeAll($this->findVisible($selector))),
        );
    }

    /**
     * Checks that a widget matching the selector shows the text where it is drawn.
     */
    public function assertSeeIn(string $selector, string $text): void
    {
        $this->waitUntil(
            fn (): bool => [] !== $this->findShowing($selector, $text),
            fn (): string => [] === ($found = $this->find($selector))
                ? \sprintf('The text "%s" was not found in "%s": no element matches it.', $text, $selector)
                : \sprintf("The text \"%s\" was not found in \"%s\". It shows:\n%s", $text, $selector, self::showAll($found)),
        );
    }

    public function assertNotSeeIn(string $selector, string $text): void
    {
        $this->waitUntil(
            fn (): bool => [] === $this->findShowing($selector, $text),
            fn (): string => \sprintf("The text \"%s\" was found in \"%s\":\n%s", $text, $selector, self::showAll($this->findShowing($selector, $text))),
        );
    }

    /**
     * Compares what the one widget matching the selector shows, ignoring trailing spaces and blank lines.
     */
    public function assertElementShows(string $selector, string $expected): void
    {
        $expected = Text::normalize($expected);
        $shown = function () use ($selector): ?string {
            $found = $this->find($selector);

            return 1 === \count($found) ? Text::normalize($found[0]->text) : null;
        };
        $this->waitUntil(
            static fn (): bool => $shown() === $expected,
            fn (): string => null === ($text = $shown())
                ? $this->describeMatches($selector, expectation: 'one was expected')
                : \sprintf("The element \"%s\" does not show:\n%s\n\nIt shows:\n%s", $selector, self::number($expected), self::number($text)),
        );
    }

    /**
     * The screen as a snapshot stores it: no trailing spaces, no trailing blank lines.
     */
    public function snapshot(): string
    {
        return Text::normalize($this->getScreenText());
    }

    /**
     * Compares the screen with a stored snapshot, writing it when missing or when TUINK_UPDATE_SNAPSHOTS is set.
     *
     * A missing snapshot fails instead when the CI environment variable is set.
     */
    public function assertMatchesSnapshot(string $name): void
    {
        if (null === $this->snapshotDir) {
            throw new \LogicException('No snapshot directory is configured.');
        }
        if (!\preg_match('/^[\w.\- \/]+$/', $name) || \str_contains($name, '..')) {
            throw new \InvalidArgumentException(\sprintf('Invalid snapshot name "%s".', $name));
        }

        $path = $this->snapshotDir.'/'.$name.'.txt';
        $update = '' !== (string) \getenv('TUINK_UPDATE_SNAPSHOTS') && '0' !== \getenv('TUINK_UPDATE_SNAPSHOTS');

        if ($update || !\is_file($path)) {
            if (!$update && '' !== (string) \getenv('CI')) {
                throw new ExpectationException(\sprintf('The snapshot "%s" does not exist; record it locally and commit it.', $path), $this->dump());
            }
            $directory = \dirname($path);
            [$created, $warning] = Warnings::capture(static fn (): bool => \is_dir($directory) || \mkdir($directory, recursive: true));
            if (!$created) {
                throw new \RuntimeException(\sprintf('Cannot create the snapshot directory "%s": %s', $directory, $warning ?? 'unknown error'));
            }
            // The comparison below then reads back what was just written
            \file_put_contents($path, $this->snapshot()."\n");
        }

        $this->assertScreenEquals((string) \file_get_contents($path));
    }

    /**
     * Waits for the application to exit, and checks its exit code when one is given.
     */
    public function assertExited(?int $code = null): void
    {
        $this->waitUntil(
            fn (): bool => !$this->driver->isRunning(),
            message: 'The application is still running.',
        );

        if (null === $code) {
            return;
        }

        $actual = $this->driver->getExitCode() ?? throw new UnsupportedDriverException(\sprintf('The "%s" driver does not know exit codes.', $this->driver->getName()));
        if ($actual !== $code) {
            throw new ExpectationException(\sprintf('The application exited with code %d, not %d.', $actual, $code), $this->dump());
        }
    }

    /**
     * The screen with row numbers, for failure messages.
     */
    public function dump(): string
    {
        if (!$this->driver->isStarted() && !$this->hasScreen()) {
            return 'The application is not started.';
        }

        $state = $this->driver->isRunning() ? 'running' : 'exited';
        $text = $this->getScreenText();

        return \sprintf("Screen (%dx%d, %s driver, %s):\n%s", $this->columns, $this->rows, $this->driver->getName(), $state, '' === $text ? '    (blank)' : self::number($text));
    }

    /**
     * The 1-based [line, column] of the middle of the one visible widget matching the selector.
     *
     * @return array{int, int}
     */
    private function elementCenter(string $selector): array
    {
        $element = $this->findOne($selector);
        $rect = $element->rect ?? throw new ExpectationException(\sprintf('The element "%s" (%s) is not on the screen, so it cannot be clicked.', $selector, $element->describe()), $this->dump());

        return [$rect->row + \intdiv($rect->rows - 1, num2: 2) + 1, $rect->col + \intdiv($rect->columns - 1, num2: 2) + 1];
    }

    /**
     * The 1-based [line, column] where the screen first shows the text, by display cell.
     *
     * @return array{int, int}|null
     */
    private function locate(string $text): ?array
    {
        foreach ($this->getGrid() as $row => $cells) {
            $found = Box::occurrences($cells, $text);
            if ([] !== $found) {
                return [$row + 1, $found[0][0] + 1];
            }
        }

        return null;
    }

    /**
     * Pumps until the output has been quiet since $lastWrite; with null, quiet time only counts once the screen shows something,
     * since an application often writes escape sequences (modes, a title) before its first frame.
     */
    private function settleFrom(?float $lastWrite): void
    {
        $deadline = \microtime(true) + $this->timeout;

        while (\microtime(true) < $deadline) {
            $pumpedAt = \microtime(true);
            if ($this->driver->pump(0.005)) {
                if (null !== $lastWrite || '' !== $this->getScreenText()) {
                    $lastWrite = \microtime(true);
                }

                continue;
            }
            // Quiet counts from a pump that began the quiet period after the last write: one that began earlier may have
            // seen this process lose the CPU before the loop ran what was due by then, like the frame an action asked for
            if (!$this->driver->isRunning() || (null !== $lastWrite && ($pumpedAt - $lastWrite) >= $this->quietPeriod)) {
                return;
            }
        }
    }

    private function fieldValue(string $selector): string
    {
        $element = $this->findOne($selector);

        return $element->value ?? throw new \LogicException(\sprintf('The element "%s" (%s) has no value.', $selector, $element->widgetClass));
    }

    private static function checkAttribute(string $attribute): void
    {
        if (!\in_array($attribute, Style::ATTRIBUTES, strict: true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown text attribute "%s"; use %s.', $attribute, \implode(', ', Style::ATTRIBUTES)));
        }
    }

    /**
     * @return array{int, int}
     */
    private function locateOrFail(string $text): array
    {
        $position = null;
        $this->waitUntil(
            function () use ($text, &$position): bool {
                $position = $this->locate($text);

                return null !== $position;
            },
            \sprintf('The text "%s" was not found on the screen.', $text),
        );
        \assert(null !== $position, description: 'waitUntil() returns once the text is found.');

        return $position;
    }

    private function describeStyles(string $text): string
    {
        $styles = \array_map(static fn (Style $s): string => $s->describe(), $this->getTextStyles($text));

        return 1 === \count(\array_unique($styles)) ? $styles[0] : \implode(' | ', $styles);
    }

    private function checkOnScreen(int $line, int $column): void
    {
        if ($line < 1 || $line > $this->rows || $column < 1 || $column > $this->columns) {
            throw new \OutOfRangeException(\sprintf('Line %d, column %d is off the %dx%d screen.', $line, $column, $this->columns, $this->rows));
        }
    }

    /**
     * @return array<int, Element>
     */
    private function findVisible(string $selector): array
    {
        return \array_filter($this->find($selector), static fn (Element $e): bool => $e->isVisible());
    }

    /**
     * @return array<int, Element>
     */
    private function findShowing(string $selector, string $text): array
    {
        return \array_filter($this->find($selector), static fn (Element $e): bool => \str_contains($e->text, $text));
    }

    private function describeMatches(string $selector, string $expectation): string
    {
        $found = $this->find($selector);

        return match (\count($found)) {
            0 => \sprintf('No element matches "%s", %s.', $selector, $expectation),
            default => \sprintf('%d elements match "%s", %s: %s.', \count($found), $selector, $expectation, self::describeAll($found)),
        };
    }

    /**
     * @param array<int, Element> $elements
     */
    private static function describeAll(array $elements): string
    {
        return \implode(', ', \array_map(static fn (Element $e): string => $e->describe(), $elements));
    }

    /**
     * @param array<int, Element> $elements
     */
    private static function showAll(array $elements): string
    {
        return \implode("\n", \array_map(
            static fn (Element $e): string => $e->describe().($e->isVisible() ? ":\n".self::number(Text::normalize($e->text)) : ' (not drawn)'),
            $elements,
        ));
    }

    private function lineOrEmpty(int $number): string
    {
        return $this->getLines()[$number - 1] ?? '';
    }

    private function hasScreen(): bool
    {
        try {
            $this->driver->getScreen();

            return true;
        } catch (DriverException) {
            return false;
        }
    }

    private function ensureRunning(): void
    {
        if (!$this->driver->isStarted()) {
            throw new DriverException('The application is not started.');
        }
        if (!$this->driver->isRunning()) {
            throw new ExpectationException('The application is no longer running.', $this->dump());
        }
    }

    private static function number(string $text): string
    {
        $lines = \explode("\n", $text);
        $width = \strlen((string) \count($lines));
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = \sprintf('%'.$width.'d | %s', $i + 1, $line);
        }

        return \implode("\n", $out);
    }
}
