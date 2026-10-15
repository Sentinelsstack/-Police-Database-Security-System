<?php
// config/db.php - PDO connection as least-privilege police_app user.
// Secrets come from config/.env (see config/.env.example). Never hardcode
// passwords or keys here, and never use root in the application.

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function env_required(string $key): string {
  $v = env($key);
  if ($v === null || $v === '' || str_starts_with($v, 'CHANGE_ME')) {
    throw new RuntimeException(
      "Missing secret '{$key}'. Copy config/.env.example to config/.env and set real values."
    );
  }
  return $v;
}

const DB_CHARSET_FALLBACK = 'utf8mb4';

function db_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $cfg = [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'name' => env('DB_NAME', 'police_db_security'),
    'user' => env_required('DB_USER'),
    'pass' => env_required('DB_PASS'),
    'charset' => env('DB_CHARSET', DB_CHARSET_FALLBACK),
  ];
  return $cfg;
}

function enc_key_b64(): string {
  return env_required('ENC_KEY_B64');
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  $c = db_config();
  $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=' . $c['charset'];
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  $pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
  return $pdo;
}
