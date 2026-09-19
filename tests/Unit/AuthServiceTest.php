<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_removing_a_session_invalidates_its_jwt_immediately(): void
    {
        $user = new User();
        $user->id = 101;
        $user->email = 'session@example.test';
        $user->token = 'subscription-token';
        $user->is_admin = 0;
        $user->is_staff = 0;

        $service = new AuthService($user);
        $auth = $service->generateAuthData(Request::create('/login', 'POST'));
        Cache::put($auth['auth_data'], [
            'id' => 101,
            'email' => $user->email,
            'is_admin' => 0,
            'is_staff' => 0,
            'banned' => 0,
        ], 300);

        $this->assertSame(101, AuthService::decryptAuthData($auth['auth_data'])['id']);

        $sessionId = array_key_first($service->getSessions());
        $this->assertTrue($service->removeSession($sessionId));
        $this->assertFalse(AuthService::decryptAuthData($auth['auth_data']));
        $this->assertFalse(Cache::has($auth['auth_data']));
    }
}
