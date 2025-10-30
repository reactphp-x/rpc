# ReactphpX RPC

A ReactPHP-based JSON-RPC library that provides HTTP and TCP transport implementations for JSON-RPC 2.0 protocol.

## Features

- ✅ **HTTP Transport**: Full HTTP/HTTPS support using ReactPHP HTTP
- ✅ **TCP Transport**: TCP-based transport using NDJSON (Newline Delimited JSON)
- ✅ **ChildProcess Transport**: Inter-process communication using child processes
- ✅ **JSON-RPC 2.0**: Full compliance with JSON-RPC 2.0 specification
- ✅ **Async/Await**: Built on ReactPHP for non-blocking, event-driven operations
- ✅ **Type Safety**: Full PHP 8.1+ type hints and strict types
- ✅ **Access Logging**: Built-in `AccessLogHandler` for detailed request/response logging
- ✅ **Error Handling**: Comprehensive error handling with JSON-RPC 2.0 compliant error responses
- ✅ **Persistent Connections**: TCP transport supports persistent connections for better performance
- ✅ **Batch Requests**: Support for batch JSON-RPC calls on all transports

## Installation

```bash
composer require reactphp-x/rpc
```

## Requirements

- PHP 8.1 or higher
- ReactPHP Event Loop
- ReactPHP HTTP (for HTTP transport)
- ReactPHP Socket (included with ReactPHP HTTP)
- clue/ndjson-react (for TCP transport)
- hcs-llc/php-json-rpc (JSON-RPC protocol implementation)

## Quick Start

### HTTP Server Example

```php
<?php

use React\EventLoop\Loop;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\Http\HttpServer;

class MathEvaluator implements Evaluator
{
    public function evaluate($method, $arguments)
    {
        return match ($method) {
            'add' => array_sum($arguments ?? []),
            'subtract' => ($arguments[0] ?? 0) - ($arguments[1] ?? 0),
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }
}

$loop = Loop::get();
$socket = new SocketServer('127.0.0.1:8080', [], $loop);

// Optional: Enable access logging
use ReactphpX\Rpc\AccessLogHandler;
$accessLog = new AccessLogHandler(true); // true = echo to stdout

$server = new HttpServer(new MathEvaluator(), $socket, $accessLog);

echo "HTTP JSON-RPC Server listening on http://127.0.0.1:8080\n";
$loop->run();
```

### HTTP Client Example

```php
<?php

use React\EventLoop\Loop;
use React\Http\Browser;
use ReactphpX\Rpc\Http\HttpClient;

$loop = Loop::get();
$browser = new Browser($loop);

// Optional: Enable access logging
use ReactphpX\Rpc\AccessLogHandler;
$accessLog = new AccessLogHandler(true); // true = echo to stdout

$client = new HttpClient($browser, 'http://127.0.0.1:8080', $accessLog);

$client->call('add', [2, 3])
    ->then(function ($result) {
        echo "Result: " . $result . "\n"; // Output: Result: 5
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
    });

$loop->run();
```

### TCP Server Example

```php
<?php

use React\EventLoop\Loop;
use React\Socket\SocketServer;
use ReactphpX\Rpc\Evaluator;
use ReactphpX\Rpc\Tcp\TcpServer;

class MathEvaluator implements Evaluator
{
    public function evaluate($method, $arguments)
    {
        return match ($method) {
            'add' => array_sum($arguments ?? []),
            'subtract' => ($arguments[0] ?? 0) - ($arguments[1] ?? 0),
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }
}

$loop = Loop::get();
$socket = new SocketServer('127.0.0.1:8081', [], $loop);

// Optional: Enable access logging
use ReactphpX\Rpc\AccessLogHandler;
$accessLog = new AccessLogHandler(true); // true = echo to stdout

$server = new TcpServer(new MathEvaluator(), $socket, $accessLog);

echo "TCP JSON-RPC Server listening on tcp://127.0.0.1:8081\n";
$loop->run();
```

### TCP Client Example

```php
<?php

use React\EventLoop\Loop;
use React\Socket\Connector;
use ReactphpX\Rpc\Tcp\TcpClient;

$loop = Loop::get();
$connector = new Connector($loop);

// Optional: Enable access logging
use ReactphpX\Rpc\AccessLogHandler;
$accessLog = new AccessLogHandler(true); // true = echo to stdout

$client = new TcpClient('127.0.0.1:8081', $connector, $accessLog);

$client->call('add', [2, 3])
    ->then(function ($result) {
        echo "Result: " . $result . "\n"; // Output: Result: 5
    })
    ->catch(function ($error) {
        echo "Error: " . $error->getMessage() . "\n";
    });

$loop->run();
```

