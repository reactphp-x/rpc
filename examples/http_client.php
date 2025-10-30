<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Http\Browser;
use ReactphpX\Rpc\Http\HttpClient;
use ReactphpX\Rpc\AccessLogHandler;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;

/**
 * Example HTTP JSON-RPC Client
 * 
 * Usage: php examples/http_client.php [port] [host] [debug]
 * Example: php examples/http_client.php 8080 127.0.0.1 true
 * Make sure the HTTP server is running first
 */

// Get port and host from command line arguments or use defaults
$port = $argv[1] ?? '8080';
$host = $argv[2] ?? '127.0.0.1';
$debug = isset($argv[3]) && ($argv[3] === 'true' || $argv[3] === '1');

if (!is_numeric($port) || $port < 1 || $port > 65535) {
    echo "Error: Invalid port number. Must be between 1 and 65535.\n";
    exit(1);
}

$loop = Loop::get();
$browser = new Browser($loop);

$url = "http://{$host}:{$port}";
$accessLog = $debug ? new AccessLogHandler(true) : null;
$client = new HttpClient($browser, $url, $accessLog);

echo "Connecting to {$url}...\n";
if ($debug) {
    echo "Access log: ENABLED\n";
}
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
    ['echo', ['message' => 'This is a notification']],
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
$loop->addTimer(2.0, function () use ($loop) {
    echo "\nDone!\n";
    $loop->stop();
});

$loop->run();
