<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

use Janalis\Tuink\Util\Warnings;

/**
 * A scenario's own directory, where steps write the files the application works on.
 *
 * Paths are relative to it and cannot leave it. The directory outlives restarts
 * of the application and is removed with the session.
 */
final class Workspace
{
    private function __construct(
        public readonly string $path,
    ) {
    }

    /**
     * Creates an empty directory under $parent, the system's temporary directory by default.
     */
    public static function create(?string $parent = null): self
    {
        $path = \sprintf('%s/tuink-workspace-%s', \rtrim($parent ?? \sys_get_temp_dir(), characters: '/'), \bin2hex(\random_bytes(6)));
        [$created, $warning] = Warnings::capture(static fn (): bool => \mkdir($path, permissions: 0o700, recursive: true));
        if (!$created) {
            throw new \RuntimeException(\sprintf('Cannot create the workspace "%s": %s', $path, $warning ?? 'unknown error'));
        }

        // The application sees the resolved path (/private/var/… on macOS); the steps must name the same one
        return new self((string) \realpath($path));
    }

    /**
     * The absolute path of a file or directory in the workspace.
     */
    public function resolve(string $relative): string
    {
        $parts = \explode('/', $relative);
        if (\str_starts_with($relative, '/') || \in_array('..', $parts, strict: true) || '' === \trim($relative, characters: '/.')) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a path inside the workspace; use a relative path without "..".', $relative));
        }

        return $this->path.'/'.\ltrim($relative, characters: '/');
    }

    /**
     * Writes a file, creating the directories it is in.
     */
    public function write(string $relative, string $content): void
    {
        $file = $this->resolve($relative);
        $this->makeDirectory(\dirname($file));
        [$written, $warning] = Warnings::capture(static fn (): int|false => \file_put_contents($file, $content));
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Cannot write "%s": %s', $relative, $warning ?? 'unknown error'));
        }
    }

    /**
     * Makes a file executable by its owner, its group and others (0755), as a script on the PATH is.
     */
    public function makeExecutable(string $relative): void
    {
        $file = $this->resolve($relative);
        [$changed, $warning] = Warnings::capture(static fn (): bool => \chmod($file, 0o755));
        if (!$changed) {
            throw new \RuntimeException(\sprintf('Cannot make "%s" executable: %s', $relative, $warning ?? 'unknown error'));
        }
    }

    public function createDirectory(string $relative): void
    {
        $this->makeDirectory($this->resolve($relative));
    }

    /**
     * The content of a file, or null when it is not a readable file.
     */
    public function read(string $relative): ?string
    {
        $file = $this->resolve($relative);
        if (!\is_file($file)) {
            return null;
        }
        [$content] = Warnings::capture(static fn (): string|false => \file_get_contents($file));

        return false === $content ? null : $content;
    }

    public function exists(string $relative): bool
    {
        return \file_exists($this->resolve($relative));
    }

    /**
     * Removes a file or a directory with everything in it.
     */
    public function remove(string $relative): void
    {
        $path = $this->resolve($relative);
        if (!\file_exists($path) && !\is_link($path)) {
            throw new \InvalidArgumentException(\sprintf('"%s" does not exist in the workspace.', $relative));
        }
        self::delete($path);
    }

    /**
     * Removes the workspace.
     */
    public function destroy(): void
    {
        if (\is_dir($this->path)) {
            self::delete($this->path);
        }
    }

    private function makeDirectory(string $directory): void
    {
        if (\is_dir($directory)) {
            return;
        }
        [$created, $warning] = Warnings::capture(static fn (): bool => \mkdir($directory, permissions: 0o777, recursive: true));
        if (!$created) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s": %s', \substr($directory, \strlen($this->path) + 1), $warning ?? 'unknown error'));
        }
    }

    private static function delete(string $path): void
    {
        if (\is_dir($path) && !\is_link($path)) {
            $entries = \scandir($path);
            foreach (false === $entries ? [] : \array_diff($entries, ['.', '..']) as $entry) {
                self::delete($path.'/'.$entry);
            }
            Warnings::capture(static fn (): bool => \rmdir($path));

            return;
        }
        Warnings::capture(static fn (): bool => \unlink($path));
    }
}