## API Reference

### HttpServer

Creates an HTTP-based JSON-RPC server.

```php
new HttpServer(
    Evaluator $evaluator, 
    SocketServer $socketServer, 
    ?AccessLogHandler $accessLog = null
)
```

**Parameters:**
- `$evaluator`: An implementation of `Evaluator` interface that handles method calls
- `$socketServer`: ReactPHP SocketServer instance
- `$accessLog`: Optional `AccessLogHandler` instance for logging requests/responses

**Features:**
- Handles POST requests with JSON-RPC payloads
- Returns 204 No Content for notifications
- Returns 200 OK with JSON-RPC response for queries
- Returns 405 Method Not Allowed for non-POST requests
- Comprehensive error handling with try-catch around JSON-RPC processing

### HttpClient

Creates an HTTP-based JSON-RPC client.

```php
new HttpClient(
    Browser $browser, 
    string $url, 
    ?AccessLogHandler $accessLog = null
)
```

**Parameters:**
- `$browser`: ReactPHP Browser instance
- `$url`: Server URL (e.g., `'http://127.0.0.1:8080'`)
- `$accessLog`: Optional `AccessLogHandler` instance for logging requests/responses

**Methods:**
- `call(string $method, ?array $arguments = null): PromiseInterface` - Call a JSON-RPC method and get result
- `notify(string $method, ?array $arguments = null): PromiseInterface` - Send a notification (no response expected)
- `batch(array $calls): PromiseInterface` - Call multiple methods in batch. Each call is an array: `[method, arguments, id?]` where `id` is optional

### TcpServer

Creates a TCP-based JSON-RPC server using NDJSON.

```php
new TcpServer(
    Evaluator $evaluator, 
    SocketServer $socketServer, 
    ?AccessLogHandler $accessLog = null
)
```

**Parameters:**
- `$evaluator`: An implementation of `Evaluator` interface that handles method calls
- `$socketServer`: ReactPHP SocketServer instance
- `$accessLog`: Optional `AccessLogHandler` instance for logging requests/responses

**Methods:**
- `close(): void` - Close the server
- `getSocketServer(): SocketServer` - Get the underlying socket server instance

**Features:**
- Uses NDJSON (Newline Delimited JSON) for streaming
- Supports persistent connections
- Handles multiple concurrent connections
- Comprehensive error handling with try-catch around JSON-RPC processing

### TcpClient

Creates a TCP-based JSON-RPC client using NDJSON.

```php
new TcpClient(
    string $uri, 
    ?Connector $connector = null, 
    ?AccessLogHandler $accessLog = null
)
```

**Parameters:**
- `$uri`: Server URI (e.g., `'127.0.0.1:8081'` or `'tcp://127.0.0.1:8081'`)
- `$connector`: Optional ReactPHP Connector instance (defaults to new Connector)
- `$accessLog`: Optional `AccessLogHandler` instance for logging requests/responses

**Methods:**
- `connect(): PromiseInterface` - Connect to the server (automatically called by `call()`, `notify()`, and `batch()`)
- `call(string $method, ?array $arguments = null): PromiseInterface` - Call a JSON-RPC method and get result
- `notify(string $method, ?array $arguments = null): PromiseInterface` - Send a notification (no response expected)
- `batch(array $calls, float $timeout = 5.0): PromiseInterface` - Call multiple methods in batch. Each call is an array: `[method, arguments, id?]` where `id` is optional. `$timeout` specifies the timeout in seconds (default: 5.0)
- `close(): void` - Close the connection

**Features:**
- Automatic connection management (connects on first use)
- Prevents duplicate connection attempts
- Reuses persistent connection for multiple requests
- Rejects pending requests if connection is lost

### AccessLogHandler

Provides detailed logging for JSON-RPC requests and responses.

```php
new AccessLogHandler(
    bool|callable $logger = true,
    bool $logRequestBody = true,
    bool $logResponseBody = true
)
```

**Parameters:**
- `$logger`: Logger instance or callback. If `true`, logs to stdout. If `false`, logging is disabled. If callable, function will be called with `(string $message, array $context)`.
- `$logRequestBody`: Whether to log request body (default: `true`)
- `$logResponseBody`: Whether to log response body (default: `true`)

