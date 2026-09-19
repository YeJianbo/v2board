# Ravel control-plane v1

Current implementation: 2026-09-12. See `F:\Code\v2board\ravel-go\docs\usable.md` for the tested local deployment and supported scope.


Ravel remains an outer `v2node` node. The protocol discriminator is:

```json
{
  "type": "v2node",
  "protocol": "ravel"
}
```

## Node settings

`v2_server_v2node` stores these non-secret fields:

- `ravel_authority`
- `ravel_path`
- `ravel_gateway_group`
- `ravel_masquerade`
- `ravel_settings`

`ravel_masquerade` stores the complete cover origin URL. Supported forms are
`file:///absolute/path`, `http://origin.example/base`, and
`https://origin.example/base`. User information and fragments are rejected.

`ravel_settings` v1 supports:

| Field | Minimum | Maximum | Default |
| --- | ---: | ---: | ---: |
| `stream_window` | 65536 | 16777216 | 1048576 |
| `connection_window` | 65536 | 67108864 | 4194304 |
| `max_streams` | 1 | 4096 | 256 |
| `max_message` | 4096 | 4194304 | 1048576 |
| `max_frame_receive` | 9 | 1048576 | 65536 |
| `claim_capacity` | 1 | 1048576 | 65536 |
| `chunk_min` | 256 | 1048576 | 1024 |
| `chunk_max` | 256 | 1048576 | 65536 |
| `chunk_min_delay` | 1 | 1000 | 2 |
| `chunk_max_delay` | 1 | 1000 | 8 |
| `handshake_timeout` | 1 | 60 | 10 |
| `idle_timeout` | 5 | 86400 | 300 |
| `connection_lifetime` | 60 | 86400 | 3600 |
| `claim_retention` | 60 | 604800 | 7200 |

Additional constraints:

- `stream_window <= connection_window`
- `chunk_min <= chunk_max`
- `chunk_max <= max_message`
- `chunk_min_delay <= chunk_max_delay`
- `connection_lifetime <= claim_retention`

## Credential lifecycle

`v2_ravel_credential` stores one encrypted capability key per credential version.

- `credential_id`: 16 random bytes encoded as 32 lowercase hexadecimal characters.
- Capability key: independent 32 random bytes encoded as 64 lowercase hexadecimal characters.
- `capability_key_ciphertext`: Laravel Crypt ciphertext. It is hidden from model JSON.
- `policy_id`: fixed to `0` in schema v1.
- Default validity: 30 days.
- `not_before`: five minutes before issue time.
- `not_after`: capped by the user expiration time.
- Default rotation overlap: 24 hours.

The `(server_id, user_id, key_version)` tuple and `credential_id` are unique.

## Server API

For a Ravel node, `/api/v2/server/config` adds:

```json
{
  "ravel": {
    "authority": "edge.example.com",
    "path": "/ravel/v1",
    "gateway_group": "gw000001",
    "masquerade": "https://origin.example/cover",
    "settings": {}
  }
}
```

The current v2node runtime reads `/api/v1/server/UniProxy/user`; the V1 and V2
user endpoints both keep the normal user fields and add
`ravel_credentials` only when the node protocol is `ravel`. The array includes
the current credential and any still-valid rotation-overlap credential.

Responses containing Ravel credentials use `Cache-Control: private, no-store`.
Server endpoints accept `Authorization: Bearer <server-token>` and retain the
legacy query token for compatibility.

## Subscription schemas

Mihomo uses kebab-case credential fields:

```yaml
type: ravel
server: edge.example.com
port: 443
sni: sni.example.com
authority: authority.example.com
path: /ravel/v1
credential-id: 0123456789abcdef0123456789abcdef
capability-key: 64-lowercase-hex-characters
key-version: 1
gateway-group: gw000001
not-before: 1700000000
not-after: 1702592000
```

Sing-box fork schema v1 uses snake_case:

```json
{
  "type": "ravel",
  "server": "edge.example.com",
  "server_port": 443,
  "tls": { "enabled": true, "server_name": "sni.example.com" },
  "authority": "authority.example.com",
  "path": "/ravel/v1",
  "credential_id": "0123456789abcdef0123456789abcdef",
  "capability_key": "64-lowercase-hex-characters",
  "key_version": 1,
  "policy_id": 0,
  "gateway_group": "gw000001",
  "not_before": 1700000000,
  "not_after": 1702592000
}
```

The Sing-box node is emitted only when the request explicitly declares schema
v1 through `ravel_schema=1`, a `capabilities` marker, or a matching user-agent
marker.

The self-client JSON format is selected with `flag=ravel-json-v1` or
`format=ravel-json-v1`. It returns schema
`v2board.ravel.subscription`, version `1`. Client subscriptions emit only the
highest active credential version; the gateway user API retains all active
overlap versions.

Capability keys are body-only secrets. They must not be placed in a
`ravel://` URI, HTTP header, admin response, or log record.


## Runtime settings and activation

Subscriptions now carry explicit chunk shaping and carrier-pool settings, including message/frame/window limits. Sing-box uses nested TLS options; the earlier flat `sni` draft is obsolete. The standalone program imports the versioned JSON with `ravel import`.

The node service requires `GODEBUG=http2xconnect=1` before process startup. Use the protocol-enabled build from `v2node_wyx`, not an older machine-agent release. Ravel nodes currently use direct TCP; route rules are rejected. User limits and active-credential revocation are enforced in the Ravel runtime. Empty user snapshots are authoritative and retain the cover listener.

Migration: `php artisan migrate --path=database/migrations/2026_07_15_000001_add_ravel_control_plane.php`. Local SQLite migration/idempotency and credential/subscription tests passed; no production migration has been run.
