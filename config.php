<?php
// Local XAMPP defaults. Set environment variables for another MySQL server.
return [
    'host' => getenv('TF_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('TF_DB_PORT') ?: '3306',
    'database' => getenv('TF_DB_NAME') ?: 'tfv1',
    'username' => getenv('TF_DB_USER') ?: 'root',
    'password' => getenv('TF_DB_PASSWORD') ?: '',
];
