<?php

declare(strict_types=1);

/*
 * This file is part of the tuink package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Janalis\Tuink\Tests\Fixtures;

/**
 * An application's probe that answers each request a given time after it came, run from the server's idle callback.
 */
final class SlowProbe
{
    /** @var resource|null */
    private $client;
    /** @var list<float> When each request came */
    private array $received = [];
    private int $answered = 0;

    /**
     * @param list<float> $delays Seconds before each answer, by request
     */
    public function __construct(
        private readonly array $delays,
    ) {
    }

    public function connect(string $path): void
    {
        $client = \stream_socket_client('unix://'.$path);
        if (false === $client) {
            throw new \RuntimeException('Cannot connect.');
        }
        \stream_set_blocking($client, enable: false);
        $this->client = $client;
    }

    public function __invoke(): void
    {
        if (null === $this->client) {
            return;
        }
        while (false !== \fgets($this->client)) {
            $this->received[] = \microtime(true);
        }
        $due = ($this->received[$this->answered] ?? \INF) + ($this->delays[$this->answered] ?? 0.0);
        if (\microtime(true) >= $due) {
            \fwrite($this->client, \sprintf("{\"xml\":\"<answer-%d/>\"}\n", ++$this->answered));
        }
    }

    public function close(): void
    {
        if (null !== $this->client) {
            \fclose($this->client);
        }
    }
}
