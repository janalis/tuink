<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Exception\DriverException;
use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Probe\ProbeServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProbeServerTest extends TestCase
{
    public function testWaitingForTheApplicationKeepsItsOutputFlowing(): void
    {
        $idle = 0;
        $server = ProbeServer::listen(
            timeout: 0.1,
            isRunning: static fn (): bool => true,
            idle: static function () use (&$idle): void {
                ++$idle;
            },
            parent: \sys_get_temp_dir(),
        );

        try {
            $server->tree();
            $this->fail('An unsupported driver exception was expected.');
        } catch (UnsupportedDriverException $e) {
            $this->assertStringContainsString('did not connect to the tuink probe', $e->getMessage());
            $this->assertGreaterThan(0, $idle);
        } finally {
            $server->close();
        }
    }

    public function testTheSocketLivesInAPrivateDirectory(): void
    {
        $server = self::listen();

        try {
            $this->assertMatchesRegularExpression('#/tuink-[0-9a-f]{12}/probe\.sock$#', $server->path);
            $this->assertSame(0o700, \fileperms(\dirname($server->path)) & 0o777);
        } finally {
            $server->close();
        }
        $this->assertDirectoryDoesNotExist(\dirname($server->path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function answers(): iterable
    {
        yield 'an error' => ['{"error":"broken"}', 'The tuink probe failed: broken'];
        yield 'an error that is not text' => ['{"error":7}', 'The tuink probe failed: unknown error'];
        yield 'not an object' => ['"tree"', 'The tuink probe sent an invalid answer.'];
        yield 'no tree' => ['{"ok":true}', 'The tuink probe sent no widget tree.'];
    }

    #[DataProvider('answers')]
    public function testAnswersThatAreNotATree(string $answer, string $message): void
    {
        [$server, $client] = self::connected();

        try {
            \fwrite($client, $answer."\n");
            $server->tree();
            $this->fail('A driver exception was expected.');
        } catch (DriverException $e) {
            $this->assertSame($message, $e->getMessage());
        } finally {
            \fclose($client);
            $server->close();
        }
    }

    public function testAProbeThatHangsUp(): void
    {
        [$server, $client] = self::connected();
        \fclose($client);

        try {
            $server->tree();
            $this->fail('A driver exception was expected.');
        } catch (DriverException $e) {
            $this->assertSame('The tuink probe connection was closed.', $e->getMessage());
        } finally {
            $server->close();
        }
    }

    public function testAProbeThatDoesNotAnswer(): void
    {
        [$server, $client] = self::connected(timeout: 0.1);

        try {
            $server->tree();
            $this->fail('A driver exception was expected.');
        } catch (DriverException $e) {
            $this->assertSame('The tuink probe did not answer within 0.1 seconds.', $e->getMessage());
        } finally {
            \fclose($client);
            $server->close();
        }
    }

    public function testAnApplicationThatStopsBeforeConnecting(): void
    {
        $running = false;
        $server = ProbeServer::listen(
            timeout: 5.0,
            isRunning: static function () use (&$running): bool {
                return $running;
            },
            idle: static function (): void {},
            parent: \sys_get_temp_dir(),
        );
        $start = \microtime(true);

        try {
            $server->tree();
            $this->fail('An unsupported driver exception was expected.');
        } catch (UnsupportedDriverException) {
            $this->assertLessThan(1.0, \microtime(true) - $start);
        }

        // Known missing now: the next request fails at once, without waiting for a connection
        $running = true;
        $start = \microtime(true);
        try {
            $server->focus(1);
            $this->fail('An unsupported driver exception was expected.');
        } catch (UnsupportedDriverException) {
            $this->assertLessThan(1.0, \microtime(true) - $start);
        } finally {
            $server->close();
        }
    }

    public function testAHangUpReleasesTheConnection(): void
    {
        [$server, $client] = self::connected();
        $streams = \count(\get_resources('stream'));
        \fclose($client);

        try {
            $server->tree();
            $this->fail('A driver exception was expected.');
        } catch (DriverException) {
            // The client's end is gone, and the server's end, accepted meanwhile, is closed again
            $this->assertSame($streams - 1, \count(\get_resources('stream')));
        } finally {
            $server->close();
        }
    }

    public function testCloseReleasesEverything(): void
    {
        $streams = \count(\get_resources('stream'));
        [$server, $client] = self::connected();
        \fwrite($client, '{"xml":"<container/>"}'."\n");
        $this->assertSame('<container/>', $server->tree());

        \fclose($client);
        $server->close();

        $this->assertSame($streams, \count(\get_resources('stream')));
        $this->assertFileDoesNotExist($server->path);
    }

    /**
     * A server with a client connected, as the application's probe would be.
     *
     * @return array{ProbeServer, resource}
     */
    private static function connected(float $timeout = 1.0): array
    {
        $server = self::listen($timeout);
        $client = \stream_socket_client('unix://'.$server->path);
        if (false === $client) {
            throw new \RuntimeException('Cannot connect.');
        }

        return [$server, $client];
    }

    private static function listen(float $timeout = 1.0): ProbeServer
    {
        return ProbeServer::listen($timeout, isRunning: static fn (): bool => true, idle: static function (): void {}, parent: \sys_get_temp_dir());
    }
}
