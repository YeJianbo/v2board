<?php

namespace Tests\Unit;

use App\Logging\MysqlLoggerHandler;
use ReflectionMethod;
use Tests\TestCase;

class MysqlLoggerRavelRedactionTest extends TestCase
{
    public function test_ravel_secrets_and_authorization_are_redacted(): void
    {
        $handler = new MysqlLoggerHandler();
        $method = new ReflectionMethod($handler, 'sanitizeData');
        $method->setAccessible(true);
        $sanitized = $method->invoke($handler, [
            'capability_key' => str_repeat('a', 64),
            'capability-key' => str_repeat('b', 64),
            'credentials' => [['credential_id' => str_repeat('c', 32)]],
            'authorization' => 'Bearer secret',
            'server_token' => 'server-secret',
            'safe' => 'visible',
        ]);

        $this->assertSame('[FILTERED]', $sanitized['capability_key']);
        $this->assertSame('[FILTERED]', $sanitized['capability-key']);
        $this->assertSame('[FILTERED]', $sanitized['credentials']);
        $this->assertSame('[FILTERED]', $sanitized['authorization']);
        $this->assertSame('[FILTERED]', $sanitized['server_token']);
        $this->assertSame('visible', $sanitized['safe']);
    }
}
