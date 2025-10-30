<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests\ChildProcess;

use ReactphpX\Rpc\Evaluator;

/**
 * Test evaluator for ChildProcess tests
 */
class TestEvaluator implements Evaluator
{
    public function evaluate($method, $arguments)
    {
        return match ($method) {
            'add' => array_sum($arguments ?? []),
            'subtract' => ($arguments[0] ?? 0) - ($arguments[1] ?? 0),
            'multiply' => array_product($arguments ?? []),
            'echo' => $arguments[0] ?? null,
            'greet' => 'Hello, ' . ($arguments['name'] ?? 'World') . '!',
            'test' => 'test_result',
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }
}

