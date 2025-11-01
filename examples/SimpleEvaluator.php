<?php

declare(strict_types=1);

use ReactphpX\Rpc\Evaluator;
use React\Promise\PromiseInterface;
use React\Promise\Deferred;
use React\EventLoop\Loop;
use function React\Async\delay;

/**
 * Simple evaluator implementation for examples
 */
class SimpleEvaluator implements Evaluator
{
    public function evaluate($method, $arguments)
    {
        return match ($method) {
            'add' => array_sum($arguments ?? []),
            'subtract' => ($arguments[0] ?? 0) - ($arguments[1] ?? 0),
            'multiply' => array_product($arguments ?? []),
            'echo' => $arguments[0] ?? null,
            'greet' => 'Hello, ' . ($arguments['name'] ?? 'World') . '!',
            'slow' => $this->slowOperation($arguments[0] ?? 1),
            'async_fetch' => $this->asyncFetch($arguments['url'] ?? null),
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }

    private function slowOperation(int $seconds)
    {
        // Simulate a slow operation using async delay (non-blocking)
        delay($seconds);
        return "Slow operation completed after {$seconds} seconds";
    }

    /**
     * Example method that returns a Promise
     * Simulates an async API fetch operation
     *
     * @param string|null $url The URL to fetch
     * @return PromiseInterface Promise that resolves to the fetched data
     */
    private function asyncFetch(?string $url): PromiseInterface
    {
        $deferred = new Deferred();
        
        if ($url === null) {
            $deferred->resolve(['error' => 'URL is required']);
            return $deferred->promise();
        }

        // Simulate async operation with delay (0.5 seconds)
        Loop::get()->addTimer(0.5, function () use ($deferred, $url) {
            // Simulate successful fetch
            $deferred->resolve([
                'url' => $url,
                'status' => 200,
                'data' => [
                    'id' => 123,
                    'name' => 'Example Data',
                    'timestamp' => time(),
                ],
            ]);
        });
        
        return $deferred->promise();
    }
}

