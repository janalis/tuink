<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Check;

use Janalis\Tuink\Session;

/**
 * Checks on the files of a session's workspace, waiting for the application to write them.
 */
final readonly class FileChecks
{
    public function __construct(
        private Session $session,
    ) {
    }

    public function assertExists(string $path): void
    {
        $workspace = $this->session->getWorkspace();
        $this->session->waitUntil(
            static fn (): bool => $workspace->exists($path),
            \sprintf('The file "%s" does not exist.', $path),
        );
    }

    public function assertNotExists(string $path): void
    {
        $workspace = $this->session->getWorkspace();
        $this->session->waitUntil(
            static fn (): bool => !$workspace->exists($path),
            \sprintf('The file "%s" exists.', $path),
        );
    }

    public function assertContains(string $path, string $text): void
    {
        $workspace = $this->session->getWorkspace();
        $this->session->waitUntil(
            static fn (): bool => null !== ($content = $workspace->read($path)) && \str_contains($content, $text),
            fn (): string => $this->describe($path, \sprintf('does not contain "%s"', $text)),
        );
    }

    public function assertNotContains(string $path, string $text): void
    {
        $workspace = $this->session->getWorkspace();
        $this->session->waitUntil(
            static fn (): bool => !\str_contains($workspace->read($path) ?? '', $text),
            fn (): string => $this->describe($path, \sprintf('contains "%s"', $text)),
        );
    }

    /**
     * Compares a whole file, ignoring trailing line breaks on both sides.
     */
    public function assertEquals(string $path, string $expected): void
    {
        $workspace = $this->session->getWorkspace();
        $expected = \rtrim($expected, characters: "\n");
        $this->session->waitUntil(
            static fn (): bool => null !== ($content = $workspace->read($path)) && \rtrim($content, characters: "\n") === $expected,
            fn (): string => $this->describe($path, problem: 'is not as expected'),
        );
    }

    private function describe(string $path, string $problem): string
    {
        $content = $this->session->getWorkspace()->read($path);

        return null === $content
            ? \sprintf('The file "%s" does not exist.', $path)
            : \sprintf("The file \"%s\" %s; it contains:\n%s", $path, $problem, '' === $content ? '    (nothing)' : $content);
    }
}