**Usage Example:**

```php
use ReactphpX\Rpc\AccessLogHandler;

// Log to stdout
$accessLog = new AccessLogHandler(true);

// Log to custom callback
$accessLog = new AccessLogHandler(function (string $message, array $context) {
    file_put_contents('rpc.log', $message, FILE_APPEND);
});

// Disable logging
$accessLog = new AccessLogHandler(false);

// Log without request/response bodies
$accessLog = new AccessLogHandler(true, false, false);
```

**Log Format:**

The access log includes:
- Timestamp
- Direction (REQUEST, RESPONSE, NOTIFICATION, BATCH RESPONSE)
- Remote address (for servers)
- HTTP method and URI (for HTTP transport)
- JSON-RPC method name
- JSON-RPC request ID
- HTTP status code
- Processing duration (in milliseconds)
- Request body (if enabled)
- Response body (if enabled)
- Error information (if any)

### Evaluator Interface

All servers require an `Evaluator` implementation:

```php
interface Evaluator extends \Datto\JsonRpc\Evaluator
{
    /**
     * Evaluate a JSON-RPC method call
     *
     * @param string $method The method name to call
     * @param array|null $arguments The arguments to pass to the method
     * @return mixed The result of the method call
     * @throws \Exception If the method call fails
     */
    public function evaluate($method, $arguments);
}
```

**Note:** This is an alias for `\Datto\JsonRpc\Evaluator` from the `hcs-llc/php-json-rpc` library.

## Examples

See the `examples/` directory for complete working examples:

- `examples/http_server.php` - HTTP server example
- `examples/http_client.php` - HTTP client example
- `examples/tcp_server.php` - TCP server example
- `examples/tcp_client.php` - TCP client example

Run examples:

```bash
# Terminal 1: Start HTTP server (port 8080, debug enabled)
php examples/http_server.php 8080 true

# Terminal 2: Run HTTP client (connect to localhost:8080, debug enabled)
php examples/http_client.php 8080 localhost true

# Terminal 1: Start TCP server (port 8081, debug enabled)
php examples/tcp_server.php 8081 true

# Terminal 2: Run TCP client (connect to localhost:8081, debug enabled)
php examples/tcp_client.php 8081 localhost true
```

**Example Parameters:**
- Server examples accept: `[port] [debug]`
  - `port`: Port number (default: 8080 for HTTP, 8081 for TCP)
  - `debug`: Enable access logging (`true` or `1` to enable, omit or `false` to disable)
- Client examples accept: `[port] [host] [debug]`
  - `port`: Server port (default: 8080 for HTTP, 8081 for TCP)
  - `host`: Server hostname (default: `localhost`)
  - `debug`: Enable access logging (`true` or `1` to enable, omit or `false` to disable)

**Testing with curl:**

```bash
# Test HTTP server
curl -X POST http://localhost:8080 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1}'

# Test batch request
curl -X POST http://localhost:8080 \
  -H "Content-Type: application/json" \
  -d '[{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1},{"jsonrpc":"2.0","method":"subtract","params":[10,4],"id":2}]'
```

## Transport Differences

### HTTP Transport

**Characteristics:**
- Uses standard HTTP POST requests
- Supports JSON-RPC 2.0 batch requests
- Each request/response is a single HTTP request/response
- Stateless (each HTTP request is independent)
- Easy to integrate with web servers, load balancers, and proxies

**Best for:**
- Web APIs and REST-like interfaces
- Browser-based clients
- Cross-language interoperability
- Environments where HTTP is standard

**Request Format:**
```http
POST / HTTP/1.1
Host: localhost:8080
Content-Type: application/json

{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1}
```

**Response Format:**
```http
HTTP/1.1 200 OK
Content-Type: application/json

{"jsonrpc":"2.0","result":5,"id":1}
```

### TCP Transport

**Characteristics:**
- Uses TCP sockets with NDJSON (Newline Delimited JSON)
- Supports persistent connections
- Each JSON-RPC message is separated by a newline (`\n`)
- Stateful (connection persists across requests)
- Lower overhead than HTTP

**Best for:**
- High-performance scenarios
- Long-lived connections
- Internal service communication
- Real-time applications

**Message Format:**
Each JSON-RPC message is sent as a single line:

```
{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1}\n
{"jsonrpc":"2.0","result":5,"id":1}\n
```

