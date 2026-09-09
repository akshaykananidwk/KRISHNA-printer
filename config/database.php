<?php
declare(strict_types=1);

$generated = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];

$value = static function (string $key, mixed $default = null) use ($generated): mixed {
    if (array_key_exists($key, $generated)) {
        return $generated[$key];
    }
    $env = getenv(strtoupper($key));
    return $env === false || $env === '' ? $default : $env;
};

return [
    'driver' => $value('db_driver', 'mysql'),
    'host' => $value('db_host', '127.0.0.1'),
    'port' => (int) $value('db_port', 3306),
    'database' => $value('db_name', ''),
    'username' => $value('db_user', ''),
    'password' => $value('db_pass', ''),
    'socket' => $value('db_socket', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
];
