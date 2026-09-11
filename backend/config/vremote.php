<?php
return [
    'signing' => ['skew_seconds' => 300],
    'session_ttl' => (int) env('VREMOTE_SESSION_TTL', 1800),
    'signaling_url' => env('VREMOTE_SIGNALING_URL', 'ws://localhost:8787/signaling'),
    'signaling_internal_url' => env('VREMOTE_SIGNALING_INTERNAL_URL', 'http://localhost:8788'),
    'signaling_internal_secret' => env('VREMOTE_SIGNALING_INTERNAL_SECRET', ''),
    'session_secret' => env('VREMOTE_SESSION_SECRET', ''),
    'devices' => env('VREMOTE_DEVICES_JSON', '{}'),
    'ice_servers' => array_values(array_filter([
        ['urls' => env('VREMOTE_STUN_URL', 'stun:stun.l.google.com:19302')],
        env('VREMOTE_TURN_URL') ? ['urls' => env('VREMOTE_TURN_URL')] : null,
    ])),
];
