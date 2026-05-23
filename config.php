<?php
return [
    'db' => [
        'host' => getenv('RACK_PLANNER_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('RACK_PLANNER_DB_PORT') ?: 3306),
        'database' => getenv('RACK_PLANNER_DB_NAME') ?: 'rack_planner',
        'username' => getenv('RACK_PLANNER_DB_USER') ?: 'root',
        'password' => getenv('RACK_PLANNER_DB_PASS') ?: '',
        'charset' => getenv('RACK_PLANNER_DB_CHARSET') ?: 'utf8mb4',
    ],
];
