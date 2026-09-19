<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private const DEFAULT_SESSION_TTL = 2592000;
    private const AUTH_CACHE_TTL = 300;

    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $issuedAt = time();
        $expiresAt = $issuedAt + self::sessionTtl();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => $request->ip(),
            'login_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'ua' => $request->userAgent(),
            'auth_data' => $authData
        ]);
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            if (!is_string($jwt) || $jwt === '') {
                return false;
            }

            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (empty($data['id']) || empty($data['session'])) {
                return false;
            }
            if (!self::checkSession((int) $data['id'], (string) $data['session'])) {
                Cache::forget($jwt);
                return false;
            }

            if (!Cache::has($jwt)) {
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff',
                    'banned',
                ])
                    ->find($data['id']);
                if (!$user || (int) $user->banned === 1) {
                    Cache::forget($jwt);
                    return false;
                }
                Cache::put($jwt, $user->toArray(), self::AUTH_CACHE_TTL);
            }

            $user = Cache::get($jwt);
            if (!is_array($user) || (int) ($user['banned'] ?? 0) === 1) {
                Cache::forget($jwt);
                return false;
            }
            unset($user['banned']);
            return $user;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        if (!isset($sessions[$session]) || !is_array($sessions[$session])) {
            return false;
        }

        $now = time();
        $changed = false;
        foreach ($sessions as $guid => $meta) {
            if (!is_array($meta)) {
                unset($sessions[$guid]);
                $changed = true;
                continue;
            }

            $expiresAt = (int)($meta['expires_at'] ?? 0);
            if ($expiresAt <= 0) {
                $expiresAt = (int)($meta['login_at'] ?? $now) + self::sessionTtl();
                $sessions[$guid]['expires_at'] = $expiresAt;
                $changed = true;
            }
            if ($expiresAt <= $now) {
                if (!empty($meta['auth_data'])) {
                    Cache::forget($meta['auth_data']);
                }
                unset($sessions[$guid]);
                $changed = true;
            }
        }

        if ($changed) {
            self::storeSessions($cacheKey, $sessions);
        }

        return isset($sessions[$session]);
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        return self::storeSessions($cacheKey, $sessions);
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        if (!empty($sessions[$sessionId]['auth_data'])) {
            Cache::forget($sessions[$sessionId]['auth_data']);
        }
        unset($sessions[$sessionId]);
        return self::storeSessions($cacheKey, $sessions);
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }

    public function removeAllSessions()
    {
        return $this->removeAllSession();
    }

    private static function sessionTtl(): int
    {
        return max(3600, (int)config('v2board.auth_session_ttl', self::DEFAULT_SESSION_TTL));
    }

    private static function storeSessions(string $cacheKey, array $sessions): bool
    {
        if (!$sessions) {
            Cache::forget($cacheKey);
            return true;
        }

        $now = time();
        $maxExpiresAt = $now;
        foreach ($sessions as $meta) {
            if (is_array($meta) && !empty($meta['expires_at'])) {
                $maxExpiresAt = max($maxExpiresAt, (int)$meta['expires_at']);
            }
        }

        return Cache::put($cacheKey, $sessions, max(1, $maxExpiresAt - $now));
    }
}
