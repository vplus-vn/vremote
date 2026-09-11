<?php
return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        // Production persistence for VRemote domain data (device/session/command/audit
        // and the v0.2.0 device-lifecycle collections). Requires mongodb/laravel-mongodb.
        'mongodb' => [
            'driver' => 'mongodb',
            'host' => env('MONGODB_HOST', '127.0.0.1'),
            'port' => (int) env('MONGODB_PORT', 27017),
            'database' => env('MONGODB_DATABASE', 'vremote'),
            'username' => env('MONGODB_USERNAME'),
            'password' => env('MONGODB_PASSWORD'),
            'options' => [
                // authSource — the database that holds the user credentials.
                'database' => env('MONGODB_AUTHENTICATION_DATABASE', env('MONGODB_DATABASE', 'vremote')),
            ],
        ],
        // Kept for local/testing convenience; not used in production.
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
