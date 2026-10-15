<?php
// docs/create_admin.php - create a user with a one-time printed temp password.
// No passwords are stored in source. Run via CLI only:
//   C:\xampp\php\php.exe docs\create_admin.php <username> <full name> <role_id>
// Roles: 1=Administrator 2=Records Officer 3=Investigating Officer 4=Supervisor
// Optional 4th arg: officer_id link (required for Investigating Officer).
declare(strict_types=1);
require __DIR__ . '/../includes/security.php';

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only.\n");
  exit(1);
}
$username = trim($argv[1] ?? '');
$full = trim($argv[2] ?? '');
$roleId = (int)($argv[3] ?? 0);
$officerId = trim($argv[4] ?? '') ?: null;

if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username) || $full === '' || $roleId < 1) {
  fwrite(STDERR, "Usage: php docs\\create_admin.php <username> <full name> <role_id> [officer_id]\n");
  exit(1);
}

function temp_password(): string {
  // Meets policy: 12+ chars, upper, lower, digit; not in common list.
  $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  $lower = 'abcdefghijkmnpqrstuvwxyz';
  $digit = '23456789';
  $all = $upper . $lower . $digit;
  do {
    $pw = $upper[random_int(0, strlen($upper) - 1)]
        . $lower[random_int(0, strlen($lower) - 1)]
        . $digit[random_int(0, strlen($digit) - 1)];
    for ($i = 0; $i < 12; $i++) $pw .= $all[random_int(0, strlen($all) - 1)];
    $pw = str_split($pw);
    shuffle($pw);
    $pw = implode('', $pw);
  } while (password_policy_errors($pw) !== []);
  return $pw;
}

$pdo = db();
$exists = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
$exists->execute([':u' => $username]);
if ($exists->fetch()) {
  fwrite(STDERR, "Username already exists: {$username}\n");
  exit(1);
}
$role = $pdo->prepare('SELECT role_id FROM roles WHERE role_id = :r LIMIT 1');
$role->execute([':r' => $roleId]);
if (!$role->fetch()) {
  fwrite(STDERR, "Unknown role_id {$roleId}\n");
  exit(1);
}

$pw = temp_password();
$uid = 'USR' . str_pad((string)random_int(100, 9999), 4, '0', STR_PAD_LEFT);
$ins = $pdo->prepare('INSERT INTO users (user_id, username, password_hash, full_name, role_id, account_status, force_password_reset, officer_id) VALUES (:id,:u,:h,:f,:r,\'active\',1,:o)');
$ins->execute([':id' => $uid, ':u' => $username, ':h' => password_hash_secure($pw), ':f' => substr($full, 0, 100), ':r' => $roleId, ':o' => $officerId]);

echo "Created {$uid} ({$username}).\n";
echo "Temporary password (shown once, user must change on first login):\n  {$pw}\n";
