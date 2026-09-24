<?php
return [
    'signing' => ['skew_seconds' => 300],
    // [v0.1.1] Chuẩn hoá API v1 — shape mặc định khi client không gửi header X-Api-Envelope (2026-09-23)
    // `legacy` = body trần như contract v0.0.1 (agent Android đang cài); `v1` = envelope {data}/{error}.
    // Lật sang `v1` chỉ sau khi Contract Change Gate duyệt + fleet agent đã lên bản đọc được envelope.
    'api_envelope_default' => env('VREMOTE_API_ENVELOPE_DEFAULT', 'legacy'),
    'session_ttl' => (int) env('VREMOTE_SESSION_TTL', 1800),
    // Single VPlus Platform integration point (DSG-VREMOTE-002 §4). No VREMOTE_PLATFORM_MODE.
    'platform_url' => env('VREMOTE_PLATFORM_URL', ''),
    // Introspection auth (like the S&B admin portal): forward the caller's bearer token
    // to the Platform's "who am I" endpoint instead of verifying a JWT locally. Works with
    // OPAQUE Platform tokens. When on (and platform_url set) it is the primary PlatformClient.
    'platform_introspect' => filter_var(env('VREMOTE_PLATFORM_INTROSPECT', false), FILTER_VALIDATE_BOOL),
    // VPlus contract: the login access_token is an opaque referenceToken; exchange it for a
    // real JWT via checkRef (POST {url}{checkref_path} {referenceToken}).
    'platform_checkref_path' => env('VREMOTE_PLATFORM_CHECKREF_PATH', '/v1/auth/checkRef'),
    'platform_me_path' => env('VREMOTE_PLATFORM_ME_PATH', '/v1/me'),
    'platform_login_path' => env('VREMOTE_PLATFORM_LOGIN_PATH', '/v1/auth/login'),
    'introspect_ttl' => (int) env('VREMOTE_INTROSPECT_TTL', 30),
    'dev_auth' => filter_var(env('VREMOTE_DEV_AUTH', false), FILTER_VALIDATE_BOOL),
    'dev_actor' => env('VREMOTE_DEV_ACTOR', 'local-admin'),
    // Platform-issued JWT verification (moved out of the request path's env() reads so
    // `php artisan config:cache` is safe). The key is the VPlus identity issuer public key.
    'jwt_public_key' => env('VPLUS_USER_PUBLIC_KEY', ''),
    'jwt_alg' => env('VPLUS_JWT_ALG', 'RS256'),
    // Tenant-ownership enforcement (DSG-VREMOTE-002 §5). Default OFF during migration so
    // devices without an ownerTenantId (not yet enrolled) keep working; enable once the
    // fleet is enrolled and every device carries ownerTenantId.
    'enforce_tenant' => filter_var(env('VREMOTE_ENFORCE_TENANT', false), FILTER_VALIDATE_BOOL),
    'signaling_url' => env('VREMOTE_SIGNALING_URL', 'ws://localhost:8787/signaling'),
    'signaling_internal_url' => env('VREMOTE_SIGNALING_INTERNAL_URL', 'http://localhost:8788'),
    'signaling_internal_secret' => env('VREMOTE_SIGNALING_INTERNAL_SECRET', ''),
    'session_secret' => env('VREMOTE_SESSION_SECRET', ''),
    'viewer_url' => env('VREMOTE_VIEWER_URL', 'http://localhost:5173'),
    'gated_commands' => filter_var(env('VREMOTE_GATED_COMMANDS', 'false'), FILTER_VALIDATE_BOOL),
    'devices' => env('VREMOTE_DEVICES_JSON', '{}'),
    // Baseline ICE = STUN only. TURN is added per-session by TurnCredentialService WITH
    // ephemeral username+credential (coturn REST auth). A TURN url must never be emitted
    // here without credentials: the browser rejects the whole RTCPeerConnection with
    // "Both username and credential are required when the URL scheme is 'turn'/'turns'".
    'ice_servers' => array_values(array_filter([
        ['urls' => env('VREMOTE_STUN_URL', 'stun:stun.l.google.com:19302')],
    ])),
];
