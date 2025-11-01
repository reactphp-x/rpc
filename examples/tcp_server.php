<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/SimpleEvaluator.php';

use React\EventLoop\Loop;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Tcp\TcpServer;
use ReactphpX\Rpc\AccessLogHandler;

/**
 * Example TCP JSON-RPC Server using NDJSON
 * 
 * Usage: php examples/tcp_server.php [port] [debug]
 * Example: php examples/tcp_server.php 8081 true
 * Then test with: echo '{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1}' | nc localhost 8081
 */

// Get port from command line argument or use default
$port = $argv[1] ?? '8081';
$debug = isset($argv[2]) && ($argv[2] === 'true' || $argv[2] === '1');

if (!is_numeric($port) || $port < 1 || $port > 65535) {
    echo "Error: Invalid port number. Must be between 1 and 65535.\n";
    exit(1);
}

$loop = Loop::get();

// Create socket server
$socket = new SocketServer("127.0.0.1:{$port}", [], $loop);

// Create access log handler
$accessLog = $debug ? new AccessLogHandler(true) : null;

// Create TCP RPC server with access log
$server = new TcpServer(new SimpleEvaluator(), $socket, $accessLog);

echo "TCP JSON-RPC Server listening on tcp://127.0.0.1:{$port}\n";
if ($debug) {
    echo "Access log: ENABLED\n";
}
echo "Try: echo '{\"jsonrpc\":\"2.0\",\"method\":\"add\",\"params\":[2,3],\"id\":1}' | nc localhost {$port}\n";

$loop->run();