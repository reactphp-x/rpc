<?php

declare(strict_types=1);

namespace ReactphpX\Rpc\Tests;

use PHPUnit\Framework\TestCase;
use ReactphpX\Rpc\AccessLogHandler;

class AccessLogHandlerTest extends TestCase
{
    public function testLogWithDisabledLogger(): void
    {
        $handler = new AccessLogHandler(false);
        
        ob_start();
        $handler->log('REQUEST', ['remote' => '127.0.0.1']);
        $output = ob_get_clean();
        
        $this->assertEmpty($output);
    }

    public function testLogWithStdoutLogger(): void
    {
        $handler = new AccessLogHandler(true);
        
        ob_start();
        $handler->log('REQUEST', ['remote' => '127.0.0.1', 'method' => 'POST', 'uri' => '/']);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('REQUEST', $output);
        $this->assertStringContainsString('127.0.0.1', $output);
        $this->assertStringContainsString('POST /', $output);
    }

    public function testLogWithCallableLogger(): void
    {
        $messages = [];
        $handler = new AccessLogHandler(function (string $message, array $context) use (&$messages) {
            $messages[] = ['message' => $message, 'context' => $context];
        });
        
        $context = ['remote' => '127.0.0.1', 'method' => 'POST', 'uri' => '/'];
        $handler->log('REQUEST', $context);
        
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('REQUEST', $messages[0]['message']);
        $this->assertEquals($context, $messages[0]['context']);
    }

    public function testLogRequestBody(): void
    {
        $handler = new AccessLogHandler(true, true, false);
        
        ob_start();
        $handler->log('REQUEST', ['request_body' => '{"jsonrpc":"2.0","method":"test"}']);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Request:', $output);
        $this->assertStringContainsString('{"jsonrpc":"2.0","method":"test"}', $output);
    }

    public function testLogRequestBodyDisabled(): void
    {
        $handler = new AccessLogHandler(true, false, false);
        
        ob_start();
        $handler->log('REQUEST', ['request_body' => '{"jsonrpc":"2.0","method":"test"}']);
        $output = ob_get_clean();
        
        $this->assertStringNotContainsString('Request:', $output);
    }

    public function testLogResponseBody(): void
    {
        $handler = new AccessLogHandler(true, false, true);
        
        ob_start();
        $handler->log('RESPONSE', ['response_body' => '{"jsonrpc":"2.0","result":5}']);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Response:', $output);
        $this->assertStringContainsString('{"jsonrpc":"2.0","result":5}', $output);
    }

    public function testLogTruncatesLongBody(): void
    {
        $handler = new AccessLogHandler(true, true, false);
        
        $longBody = str_repeat('a', 600);
        ob_start();
        $handler->log('REQUEST', ['request_body' => $longBody]);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('... (truncated)', $output);
        $this->assertLessThan(strlen($longBody), strlen($output));
    }

    public function testExtractRpcInfoFromSingleRequest(): void
    {
        $handler = new AccessLogHandler();
        
        $body = '{"jsonrpc":"2.0","method":"test","params":[1,2],"id":123}';
        $info = $handler->extractRpcInfo($body);
        
        $this->assertEquals('test', $info['rpc_method']);
        $this->assertEquals('123', $info['rpc_id']);
    }

    public function testExtractRpcInfoFromNotification(): void
    {
        $handler = new AccessLogHandler();
        
        $body = '{"jsonrpc":"2.0","method":"notify","params":[]}';
        $info = $handler->extractRpcInfo($body);
        
        $this->assertEquals('notify', $info['rpc_method']);
        $this->assertArrayNotHasKey('rpc_id', $info);
    }

    public function testExtractRpcInfoFromBatchRequest(): void
    {
        $handler = new AccessLogHandler();
        
        $body = '[{"jsonrpc":"2.0","method":"test1","id":1},{"jsonrpc":"2.0","method":"test2","id":2}]';
        $info = $handler->extractRpcInfo($body);
        
        $this->assertEquals(2, $info['rpc_batch']);
        $this->assertEquals('test1 (batch)', $info['rpc_method']);
    }

    public function testExtractRpcInfoFromInvalidJson(): void
    {
        $handler = new AccessLogHandler();
        
        $body = 'invalid json';
        $info = $handler->extractRpcInfo($body);
        
        $this->assertEmpty($info);
    }

    public function testExtractRpcResponseInfoFromSuccess(): void
    {
        $handler = new AccessLogHandler();
        
        $body = '{"jsonrpc":"2.0","result":5,"id":123}';
        $info = $handler->extractRpcResponseInfo($body);
        
        $this->assertEquals('123', $info['rpc_id']);
        $this->assertTrue($info['rpc_result']);
    }

    public function testExtractRpcResponseInfoFromError(): void
    {
        $handler = new AccessLogHandler();
        
        $body = '{"jsonrpc":"2.0","error":{"code":-32601,"message":"Method not found"},"id":123}';
        $info = $handler->extractRpcResponseInfo($body);
        
        $this->assertEquals('123', $info['rpc_id']);
        $this->assertEquals('Method not found', $info['rpc_error']);
        $this->assertEquals(-32601, $info['rpc_error_code']);
    }

    public function testExtractRpcResponseInfoFromBatch(): void
    {
        $handler = new AccessLogHandler();
        
        $body = '[{"jsonrpc":"2.0","result":5,"id":1},{"jsonrpc":"2.0","result":6,"id":2}]';
        $info = $handler->extractRpcResponseInfo($body);
        
        $this->assertEquals(2, $info['rpc_batch']);
    }

    public function testLogIncludesTimestamp(): void
    {
        $handler = new AccessLogHandler(true);
        
        ob_start();
        $handler->log('REQUEST', []);
        $output = ob_get_clean();
        
        // Check for timestamp format [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/', $output);
    }

    public function testLogIncludesDuration(): void
    {
        $handler = new AccessLogHandler(true);
        
        ob_start();
        $handler->log('REQUEST', ['duration' => 0.123]);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('123ms', $output);
    }

    public function testLogIncludesError(): void
    {
        $handler = new AccessLogHandler(true);
        
        ob_start();
        $handler->log('REQUEST', ['error' => 'Something went wrong']);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Error:', $output);
        $this->assertStringContainsString('Something went wrong', $output);
    }
}

