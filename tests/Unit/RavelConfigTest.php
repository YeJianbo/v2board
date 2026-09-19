<?php

namespace Tests\Unit;

use App\Support\RavelConfig;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RavelConfigTest extends TestCase
{
    public function test_ravel_fields_accept_valid_values(): void
    {
        $payload = $this->validPayload();
        $validator = Validator::make($payload, array_merge([
            'protocol' => 'required',
        ], RavelConfig::rules()));

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray()));
        $normalized = RavelConfig::normalize('ravel', $payload['ravel_settings']);
        $this->assertSame(65536, $normalized['chunk_max']);
        $this->assertSame(65536, $normalized['max_frame_receive']);
        $this->assertSame(7200, $normalized['claim_retention']);
    }

    /**
     * @dataProvider invalidFieldProvider
     */
    public function test_ravel_field_validation_rejects_invalid_values(
        string $field,
        $value
    ): void {
        $payload = $this->validPayload();
        data_set($payload, $field, $value);
        $validator = Validator::make($payload, array_merge([
            'protocol' => 'required',
        ], RavelConfig::rules()));

        $this->assertTrue($validator->fails(), $field . ' should fail validation');
    }

    public function invalidFieldProvider(): array
    {
        return [
            'authority with slash' => ['ravel_authority', 'example.com/path'],
            'authority invalid port' => ['ravel_authority', 'example.com:65536'],
            'authority malformed colon' => ['ravel_authority', 'example.com:abc'],
            'path without leading slash' => ['ravel_path', 'ravel'],
            'gateway too short' => ['ravel_gateway_group', '1234567'],
            'gateway non ascii' => ['ravel_gateway_group', '123456中'],
            'masquerade without URL' => ['ravel_masquerade', 'https'],
            'unsupported masquerade' => ['ravel_masquerade', 'tcp://origin.example'],
            'file masquerade must be absolute' => ['ravel_masquerade', 'file:relative'],
            'file masquerade must not have host' => ['ravel_masquerade', 'file://host/srv/cover'],
            'masquerade credentials forbidden' => ['ravel_masquerade', 'https://user@origin.example'],
            'masquerade backslash forbidden' => ['ravel_masquerade', 'https://origin.example\\cover'],
            'zero streams' => ['ravel_settings.max_streams', 0],
            'message too small' => ['ravel_settings.max_message', 4095],
            'message too large' => ['ravel_settings.max_message', 4194305],
            'frame too large' => ['ravel_settings.max_frame_receive', 1048577],
            'connection window too large' => ['ravel_settings.connection_window', 67108865],
            'stream window too large' => ['ravel_settings.stream_window', 16777217],
            'chunk too large' => ['ravel_settings.chunk_max', 1048577],
            'timeout too large' => ['ravel_settings.handshake_timeout', 61],
        ];
    }

    public function test_cross_field_limits_are_enforced(): void
    {
        $this->expectException(ValidationException::class);

        RavelConfig::normalize('ravel', [
            'max_message' => 4096,
            'chunk_max' => 8192,
            'connection_lifetime' => 7201,
            'claim_retention' => 7200,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'protocol' => 'ravel',
            'ravel_authority' => 'edge.example.com:443',
            'ravel_path' => '/ravel/v1',
            'ravel_gateway_group' => 'gw000001',
            'ravel_masquerade' => 'https://origin.example/cover',
            'ravel_settings' => [
                'stream_window' => 1048576,
                'connection_window' => 4194304,
                'max_streams' => 256,
                'max_message' => 1048576,
                'max_frame_receive' => 65536,
                'claim_capacity' => 65536,
                'chunk_min' => 1024,
                'chunk_max' => 65536,
                'chunk_min_delay' => 2,
                'chunk_max_delay' => 8,
                'handshake_timeout' => 10,
                'idle_timeout' => 300,
                'connection_lifetime' => 3600,
                'claim_retention' => 7200,
            ],
        ];
    }
}
