<?php
// includes/totp.php - lightweight RFC6238 TOTP (no external deps)
declare(strict_types=1);

function totp_base32_decode(string $b32): string {
  $b32 = strtoupper(rtrim($b32, '='));
  $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits = '';
  $out = '';
  for ($i = 0; $i < strlen($b32); $i++) {
    $v = strpos($map, $b32[$i]);
    if ($v === false) continue;
    $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
  }
  for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
    $out .= chr((int)bindec(substr($bits, $i, 8)));
  }
  return $out;
}

function totp_generate_secret(int $len = 20): string {
  $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $s = '';
  for ($i = 0; $i < 32; $i++) $s .= $chars[random_int(0, 31)];
  return $s;
}

function totp_code(string $secret, ?int $time = null, int $step = 30, int $digits = 6): string {
  $time = $time ?? time();
  $counter = (int)floor($time / $step);
  $key = totp_base32_decode($secret);
  $msg = pack('N*', 0) . pack('N*', $counter);
  $hash = hash_hmac('sha1', $msg, $key, true);
  $offset = ord($hash[19]) & 0x0F;
  $code = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16) | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);
  $code = $code % (10 ** $digits);
  return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code, int $window = 1): bool {
  $code = trim($code);
  if (!preg_match('/^[0-9]{6}$/', $code)) return false;
  $t = time();
  for ($i = -$window; $i <= $window; $i++) {
    if (hash_equals(totp_code($secret, $t + $i * 30), $code)) return true;
  }
  return false;
}

function totp_provision_uri(string $secret, string $account, string $issuer = 'PoliceDB'): string {
  return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}
