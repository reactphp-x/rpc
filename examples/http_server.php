<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use React\EventLoop\Loop;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\Http\HttpServer;
use ReactphpX\Rpc\AccessLogHandler;

/**
 * Example HTTP JSON-RPC Server
 * 
 * Usage: php examples/http_server.php [port] [debug]
 * Example: php examples/http_server.php 8080 true
 * Then test with: curl -X POST http://localhost:8080 -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1}'
 */

// Get port from command line argument or use default
$port = $argv[1] ?? '8080';
$debug = isset($argv[2]) && ($argv[2] === 'true' || $argv[2] === '1');

if (!is_numeric($port) || $port < 1 || $port > 65535) {
    echo "Error: Invalid port number. Must be between 1 and 65535.\n";
    exit(1);
}

// Create a simple evaluator that implements methods
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
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }
}

$loop = Loop::get();

// Create socket server
$socket = new SocketServer("127.0.0.1:{$port}", [], $loop);

// Create access log handler
$accessLog = $debug ? new AccessLogHandler(true) : null;

// Create HTTP RPC server with access log
$server = new HttpServer(new SimpleEvaluator(), $socket, $accessLog);

echo "HTTP JSON-RPC Server listening on http://127.0.0.1:{$port}\n";
if ($debug) {
    echo "Access log: ENABLED\n";
}
echo "Try: curl -X POST http://localhost:{$port} -H 'Content-Type: application/json' -d '{\"jsonrpc\":\"2.0\",\"method\":\"add\",\"params\":[2,3],\"id\":1}'\n";

$loop->run();