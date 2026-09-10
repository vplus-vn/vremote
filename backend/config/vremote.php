<?php
return [
    'signing' => ['skew_seconds' => 300],
    'session_ttl' => (int) env('VREMOTE_SESSION_TTL', 1800),
    'signaling_url' => env('VREMOTE_SIGNALING_URL', 'wss://vremote.example/signaling'),
    'ice_servers' => array_values(array_filter([
        ['urls' => env('VREMOTE_STUN_URL', 'stun:stun.l.google.com:19302')],
        env('VREMOTE_TURN_URL') ? ['urls' => env('VREMOTE_TURN_URL')] : null,
    ])),
];
