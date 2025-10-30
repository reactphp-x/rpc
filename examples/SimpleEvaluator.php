<?php

declare(strict_types=1);

use ReactphpX\Rpc\Evaluator;
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
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }

    private function slowOperation(int $seconds)
    {
        // Simulate a slow operation using async delay (non-blocking)
        delay($seconds);
        return "Slow operation completed after {$seconds} seconds";
    }
}

