<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Connector;
use ReactphpX\Rpc\Tcp\TcpClient;
use ReactphpX\Rpc\AccessLogHandler;
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;

/**
 * Example TCP JSON-RPC Client using NDJSON
 * 
 * Usage: php examples/tcp_client.php [port] [host] [debug]
 * Example: php examples/tcp_client.php 8081 127.0.0.1 true
 * Make sure the TCP server is running first
 */

// Get port and host from command line arguments or use defaults
$port = $argv[1] ?? '8081';
$host = $argv[2] ?? '127.0.0.1';
$debug = isset($argv[3]) && ($argv[3] === 'true' || $argv[3] === '1');

if (!is_numeric($port) || $port < 1 || $port > 65535) {
    echo "Error: Invalid port number. Must be between 1 and 65535.\n";
    exit(1);
}

$loop = Loop::get();
$connector = new Connector($loop);

$uri = "{$host}:{$port}";
$accessLog = $debug ? new AccessLogHandler(true) : null;
$client = new TcpClient($uri, $connector, $accessLog);

echo "Connecting to tcp://{$uri}...\n";
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

$client->call('greet', ['name' => 'Bob'])
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

echo "Calling async_fetch (single call)...\n";
$singleStartTime = microtime(true);
$client->call('async_fetch', ['url' => 'https://example.com/api/user'])
    ->then(function ($result) use ($singleStartTime) {
        $singleDuration = microtime(true) - $singleStartTime;
        echo "Result: " . json_encode($result) . "\n";
        echo "Single call duration: " . number_format($singleDuration * 1000, 2) . "ms\n";
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
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

echo "\nCalling async_fetch in batch (testing concurrency)...\n";
$batchStartTime = microtime(true);
$client->batch([
    ['async_fetch', ['url' => 'https://example.com/api/user/1']],
    ['async_fetch', ['url' => 'https://example.com/api/user/2']],
    ['async_fetch', ['url' => 'https://example.com/api/user/3']],
    ['async_fetch', ['url' => 'https://example.com/api/user/4']],
    ['async_fetch', ['url' => 'https://example.com/api/user/5']],
])
->then(function ($responses) use ($batchStartTime) {
    $batchDuration = microtime(true) - $batchStartTime;
    echo "Batch async_fetch results (" . count($responses) . " responses):\n";
    foreach ($responses as $index => $response) {
        if ($response instanceof ResultResponse) {
            $value = $response->getValue();
            echo "  [$index] URL: " . ($value['url'] ?? 'N/A') . ", Status: " . ($value['status'] ?? 'N/A') . "\n";
        } elseif ($response instanceof ErrorResponse) {
            echo "  [$index] Error: " . $response->getMessage() . " (code: " . $response->getCode() . ")\n";
        } else {
            echo "  [$index] Unknown response type\n";
        }
    }
    echo "\nBatch duration: " . number_format($batchDuration * 1000, 2) . "ms\n";
    echo "Expected duration if sequential: ~2500ms (5 calls × 500ms each)\n";
    echo "If concurrent, duration should be ~500ms (single call duration)\n";
})
->catch(function ($error) {
    echo "Batch async_fetch error: " . $error->getMessage() . "\n";
});

// Run for a longer time to allow async operations to complete
$loop->addTimer(4.0, function () use ($loop, $client) {
    echo "\nClosing connection...\n";
    $client->close();
    $loop->stop();
});

$loop->run();
