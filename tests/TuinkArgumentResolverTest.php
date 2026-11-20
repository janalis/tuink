<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Context\Argument\TuinkArgumentResolver;
use Janalis\Tuink\Driver\InProcessDriver;
use Janalis\Tuink\Tuink;
use PHPUnit\Framework\TestCase;

final class TuinkArgumentResolverTest extends TestCase
{
    public function testInjectsTheManagerByType(): void
    {
        $tuink = $this->tuink();
        $context = new class($tuink, 'x') {
            public function __construct(
                public Tuink $tuink,
                public string $other,
            ) {
            }
        };

        $arguments = new TuinkArgumentResolver($tuink)->resolveArguments(new \ReflectionClass($context), ['other' => 'x']);

        $this->assertSame(['other' => 'x', 'tuink' => $tuink], $arguments);
    }

    public function testConfiguredArgumentsWin(): void
    {
        $tuink = $this->tuink();
        $mine = $this->tuink();
        $context = new class($tuink) {
            public function __construct(
                public Tuink $tuink,
            ) {
            }
        };
        $resolver = new TuinkArgumentResolver($tuink);

        $this->assertSame(['tuink' => $mine], $resolver->resolveArguments(new \ReflectionClass($context), ['tuink' => $mine]));
        $this->assertSame([0 => $mine], $resolver->resolveArguments(new \ReflectionClass($context), [0 => $mine]));
    }

    public function testLeavesOtherContextsAlone(): void
    {
        $this->assertSame([], new TuinkArgumentResolver($this->tuink())->resolveArguments(new \ReflectionClass(\stdClass::class), []));
    }

    public function testUnionTypesAreLeftAlone(): void
    {
        $context = new class(null) {
            public function __construct(
                public Tuink|string|null $value,
            ) {
            }
        };

        $this->assertSame([], new TuinkArgumentResolver($this->tuink())->resolveArguments(new \ReflectionClass($context), []));
    }

    public function testEveryTuinkParameterIsConsidered(): void
    {
        $tuink = $this->tuink();
        $mine = $this->tuink();
        $context = new class('x', $tuink, $tuink) {
            public function __construct(
                public string $other,
                public Tuink $first,
                public Tuink $second,
            ) {
            }
        };

        $arguments = new TuinkArgumentResolver($tuink)->resolveArguments(new \ReflectionClass($context), ['other' => 'x', 'first' => $mine]);

        $this->assertSame(['other' => 'x', 'first' => $mine, 'second' => $tuink], $arguments);
    }

    private function tuink(): Tuink
    {
        return new Tuink(['in_process' => static fn () => new InProcessDriver(static fn () => null)], 'in_process');
    }
}
