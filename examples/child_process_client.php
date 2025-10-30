<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/SimpleEvaluator.php';

use React\EventLoop\Loop;
use ReactphpX\Rpc\ChildProcess\Client;
use ReactphpX\Rpc\AccessLogHandler;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;

/**
 * Example ChildProcess JSON-RPC Client
 * 
 * Usage: php examples/child_process_client.php [debug]
 * Example: php examples/child_process_client.php true
 * 
 * Make sure the server is running first or run both together.
 * This example demonstrates how to use ChildProcess RPC client.
 */

// Get debug flag from command line argument or use default
$debug = isset($argv[1]) && ($argv[1] === 'true' || $argv[1] === '1');

$loop = Loop::get();

// Create access log handler
$accessLog = $debug ? new AccessLogHandler(true) : null;

// Create ChildProcess client with evaluator class name and file path
$client = new Client(
    SimpleEvaluator::class,
    $accessLog,
    __DIR__ . '/SimpleEvaluator.php'
);


    if ($debug) {
        echo "Access log: ENABLED\n";
    }
    echo "\n";

    echo "Calling add(2, 3)...\n";
    $client->call('add', [2, 3])
        ->then(function ($result) {
            echo "Result: " . json_encode($result) . "\n";
        })
        ->catch(function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

    echo "Calling subtract(10, 4)...\n";
    $client->call('subtract', [10, 4])
        ->then(function ($result) {
            echo "Result: " . json_encode($result) . "\n";
        })
        ->catch(function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

    echo "Calling greet with name...\n";
    $client->call('greet', ['name' => 'Alice'])
        ->then(function ($result) {
            echo "Result: " . json_encode($result) . "\n";
        })
        ->catch(function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

    echo "Calling multiply(5, 6)...\n";
    $client->call('multiply', [5, 6])
        ->then(function ($result) {
            echo "Result: " . json_encode($result) . "\n";
        })
        ->catch(function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

    echo "Calling slow(2)...\n";
    $client->call('slow', [2])
        ->then(function ($result) {
            echo "Result: " . json_encode($result) . "\n";
        })
        ->catch(function ($error) {
            echo "Error: " . $error->getMessage() . "\n";
        });

    echo "Sending notification...\n";
    $client->notify('echo', ['message' => 'This is a notification'])
        ->then(function () {
            echo "Notification sent successfully\n";
        });

    echo "Calling batch methods...\n";
    $client->batch([
        ['add', [2, 3]],
        ['subtract', [10, 4]],
        ['greet', ['name' => 'Bob']],
        ['multiply', [5, 6]],
    ])
    ->then(function ($responses) {
        echo "Batch results (" . count($responses) . " responses):\n";
        foreach ($responses as $index => $response) {
            if ($response instanceof ResultResponse) {
                echo "  [$index] Result: " . json_encode($response->getValue()) . "\n";
            } elseif ($response instanceof ErrorResponse) {
                echo "  [$index] Error: " . $response->getMessage() . " (code: " . $response->getCode() . ")\n";
            } else {
                echo "  [$index] Unknown response type\n";
            }
        }
    })
    ->catch(function ($error) {
        echo "Batch error: " . $error->getMessage() . "\n";
    });

    // Run for a short time then exit
    $loop->addTimer(2.0, function () use ($loop, $client) {
        echo "\nClosing client...\n";
        $client->close();
        echo "Done!\n";
        $loop->stop();
    });


$loop->run();

