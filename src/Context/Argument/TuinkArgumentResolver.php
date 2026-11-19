<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Context\Argument;

use Behat\Behat\Context\Argument\ArgumentResolver;
use Janalis\Tuink\Tuink;

/**
 * Passes the Tuink manager to context constructors that ask for it by type.
 *
 *     public function __construct(private Tuink $tuink) {}
 */
final class TuinkArgumentResolver implements ArgumentResolver
{
    public function __construct(
        private readonly Tuink $tuink,
    ) {}

    public function resolveArguments(\ReflectionClass $classReflection, array $arguments): array
    {
        foreach ($classReflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || Tuink::class !== $type->getName()) {
                continue;
            }
            // Arguments set in the suite configuration win, by name or by position
            if (\array_key_exists($parameter->getName(), $arguments) || \array_key_exists($parameter->getPosition(), $arguments)) {
                continue;
            }
            $arguments[$parameter->getName()] = $this->tuink;
        }

        return $arguments;
    }
}
