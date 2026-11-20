<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Check;

use Janalis\Tuink\Box;
use Janalis\Tuink\Mouse;
use Janalis\Tuink\Session;
use Janalis\Tuink\Util\Text;

/**
 * Reads, checks and clicks inside a frame found by the title on its top border, like a dialog or a panel.
 *
 * A check on the whole screen passes when the text shows anywhere, a dialog
 * listing nothing included when the rest of the screen has the text; these
 * look inside the frame only.
 */
final readonly class BoxChecks
{
    public function __construct(
        private Session $session,
        private string $title,
    ) {
    }

    /**
     * The text inside the frame, or null while no frame carries the title.
     */
    public function getText(): ?string
    {
        $grid = $this->session->getGrid();

        return Box::find($grid, $this->title)?->text($grid);
    }

    public function assertSee(string $text): void
    {
        $this->session->waitUntil(
            fn (): bool => null !== ($inside = $this->getText()) && \str_contains($inside, $text),
            fn (): string => $this->describe(\sprintf('does not show "%s"', $text)),
        );
    }

    public function assertNotSee(string $text): void
    {
        $this->session->waitUntil(
            fn (): bool => null !== ($inside = $this->getText()) && !\str_contains($inside, $text),
            fn (): string => $this->describe(\sprintf('shows "%s"', $text)),
        );
    }

    /**
     * Compares the whole inside of the frame, ignoring trailing spaces and blank lines.
     */
    public function assertShows(string $expected): void
    {
        $expected = Text::normalize($expected);
        $this->session->waitUntil(
            fn (): bool => null !== ($inside = $this->getText()) && Text::normalize($inside) === $expected,
            fn (): string => $this->describe("is not:\n".$expected),
        );
    }

    /**
     * Waits for the frame to show.
     */
    public function assertVisible(): void
    {
        $this->session->waitUntil(
            fn (): bool => null !== $this->getText(),
            \sprintf('No frame titled "%s" is on the screen.', $this->title),
        );
    }

    public function assertNotVisible(): void
    {
        $this->session->waitUntil(
            fn (): bool => null === $this->getText(),
            \sprintf('A frame titled "%s" is on the screen.', $this->title),
        );
    }

    /**
     * Clicks the first character of the first place the frame shows the text.
     *
     * @param list<string> $modifiers
     */
    public function clickText(string $text, string $button = Mouse::LEFT, int $count = 1, array $modifiers = []): void
    {
        $at = null;
        $this->session->waitUntil(
            function () use ($text, &$at): bool {
                $grid = $this->session->getGrid();
                $at = Box::find($grid, $this->title)?->locate($grid, $text);

                return null !== $at;
            },
            fn (): string => $this->describe(\sprintf('does not show "%s"', $text)),
        );
        \assert(null !== $at, description: 'waitUntil() returns once the text is found.');
        $this->session->click($at[0] + 1, $at[1] + 1, $button, $count, $modifiers);
    }

    private function describe(string $problem): string
    {
        $inside = $this->getText();

        return null === $inside
            ? \sprintf('No frame titled "%s" is on the screen.', $this->title)
            : \sprintf("The frame titled \"%s\" %s; it shows:\n%s", $this->title, $problem, Text::normalize($inside));
    }
}
