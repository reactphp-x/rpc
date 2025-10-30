<?php

declare(strict_types=1);

use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
} else {
    require __DIR__ . '/../../../../../vendor/autoload.php';
}

use Datto\JsonRpc\Server as JsonRpcServer;
use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use function React\Async\async;

// Get evaluator class name from command line argument
$evaluatorClass = $argv[1] ?? null;
$evaluatorFile = $argv[2] ?? null;

// If evaluator file is provided, require it first
if ($evaluatorFile !== null && file_exists($evaluatorFile)) {
    require $evaluatorFile;
}

if (!$evaluatorClass || !class_exists($evaluatorClass)) {
    fwrite(STDERR, "Error: Invalid or missing evaluator class name\n");
    exit(1);
}

// Instantiate the evaluator class
try {
    $evaluator = new $evaluatorClass();
    if (!($evaluator instanceof \ReactphpX\Rpc\Evaluator)) {
        fwrite(STDERR, "Error: Evaluator class must implement ReactphpX\\Rpc\\Evaluator\n");
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: Failed to instantiate evaluator class: " . $e->getMessage() . "\n");
    exit(1);
}

// Create JSON-RPC server with evaluator
$rpcServer = new JsonRpcServer($evaluator);

// Create streams for STDIN (read from parent) and STDERR (write to parent)
$stdin = new ReadableResourceStream(STDIN);
$stderr = new WritableResourceStream(STDERR);

// Create decoder and encoder for NDJSON
$decoder = new Decoder($stdin, true);
$encoder = new Encoder($stderr);

// Process JSON-RPC requests
$decoder->on('data', async(function (array $data) use ($rpcServer, $encoder) {

    try {
        $response = $rpcServer->rawReply($data);
        
        // Only send response for queries (notifications return null)
        if ($response !== null) {
            $encoder->write($response);
        }
    } catch (\Throwable $e) {
        // Send error response
        $encoder->write([
            'jsonrpc' => '2.0',
            'id' => $data['id'] ?? null,
            'error' => [
                'code' => -32603,
                'message' => 'Internal error',
                'data' => $e->getMessage()
            ]
        ]);
    }
}));

// Handle stream errors
$stdin->on('error', function (\Throwable $error) {
    // Handle error
});

$stderr->on('error', function (\Throwable $error) {
    // Handle error
});

// Run event loop
\React\EventLoop\Loop::run();

