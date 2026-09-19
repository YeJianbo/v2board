<?php

namespace App\Services;

use App\Models\RavelCredential;
use App\Models\ServerV2node;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RavelCredentialService
{
    public const DEFAULT_LIFETIME_SECONDS = 2592000;
    public const NOT_BEFORE_SKEW_SECONDS = 300;
    public const DEFAULT_OVERLAP_SECONDS = 86400;
    public const ROTATE_BEFORE_SECONDS = 604800;

    public function activeForServer($server, ?int $now = null): Collection
    {
        $serverId = $server instanceof ServerV2node ? (int) $server->id : (int) $server;
        $now = $now ?? time();

        return RavelCredential::query()
            ->where('server_id', $serverId)
            ->whereNull('revoked_at')
            ->where('not_before', '<=', $now)
            ->where('not_after', '>', $now)
            ->orderBy('user_id')
            ->orderByDesc('key_version')
            ->get();
    }

    public function latestForSubscription(
        ServerV2node $server,
        User $user,
        ?int $now = null
    ): RavelCredential {
        $now = $now ?? time();
        $latest = RavelCredential::query()
            ->where('server_id', $server->id)
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('not_before', '<=', $now)
            ->where('not_after', '>', $now)
            ->orderByDesc('key_version')
            ->first();

        $userExpiresAt = (int) ($user->expired_at ?: 0);
        $userOutlivesRotationWindow = $userExpiresAt === 0
            || $userExpiresAt > $now + self::ROTATE_BEFORE_SECONDS;
        if (
            !$latest
            || (
                $userOutlivesRotationWindow
                && (int) $latest->not_after <= $now + self::ROTATE_BEFORE_SECONDS
            )
        ) {
            return $this->rotate($server, $user, self::DEFAULT_OVERLAP_SECONDS, $now);
        }

        return $latest;
    }

    public function rotate(
        ServerV2node $server,
        User $user,
        int $overlapSeconds = self::DEFAULT_OVERLAP_SECONDS,
        ?int $now = null
    ): RavelCredential {
        $now = $now ?? time();
        $overlapSeconds = max(0, $overlapSeconds);

        return DB::transaction(function () use ($server, $user, $overlapSeconds, $now) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedServer = ServerV2node::query()
                ->whereKey($server->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedServer->protocol !== 'ravel') {
                throw new RuntimeException('Ravel credential can only be issued for Ravel nodes.');
            }

            $gatewayGroup = (string) $lockedServer->ravel_gateway_group;
            if (!preg_match('/\A[\x21-\x7E]{8}\z/', $gatewayGroup)) {
                throw new RuntimeException('Ravel gateway group is invalid.');
            }

            $notAfter = $now + self::DEFAULT_LIFETIME_SECONDS;
            $userExpiresAt = (int) ($lockedUser->expired_at ?: 0);
            if ($userExpiresAt > 0) {
                $notAfter = min($notAfter, $userExpiresAt);
            }
            if ($notAfter <= $now) {
                throw new RuntimeException('Cannot issue a Ravel credential for an expired user.');
            }

            $latestVersion = (int) RavelCredential::query()
                ->where('server_id', $lockedServer->id)
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->max('key_version');

            $overlapUntil = $now + $overlapSeconds;
            RavelCredential::query()
                ->where('server_id', $lockedServer->id)
                ->where('user_id', $lockedUser->id)
                ->whereNull('revoked_at')
                ->where('not_after', '>', $now)
                ->get()
                ->each(function (RavelCredential $credential) use ($now, $overlapUntil) {
                    $credential->superseded_at = $credential->superseded_at ?: $now;
                    $credential->not_after = min((int) $credential->not_after, $overlapUntil);
                    $credential->save();
                });

            $capabilityKey = bin2hex(random_bytes(32));

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $credentialId = bin2hex(random_bytes(16));
                if (!RavelCredential::where('credential_id', $credentialId)->exists()) {
                    return RavelCredential::create([
                        'server_id' => (int) $lockedServer->id,
                        'user_id' => (int) $lockedUser->id,
                        'credential_id' => $credentialId,
                        'capability_key_ciphertext' => Crypt::encryptString($capabilityKey),
                        'key_version' => $latestVersion + 1,
                        'policy_id' => 0,
                        'gateway_group' => $gatewayGroup,
                        'not_before' => max(0, $now - self::NOT_BEFORE_SKEW_SECONDS),
                        'not_after' => $notAfter,
                        'revoked_at' => null,
                        'superseded_at' => null,
                    ]);
                }
            }

            throw new RuntimeException('Unable to allocate a unique Ravel credential ID.');
        }, 3);
    }

    public function revokeUser(User $user, bool $rotateExisting = false, ?int $now = null): array
    {
        $now = $now ?? time();
        return DB::transaction(function () use ($user, $rotateExisting, $now) {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $serverIds = RavelCredential::query()
                ->where('user_id', $lockedUser->id)
                ->distinct()
                ->pluck('server_id')
                ->map(function ($serverId) {
                    return (int) $serverId;
                })
                ->all();

            RavelCredential::query()
                ->where('user_id', $lockedUser->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'superseded_at' => DB::raw(
                        'COALESCE(superseded_at, ' . (int) $now . ')'
                    ),
                    'updated_at' => $now,
                ]);

            $rotated = [];
            $userExpiresAt = (int) ($lockedUser->expired_at ?: 0);
            $canIssue = !(bool) $lockedUser->banned
                && ($userExpiresAt === 0 || $userExpiresAt > $now);
            if ($rotateExisting && $canIssue) {
                $servers = ServerV2node::query()
                    ->whereIn('id', $serverIds)
                    ->where('protocol', 'ravel')
                    ->get();
                foreach ($servers as $server) {
                    $rotated[] = $this->rotate($server, $lockedUser, 0, $now);
                }
            }

            return $rotated;
        }, 3);
    }

    public function credentialsForSubscription(
        ServerV2node $server,
        User $user,
        ?int $now = null
    ): array
    {
        return [
            $this->toSecretPayload(
                $this->latestForSubscription($server, $user, $now)
            ),
        ];
    }

    public function userPayloads(ServerV2node $server, iterable $users): array
    {
        $users = collect($users)->values();
        $credentials = $this->credentialsForServerUsers($server, $users);
        return $users->map(function (User $user) use ($credentials) {
            $payload = array_filter($user->toArray(), static function ($value) {
                return $value !== null;
            });
            $payload['ravel_credentials'] = $credentials[(int) $user->id] ?? [];
            return $payload;
        })->all();
    }

    /**
     * Build the complete active credential snapshot consumed by a Ravel
     * gateway. Active overlap versions are retained so an in-flight client
     * rotation does not cause an avoidable outage.
     *
     * @param iterable<User> $users
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function credentialsForServerUsers(
        ServerV2node $server,
        iterable $users,
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $usersById = collect($users)
            ->filter(function ($user) {
                return $user instanceof User && (int) $user->id > 0;
            })
            ->keyBy(function (User $user) {
                return (int) $user->id;
            });
        $userIds = $usersById->keys()->map(function ($userId) {
            return (int) $userId;
        })->values()->all();

        if (!$userIds) {
            return [];
        }

        $states = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'expired_at', 'banned'])
            ->keyBy('id');
        $active = $this->activeForUsers($server, $userIds, $now)
            ->groupBy('user_id');
        $rotated = false;

        foreach ($usersById as $userId => $user) {
            $state = $states->get((int) $userId);
            if (!$state) {
                continue;
            }
            $expiredAt = (int) ($state->expired_at ?: 0);
            if ((bool) $state->banned || ($expiredAt > 0 && $expiredAt <= $now)) {
                continue;
            }

            $latest = $active
                ->get((int) $userId, collect())
                ->sortByDesc('key_version')
                ->first();
            $outlivesRotationWindow = $expiredAt === 0
                || $expiredAt > $now + self::ROTATE_BEFORE_SECONDS;
            if (
                !$latest
                || (
                    $outlivesRotationWindow
                    && (int) $latest->not_after <= $now + self::ROTATE_BEFORE_SECONDS
                )
            ) {
                $this->rotate($server, $user, self::DEFAULT_OVERLAP_SECONDS, $now);
                $rotated = true;
            }
        }

        if ($rotated) {
            $active = $this->activeForUsers($server, $userIds, $now)
                ->groupBy('user_id');
        }

        $payloads = [];
        foreach ($userIds as $userId) {
            $payloads[$userId] = $active
                ->get($userId, collect())
                ->sortByDesc('key_version')
                ->map(function (RavelCredential $credential) {
                    return $this->toSecretPayload($credential);
                })
                ->values()
                ->all();
        }

        return $payloads;
    }

    private function activeForUsers(
        ServerV2node $server,
        array $userIds,
        int $now
    ): Collection {
        return RavelCredential::query()
            ->where('server_id', $server->id)
            ->whereIn('user_id', $userIds)
            ->whereNull('revoked_at')
            ->where('not_before', '<=', $now)
            ->where('not_after', '>', $now)
            ->orderBy('user_id')
            ->orderByDesc('key_version')
            ->get()
        ;
    }

    public function toSecretPayload(RavelCredential $credential): array
    {
        return [
            'credential_id' => (string) $credential->credential_id,
            'capability_key' => Crypt::decryptString(
                (string) $credential->getRawOriginal('capability_key_ciphertext')
            ),
            'key_version' => (int) $credential->key_version,
            'policy_id' => 0,
            'gateway_group' => (string) $credential->gateway_group,
            'not_before' => (int) $credential->not_before,
            'not_after' => (int) $credential->not_after,
        ];
    }
}
