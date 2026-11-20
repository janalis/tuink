<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink;

/**
 * What a scenario starts the application with, on top of the driver's configuration.
 *
 * The in-process factory receives it as its second argument; the process driver
 * appends the arguments to its command and adds the variables to its environment.
 */
final readonly class Launch
{
    /** The variable that holds the scenario's workspace, when it has one */
    public const WORKSPACE_ENV = 'TUINK_WORKSPACE';

    /**
     * @param list<string>          $arguments Command-line arguments
     * @param array<string, string> $env       Environment variables
     * @param string|null           $workspace The scenario's directory of files, if a step created it
     * @param bool                  $kitty     Whether the terminal speaks the kitty keyboard protocol
     */
    public function __construct(
        public array $arguments = [],
        public array $env = [],
        public ?string $workspace = null,
        public bool $kitty = false,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self($arguments, $this->env, $this->workspace, $this->kitty);
    }

    /**
     * Sets an environment variable, or removes it with null.
     */
    public function withEnv(string $name, ?string $value): self
    {
        $env = $this->env;
        unset($env[$name]);
        if (null !== $value) {
            $env[$name] = $value;
        }

        return new self($this->arguments, $env, $this->workspace, $this->kitty);
    }

    public function withWorkspace(?string $workspace): self
    {
        return new self($this->arguments, $this->env, $workspace, $this->kitty);
    }

    public function withKitty(bool $kitty = true): self
    {
        return new self($this->arguments, $this->env, $this->workspace, $kitty);
    }

    /**
     * Splits a command line as a shell would, with single quotes only: 'a b' and --name='a b' are one argument each,
     * and '' an empty one.
     *
     * @return list<string>
     */
    public static function split(string $line): array
    {
        $words = [];
        \preg_match_all("/(?:[^\\s']+|'[^']*')+|'/", $line, $words);
        $arguments = [];
        foreach ($words[0] as $word) {
            if ("'" === $word) {
                throw new \InvalidArgumentException(\sprintf('The arguments "%s" have a quote that is not closed.', $line));
            }
            $arguments[] = \str_replace("'", replace: '', subject: $word);
        }

        return $arguments;
    }

    /**
     * The arguments and variables with "%workspace%" replaced by the workspace's path, and the workspace in
     * TUINK_WORKSPACE; naming the workspace without one is an error.
     */
    public function resolved(): self
    {
        if (null === $this->workspace) {
            foreach ([...$this->arguments, ...$this->env] as $value) {
                if (\str_contains($value, '%workspace%')) {
                    throw new \LogicException(\sprintf('"%s" names the workspace, but the scenario has none.', $value));
                }
            }

            return $this;
        }
        $expand = fn (string $value): string => \str_replace('%workspace%', replace: $this->workspace, subject: $value);

        return new self(
            \array_map($expand, $this->arguments),
            [self::WORKSPACE_ENV => $this->workspace, ...\array_map($expand, $this->env)],
            $this->workspace,
            $this->kitty,
        );
    }
}
