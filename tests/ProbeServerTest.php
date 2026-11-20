<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests;

use Janalis\Tuink\Exception\UnsupportedDriverException;
use Janalis\Tuink\Probe\ProbeServer;
use PHPUnit\Framework\TestCase;

final class ProbeServerTest extends TestCase
{
    public function testWaitingForTheApplicationKeepsItsOutputFlowing(): void
    {
        $server = ProbeServer::listen();
        $idle = 0;

        try {
            $server->request(['op' => 'tree'], timeout: 0.1, isRunning: static fn (): bool => true, idle: static function () use (&$idle): void {
                ++$idle;
            });
            $this->fail('An unsupported driver exception was expected.');
        } catch (UnsupportedDriverException $e) {
            $this->assertStringContainsString('did not connect to the tuink probe', $e->getMessage());
            $this->assertGreaterThan(0, $idle);
        } finally {
            $server->close();
        }
    }
}
