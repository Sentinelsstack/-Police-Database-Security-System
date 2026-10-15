<?php
// config/env.php - minimal .env loader (no Composer dependency).
// Load order (first wins for real process env; file only fills gaps):
//   1) Real environment variables (Apache SetEnv / systemd / IIS)
//   2) Path in POLICE_ENV_FILE (recommended: outside webroot)
//   3) Optional ../.police_db.env (parent of app root)
//   4) config/.env (dev convenience only - denied by .htaccess)
declare(strict_types=1);

function env_load_file(string $path): void {
  if (!is_readable($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) return;
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $eq = strpos($line, '=');
    if ($eq === false) continue;
    $key = trim(substr($line, 0, $eq));
    $val = trim(substr($line, $eq + 1));
    if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) continue;
    $len = strlen($val);
    if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
      $val = substr($val, 1, -1);
    }
    if (getenv($key) === false) {
      putenv($key . '=' . $val);
      $_ENV[$key] = $val;
    }
  }
}

function env(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  return $v === false ? $default : $v;
}

// Explicit production path first, then parent-of-app, then local config/.env.
$explicit = env('POLICE_ENV_FILE');
if ($explicit !== null && $explicit !== '') {
  env_load_file($explicit);
}
env_load_file(dirname(__DIR__, 2) . '/.police_db.env');
env_load_file(__DIR__ . '/.env');
