<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests\ChildProcess;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use ReactphpX\Rpc\AccessLogHandler;
use ReactphpX\Rpc\ChildProcess\Client;

class ChildProcessClientTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        // Ensure event loop is running
        $loop = Loop::get();
        
        // Create client with test evaluator
        $this->client = new Client(
            TestEvaluator::class,
            null,
            __DIR__ . '/TestEvaluator.php'
        );
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }
    }

    public function testClientCreation(): void
    {
        $client = new Client(
            TestEvaluator::class,
            null,
            __DIR__ . '/TestEvaluator.php'
        );
        
        $this->assertInstanceOf(Client::class, $client);
        $client->close();
    }

    public function testClientWithAccessLog(): void
    {
        $accessLog = new AccessLogHandler(false);
        $client = new Client(
            TestEvaluator::class,
            $accessLog,
            __DIR__ . '/TestEvaluator.php'
        );
        
        $this->assertInstanceOf(Client::class, $client);
        $client->close();
    }

    public function testCallMethod(): void
    {
        $loop = Loop::get();
        $result = null;
        $error = null;
        $completed = false;

        $this->client->call('add', [2, 3])
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            })
            ->catch(function ($e) use (&$error, &$completed) {
                $error = $e;
                $completed = true;
            });

        // Wait for promise to resolve
        $loop->addTimer(1.0, function () use ($loop) {
            $loop->stop();
        });
        
        $loop->run();

        $this->assertTrue($completed, 'Promise should complete');
        $this->assertNull($error, 'Should not have error: ' . ($error ? $error->getMessage() : ''));
        $this->assertEquals(5, $result);
    }

    public function testCallMethodWithNamedParams(): void
    {
        $loop = Loop::get();
        $result = null;
        $error = null;
        $completed = false;

        $this->client->call('greet', ['name' => 'Test'])
            ->then(function ($value) use (&$result, &$completed) {
                $result = $value;
                $completed = true;
            })
            ->catch(function ($e) use (&$error, &$completed) {
                $error = $e;
                $completed = true;
            });

        // Wait for promise to resolve
        $loop->addTimer(1.0, function () use ($loop) {
            $loop->stop();
        });
        
        $loop->run();

        $this->assertTrue($completed, 'Promise should complete');
        $this->assertNull($error, 'Should not have error: ' . ($error ? $error->getMessage() : ''));
        $this->assertEquals('Hello, Test!', $result);
    }

    public function testNotifyMethod(): void
    {
        $loop = Loop::get();
        $completed = false;

        $this->client->notify('echo', ['message' => 'test'])
            ->then(function () use (&$completed) {
                $completed = true;
            })
            ->catch(function ($e) use (&$completed) {
                $completed = true;
            });

        // Wait for promise to resolve
        $loop->addTimer(0.5, function () use ($loop) {
            $loop->stop();
        });
        
        $loop->run();

        $this->assertTrue($completed, 'Notification should complete');
    }

    public function testBatchMethod(): void
    {
        $loop = Loop::get();
        $result = null;
        $error = null;
        $completed = false;

        $this->client->batch([
            ['add', [2, 3]],
            ['subtract', [10, 4]],
            ['multiply', [5, 6]],
        ])
        ->then(function ($responses) use (&$result, &$completed) {
            $result = $responses;
            $completed = true;
        })
        ->catch(function ($e) use (&$error, &$completed) {
            $error = $e;
            $completed = true;
        });

        // Wait for promise to resolve
        $loop->addTimer(2.0, function () use ($loop) {
            $loop->stop();
        });
        
        $loop->run();

        $this->assertTrue($completed, 'Batch promise should complete');
        $this->assertNull($error, 'Should not have error: ' . ($error ? $error->getMessage() : ''));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }

    public function testCallNonExistentMethod(): void
    {
        $loop = Loop::get();
        $error = null;
        $completed = false;

        $this->client->call('nonexistent', [])
            ->then(function () use (&$completed) {
                $completed = true;
            })
            ->catch(function ($e) use (&$error, &$completed) {
                $error = $e;
                $completed = true;
            });

        // Wait for promise to resolve
        $loop->addTimer(1.0, function () use ($loop) {
            $loop->stop();
        });
        
        $loop->run();

        $this->assertTrue($completed, 'Promise should complete');
        $this->assertNotNull($error, 'Should have error for nonexistent method');
    }
}