**Connection Management:**
- TCP client automatically connects on first use
- Connection is reused for subsequent requests
- If connection is lost, pending requests are rejected
- Call `close()` to explicitly close the connection

**Performance Comparison:**

- **HTTP**: Higher overhead per request (HTTP headers, etc.)
- **TCP**: Lower overhead, better for high-frequency requests
- **HTTP**: Better for infrequent requests and web integration
- **TCP**: Better for persistent connections and internal services

## Error Handling

### Client-Side Error Handling

JSON-RPC errors follow the standard JSON-RPC 2.0 error format:

```php
$client->call('nonexistent', [])
    ->catch(function ($error) {
        // $error is a RuntimeException with the error message and code
        echo "Error Code: " . $error->getCode() . "\n";
        echo "Error Message: " . $error->getMessage() . "\n";
    });
```

### Server-Side Error Handling

Servers automatically handle errors during JSON-RPC processing:

- **HTTP Server**: Catches exceptions during `$rpcServer->reply()` and returns a 500 Internal Server Error with a JSON-RPC error response
- **TCP Server**: Catches exceptions during `$rpcServer->rawReply()` and sends a JSON-RPC error response

**Error Response Format:**

```json
{
  "jsonrpc": "2.0",
  "id": null,
  "error": {
    "code": -32603,
    "message": "Internal error",
    "data": "Error message from exception"
  }
}
```

**Standard JSON-RPC Error Codes:**

- `-32700`: Parse error
- `-32600`: Invalid Request
- `-32601`: Method not found
- `-32602`: Invalid params
- `-32603`: Internal error
- `-32000` to `-32099`: Server error (reserved for implementation-defined server errors)

**Evaluator Exception Handling:**

Your `Evaluator` implementation can throw exceptions with error codes:

```php
class MathEvaluator implements Evaluator
{
    public function evaluate($method, $arguments)
    {
        return match ($method) {
            'divide' => $this->divide($arguments),
            default => throw new \RuntimeException("Method '{$method}' not found", -32601),
        };
    }
    
    private function divide($arguments)
    {
        $a = $arguments[0] ?? 0;
        $b = $arguments[1] ?? 1;
        
        if ($b == 0) {
            throw new \RuntimeException("Division by zero", -32000);
        }
        
        return $a / $b;
    }
}
```

## Notifications

Notifications are requests that don't expect a response:

```php
// Client
$client->notify('log', ['message' => 'Something happened']);

// Server automatically handles notifications (no response sent)
```

## Batch Requests

HTTP client supports batch requests (multiple JSON-RPC calls in a single HTTP request):

```php
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;

$client->batch([
    ['add', [2, 3]],                    // Auto-generated ID
    ['subtract', [10, 4], 100],         // Custom ID: 100
    ['multiply', [5, 6]],               // Auto-generated ID
])
    ->then(function ($responses) {
        // $responses is an array of Response objects
        foreach ($responses as $index => $response) {
            if ($response instanceof ResultResponse) {
                echo "[$index] Result: " . json_encode($response->getValue()) . "\n";
            } elseif ($response instanceof ErrorResponse) {
                echo "[$index] Error: " . $response->getMessage() . " (code: " . $response->getCode() . ")\n";
            }
        }
    })
    ->catch(function ($error) {
        // Handle batch request failure
        echo "Batch error: " . $error->getMessage() . "\n";
    });
```

**Batch Request Format:**

Each call in the batch array can be:
- `[method, arguments]` - Uses auto-generated ID
- `[method, arguments, id]` - Uses the specified ID

**TCP Batch Requests:**

TCP client also supports batch requests, but uses NDJSON format (each request/response is a separate line):

```php
use Datto\JsonRpc\Responses\ErrorResponse;
use Datto\JsonRpc\Responses\ResultResponse;

// Default timeout (5.0 seconds)
$client->batch([
    ['add', [2, 3]],
    ['subtract', [10, 4], 100],  // Custom ID: 100
    ['multiply', [5, 6]],
])
    ->then(function ($responses) {
        // $responses is an array of Response objects
        foreach ($responses as $index => $response) {
            if ($response instanceof ResultResponse) {
                echo "[$index] Result: " . json_encode($response->getValue()) . "\n";
            } elseif ($response instanceof ErrorResponse) {
                echo "[$index] Error: " . $response->getMessage() . " (code: " . $response->getCode() . ")\n";
            }
        }
    })
    ->catch(function ($error) {
        // Handle batch request failure
        echo "Batch error: " . $error->getMessage() . "\n";
    });

// Custom timeout (10 seconds)
$client->batch([
    ['add', [2, 3]],
    ['subtract', [10, 4]],
], 10.0)
    ->then(function ($responses) {
        // Handle responses
    });

**Difference between HTTP and TCP batch:**
- **HTTP**: Sends all requests in a single JSON array, receives a single JSON array response
- **TCP**: Sends each request as a separate NDJSON line, receives each response as a separate NDJSON line

## License

MIT License - see LICENSE file for details.

## Advanced Usage

### Custom Logger

You can use a custom logger by passing a callable to `AccessLogHandler`:

```php
use ReactphpX\Rpc\AccessLogHandler;

