<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\Rpc\Evaluator;

class EvaluatorTest extends TestCase
{
    public function testEvaluatorInterface(): void
    {
        $evaluator = new class implements Evaluator {
            public function evaluate($method, $arguments)
            {
                return match ($method) {
                    'test' => 'success',
                    'add' => array_sum($arguments ?? []),
                    default => throw new \RuntimeException("Method '{$method}' not found", -32601),
                };
            }
        };
        
        $this->assertInstanceOf(Evaluator::class, $evaluator);
        $this->assertEquals('success', $evaluator->evaluate('test', null));
        $this->assertEquals(5, $evaluator->evaluate('add', [2, 3]));
    }

    public function testEvaluatorExtendsDattoEvaluator(): void
    {
        $this->assertTrue(is_subclass_of(Evaluator::class, \Datto\JsonRpc\Evaluator::class));
    }
}

