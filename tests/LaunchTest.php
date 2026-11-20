<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Launch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaunchTest extends TestCase
{
    public function testDefaults(): void
    {
        $launch = new Launch();

        $this->assertSame([], $launch->arguments);
        $this->assertSame([], $launch->env);
        $this->assertNull($launch->workspace);
        $this->assertFalse($launch->kitty);
    }

    public function testEachChangeKeepsTheRest(): void
    {
        $launch = new Launch()
            ->withArguments(['a'])
            ->withEnv('A', value: '1')
            ->withWorkspace('/w')
            ->withKitty();

        $this->assertEquals(new Launch(['a'], ['A' => '1'], '/w', kitty: true), $launch);
        $this->assertEquals(new Launch(['b'], ['A' => '1'], '/w', kitty: true), $launch->withArguments(['b']));
        $this->assertEquals(new Launch(['a'], ['A' => '1', 'B' => '2'], '/w', kitty: true), $launch->withEnv('B', value: '2'));
        $this->assertEquals(new Launch(['a'], ['A' => '1'], null, kitty: true), $launch->withWorkspace(null));
        $this->assertEquals(new Launch(['a'], ['A' => '1'], '/w', kitty: false), $launch->withKitty(false));
        // The original does not change
        $this->assertEquals(new Launch(['a'], ['A' => '1'], '/w', kitty: true), $launch);
    }

    public function testAVariableIsReplacedOrRemoved(): void
    {
        $launch = new Launch(env: ['A' => '1', 'B' => '2']);

        $this->assertSame(['B' => '2', 'A' => '3'], $launch->withEnv('A', value: '3')->env);
        $this->assertSame(['B' => '2'], $launch->withEnv('A', value: null)->env);
        $this->assertSame(['A' => '1', 'B' => '2'], $launch->withEnv('C', value: null)->env);
    }

    public function testResolvedWithoutAWorkspaceChangesNothing(): void
    {
        $launch = new Launch(['a'], ['A' => 'workspace%'], kitty: true);

        $this->assertSame($launch, $launch->resolved());
    }

    /**
     * @return iterable<string, array{Launch, string}>
     */
    public static function namingAMissingWorkspace(): iterable
    {
        yield 'an argument' => [new Launch(['a', '%workspace%/b']), '%workspace%/b'];
        yield 'a variable' => [new Launch(['a'], ['A' => 'x', 'B' => 'in %workspace%']), 'in %workspace%'];
    }

    #[DataProvider('namingAMissingWorkspace')]
    public function testResolvedFailsWhenTheWorkspaceIsNamedButMissing(Launch $launch, string $value): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('"%s" names the workspace, but the scenario has none.', $value));

        $launch->resolved();
    }

    public function testResolvedNamesTheWorkspace(): void
    {
        $launch = new Launch(['%workspace%/a', 'b', '%workspace%%workspace%'], ['A' => '%workspace%/x', 'B' => 'y'], '/w', kitty: true)->resolved();

        $this->assertSame(['/w/a', 'b', '/w/w'], $launch->arguments);
        $this->assertSame(['TUINK_WORKSPACE' => '/w', 'A' => '/w/x', 'B' => 'y'], $launch->env);
        $this->assertSame('/w', $launch->workspace);
        $this->assertTrue($launch->kitty);
    }

    public function testAScenarioCanOverrideTheWorkspaceVariable(): void
    {
        $launch = new Launch(env: ['TUINK_WORKSPACE' => 'mine'], workspace: '/w')->resolved();

        $this->assertSame(['TUINK_WORKSPACE' => 'mine'], $launch->env);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commandLines(): iterable
    {
        yield 'nothing' => ['', []];
        yield 'spaces' => ['  a   b ', ['a', 'b']];
        yield 'a quoted word' => ["'a b' c", ['a b', 'c']];
        yield 'a quoted value' => ["--name='a b' c", ['--name=a b', 'c']];
        yield 'quotes inside a word' => ["a'b c'd'e'", ['ab cde']];
        yield 'an empty word' => ["'' a", ['', 'a']];
        yield 'tabs' => ["a\tb", ['a', 'b']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('commandLines')]
    public function testSplit(string $line, array $expected): void
    {
        $this->assertSame($expected, Launch::split($line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unclosedQuotes(): iterable
    {
        yield 'alone' => ["a ' b"];
        yield 'at the start' => ["'a b"];
        yield 'after a word' => ["a'b"];
        yield 'after a pair' => ["'a' 'b"];
    }

    #[DataProvider('unclosedQuotes')]
    public function testSplitFailsOnAnUnclosedQuote(string $line): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The arguments "%s" have a quote that is not closed.', $line));

        Launch::split($line);
    }
}
