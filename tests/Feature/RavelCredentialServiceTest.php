<?php

namespace Tests\Feature;

use App\Models\RavelCredential;
use App\Models\ServerV2node;
use App\Models\User;
use App\Services\RavelCredentialService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RavelCredentialServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'), 'Ravel fixtures require an isolated SQLite database');
        Cache::flush();
        $this->createSchema();
    }

    public function test_capability_key_is_encrypted_at_rest_and_hidden_from_json(): void
    {
        [$server, $user] = $this->fixtures();
        $service = new RavelCredentialService();
        $credential = $service->rotate($server, $user, 0, 1700000000);
        $payload = $service->toSecretPayload($credential);
        $raw = DB::table('v2_ravel_credential')
            ->where('id', $credential->id)
            ->value('capability_key_ciphertext');

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $credential->credential_id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $payload['capability_key']);
        $this->assertNotSame($payload['capability_key'], $raw);
        $this->assertSame($payload['capability_key'], Crypt::decryptString($raw));
        $this->assertArrayNotHasKey('capability_key_ciphertext', $credential->toArray());
        $this->assertStringNotContainsString($raw, $credential->toJson());
    }

    public function test_rotation_increments_version_and_keeps_overlap(): void
    {
        [$server, $user] = $this->fixtures();
        $service = new RavelCredentialService();
        $first = $service->rotate($server, $user, 0, 1700000000);
        $second = $service->rotate($server, $user, 600, 1700000100);
        $first->refresh();

        $this->assertSame(1, $first->key_version);
        $this->assertSame(2, $second->key_version);
        $this->assertNotSame($first->credential_id, $second->credential_id);
        $this->assertSame(1700000100, $first->superseded_at);
        $this->assertSame(1700000700, $first->not_after);

        $active = $service->activeForServer($server, 1700000200);
        $this->assertCount(2, $active);
        $this->assertSame([2, 1], $active->pluck('key_version')->all());
    }

    public function test_gateway_snapshot_keeps_overlap_but_subscription_uses_latest(): void
    {
        [$server, $user] = $this->fixtures();
        $service = new RavelCredentialService();
        $service->rotate($server, $user, 0, 1700000000);
        $service->rotate($server, $user, 600, 1700000100);

        $snapshot = $service->credentialsForServerUsers(
            $server,
            collect([$user]),
            1700000200
        );
        $subscription = $service->credentialsForSubscription(
            $server,
            $user,
            1700000200
        );

        $this->assertSame([2, 1], array_column($snapshot[$user->id], 'key_version'));
        $this->assertCount(1, $subscription);
        $this->assertSame(2, $subscription[0]['key_version']);
    }

    public function test_not_after_is_capped_by_user_expiration(): void
    {
        [$server, $user] = $this->fixtures(1700003600);
        $credential = (new RavelCredentialService())
            ->rotate($server, $user, 0, 1700000000);

        $this->assertSame(1699999700, $credential->not_before);
        $this->assertSame(1700003600, $credential->not_after);
        $this->assertSame(0, $credential->policy_id);
    }

    public function test_revoke_user_generates_new_version_for_active_user(): void
    {
        [$server, $user] = $this->fixtures();
        $service = new RavelCredentialService();
        $first = $service->rotate($server, $user, 0, 1700000000);
        $rotated = $service->revokeUser($user, true, 1700000200);
        $first->refresh();

        $this->assertSame(1700000200, $first->revoked_at);
        $this->assertCount(1, $rotated);
        $this->assertSame(2, $rotated[0]->key_version);
        $this->assertNull($rotated[0]->revoked_at);
    }

    private function fixtures(int $expiredAt = 1800000000): array
    {
        $server = ServerV2node::create([
            'group_id' => [1],
            'name' => 'Ravel Edge',
            'host' => 'edge.example.com',
            'port' => '443',
            'server_port' => 443,
            'protocol' => 'ravel',
            'ravel_gateway_group' => 'gw000001',
        ]);
        $user = User::create([
            'email' => 'ravel@example.com',
            'group_id' => 1,
            'expired_at' => $expiredAt,
            'banned' => 0,
        ]);

        return [$server, $user];
    }

    private function createSchema(): void
    {
        Schema::dropAllTables();
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('uuid')->nullable();
            $table->integer('group_id')->nullable();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('expired_at')->nullable();
            $table->boolean('banned')->default(false);
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_server_v2node', function (Blueprint $table) {
            $table->increments('id');
            $table->text('group_id');
            $table->text('route_id')->nullable();
            $table->string('name');
            $table->integer('parent_id')->nullable();
            $table->string('host');
            $table->string('listen_ip')->default('0.0.0.0');
            $table->string('port');
            $table->integer('server_port');
            $table->text('tags')->nullable();
            $table->string('rate')->default('1');
            $table->boolean('show')->default(true);
            $table->integer('sort')->nullable();
            $table->string('protocol');
            $table->boolean('tls')->default(false);
            $table->text('tls_settings')->nullable();
            $table->string('flow')->nullable();
            $table->string('network')->default('tcp');
            $table->text('network_settings')->nullable();
            $table->string('encryption')->nullable();
            $table->text('encryption_settings')->nullable();
            $table->boolean('disable_sni')->default(false);
            $table->string('udp_relay_mode')->nullable();
            $table->boolean('zero_rtt_handshake')->default(false);
            $table->string('congestion_control')->nullable();
            $table->string('cipher')->nullable();
            $table->integer('up_mbps')->default(0);
            $table->integer('down_mbps')->default(0);
            $table->string('obfs')->nullable();
            $table->string('obfs_password')->nullable();
            $table->text('padding_scheme')->nullable();
            $table->string('ravel_authority')->nullable();
            $table->string('ravel_path', 2048)->nullable();
            $table->char('ravel_gateway_group', 8)->nullable();
            $table->string('ravel_masquerade', 2048)->nullable();
            $table->text('ravel_settings')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_ravel_credential', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id');
            $table->char('credential_id', 32)->unique();
            $table->text('capability_key_ciphertext');
            $table->unsignedInteger('key_version');
            $table->unsignedBigInteger('policy_id')->default(0);
            $table->char('gateway_group', 8);
            $table->unsignedBigInteger('not_before');
            $table->unsignedBigInteger('not_after');
            $table->unsignedBigInteger('revoked_at')->nullable();
            $table->unsignedBigInteger('superseded_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['server_id', 'user_id', 'key_version']);
        });
    }
}