// Log to file
$accessLog = new AccessLogHandler(function (string $message, array $context) {
    $logFile = __DIR__ . '/rpc.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
});

// Log to PSR-3 logger (via adapter)
$psrLogger = new \Monolog\Logger('rpc');
$accessLog = new AccessLogHandler(function (string $message, array $context) use ($psrLogger) {
    $psrLogger->info($message, $context);
});

// Log without request/response bodies
$accessLog = new AccessLogHandler(true, false, false);
```

### Connection Management (TCP)

```php
// Explicit connection
$client->connect()
    ->then(function () {
        echo "Connected!\n";
        return $client->call('ping', []);
    })
    ->then(function ($result) {
        echo "Result: $result\n";
        $client->close(); // Close when done
    });

// Auto-connection (default)
$client->call('ping', [])  // Automatically connects
    ->then(function ($result) {
        echo "Result: $result\n";
    });
```

### Error Recovery

```php
// Retry logic
function callWithRetry($client, $method, $arguments, $maxRetries = 3)
{
    $attempt = 0;
    
    $tryCall = function () use (&$tryCall, $client, $method, $arguments, $maxRetries, &$attempt) {
        $attempt++;
        
        return $client->call($method, $arguments)
            ->catch(function ($error) use (&$tryCall, $maxRetries, &$attempt) {
                if ($attempt >= $maxRetries) {
                    throw $error;
                }
                
                echo "Attempt $attempt failed, retrying...\n";
                return $tryCall();
            });
    };
    
    return $tryCall();
}

$promise = callWithRetry($client, 'add', [2, 3]);
```

## Architecture

This library provides a **transport layer** implementation for JSON-RPC 2.0:

- **Protocol Layer**: Uses `hcs-llc/php-json-rpc` for JSON-RPC protocol handling
- **Transport Layer**: Custom implementation using ReactPHP components
  - HTTP: `react/http` for HTTP transport
  - TCP: `react/socket` + `clue/ndjson-react` for TCP transport with NDJSON streaming
- **Logging**: `AccessLogHandler` for structured logging
- **Error Handling**: Comprehensive try-catch blocks ensure robust error handling

## Testing

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run tests with detailed output
vendor/bin/phpunit --testdox

# Run specific test file
vendor/bin/phpunit tests/AccessLogHandlerTest.php
```

### Test Coverage

The test suite includes:

- ✅ **AccessLogHandler**: Tests for logging, JSON-RPC info extraction, and formatting
- ✅ **HttpServer**: Tests for server creation, access log integration, and HTTP server instance retrieval
- ✅ **HttpClient**: Tests for client creation and access log integration
- ✅ **TcpServer**: Tests for server creation, access log integration, closing, and socket server retrieval
- ✅ **TcpClient**: Tests for client creation, connector, and access log integration
- ✅ **Evaluator**: Tests for interface implementation and inheritance

### Running Examples

```bash
# Terminal 1: Start server
php examples/http_server.php 8080 true

# Terminal 2: Test with client
php examples/http_client.php 8080 localhost true

# Terminal 3: Test with curl
curl -X POST http://localhost:8080 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"add","params":[2,3],"id":1}'
```

## License

MIT License - see LICENSE file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## See Also

- [ReactPHP](https://reactphp.org/)
- [ReactPHP HTTP](https://github.com/reactphp/http)
- [ReactPHP Socket](https://github.com/reactphp/socket)
- [JSON-RPC 2.0 Specification](https://www.jsonrpc.org/specification)
- [hcs-llc/php-json-rpc](https://github.com/hcs-llc/php-json-rpc)
- [clue/ndjson-react](https://github.com/clue/reactphp-ndjson)
