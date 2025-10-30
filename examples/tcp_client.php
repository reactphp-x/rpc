<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\Connector;
use ReactphpX\Rpc\Tcp\TcpClient;
use ReactphpX\Rpc\AccessLogHandler;

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

// Run for a short time then exit
$loop->addTimer(2.0, function () use ($loop, $client) {
    echo "\nClosing connection...\n";
    $client->close();
    $loop->stop();
});

$loop->run();
