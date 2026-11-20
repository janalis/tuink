<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Example\DemoApp;
use Janalis\Tuink\Probe\TuiProbe;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class TuiProbeTest extends TestCase
{
    public function testDoesNothingOutsideTheProcessDriver(): void
    {
        \putenv(TuiProbe::ENV);

        $this->assertFalse(TuiProbe::install(new DemoApp(new VirtualTerminal())->tui));
    }

    public function testFailsLoudlyWhenTheSocketIsGone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('The tuink probe cannot connect to');

        TuiProbe::install(new DemoApp(new VirtualTerminal())->tui, \sys_get_temp_dir().'/tuink-missing-'.\bin2hex(\random_bytes(4)).'.sock');
    }

    public function testAnswersTheDriverUntilItHangsUp(): void
    {
        $directory = \sys_get_temp_dir().'/tuink-probe-test-'.\bin2hex(\random_bytes(4));
        \mkdir($directory, permissions: 0o700);
        $path = $directory.'/probe.sock';
        $server = \stream_socket_server('unix://'.$path);
        $this->assertNotFalse($server);
        $app = new DemoApp(new VirtualTerminal());
        $before = EventLoop::getIdentifiers();
        $streams = \count(\get_resources('stream'));

        try {
            $this->assertTrue(TuiProbe::install($app->tui, $path));
            $ids = \array_values(\array_diff(EventLoop::getIdentifiers(), $before));
            $this->assertCount(1, $ids);
            // The probe must not keep the loop, and the application, alive
            $this->assertFalse(EventLoop::isReferenced($ids[0]));

            $connection = \stream_socket_accept($server, timeout: 1.0);
            $this->assertNotFalse($connection);

            $tree = self::ask($connection, request: '{"op":"tree"}');
            $this->assertIsString($tree['xml'] ?? null);
            $this->assertStringContainsString('id="color"', $tree['xml']);

            $this->assertSame(['ok' => true], self::ask($connection, request: '{"op":"focus","index":7}'));
            $this->assertSame('color', $app->tui->getFocus()?->getId());

            $this->assertSame(['error' => 'Unknown operation "string".'], self::ask($connection, request: '{"op":"jump"}'));
            $this->assertSame(['error' => 'No widget has the index 999.'], self::ask($connection, request: '{"op":"focus","index":999}'));

            // Hanging up ends the probe: its callback and its socket are released
            \fclose($connection);
            self::turn(0.05);
            $this->assertSame([], \array_values(\array_diff(EventLoop::getIdentifiers(), $before)));
            $this->assertSame($streams, \count(\get_resources('stream')));
        } finally {
            \fclose($server);
            \unlink($path);
            \rmdir($directory);
        }
    }

    public function testAFailureSaysWhy(): void
    {
        $path = \sys_get_temp_dir().'/tuink-missing-'.\bin2hex(\random_bytes(4)).'.sock';

        try {
            TuiProbe::install(new DemoApp(new VirtualTerminal())->tui, $path);
            $this->fail('A runtime exception was expected.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(\sprintf('The tuink probe cannot connect to "%s": ', $path), $e->getMessage());
            $this->assertStringNotContainsString('unknown error', $e->getMessage());
        }
    }

    /**
     * Sends a request as the process driver does, and turns the loop until the probe answers.
     *
     * @param resource $connection
     *
     * @return array<array-key, mixed>
     */
    private static function ask($connection, string $request): array
    {
        \fwrite($connection, $request."\n");
        \stream_set_blocking($connection, enable: false);
        $answer = '';
        for ($i = 0; $i < 100 && !\str_contains($answer, "\n"); ++$i) {
            self::turn(0.01);
            $answer .= (string) \fread($connection, length: 1_000_000);
        }
        // One answer per request, on one line, as the process driver reads it
        $end = \strpos($answer, needle: "\n");
        if (false === $end || \strlen($answer) !== ($end + 1)) {
            throw new \UnexpectedValueException(\sprintf('Not one line: "%s".', $answer));
        }

        return self::object(\json_decode(\substr($answer, offset: 0, length: $end), associative: true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function object(mixed $decoded): array
    {
        return \is_array($decoded) ? $decoded : [];
    }

    private static function turn(float $seconds): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay($seconds, $suspension->resume(...));
        $suspension->suspend();
    }
}
