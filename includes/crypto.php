<?php
// includes/crypto.php - AES-256-GCM authenticated encryption for sensitive fields.
// Unique random IV per record; IV+tag stored alongside ciphertext (base64 JSON).
// Legacy plaintext rows decrypt-fallback to plaintext (backward compatible).
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function enc_key(): string {
  $raw = base64_decode(enc_key_b64(), true);
  if ($raw === false || strlen($raw) !== 32) {
    throw new RuntimeException('Invalid app encryption key.');
  }
  return $raw;
}

function enc_encrypt(?string $plain): ?string {
  if ($plain === null || $plain === '') return $plain === '' ? '' : null;
  $iv = random_bytes(12);
  $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, $iv, $tag);
  if ($ct === false) throw new RuntimeException('Encryption failed.');
  return 'ENC1:' . base64_encode(json_encode(['iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($ct)], JSON_THROW_ON_ERROR));
}

function enc_decrypt(?string $payload): ?string {
  if ($payload === null || $payload === '') return $payload;
  if (strpos($payload, 'ENC1:') !== 0) return $payload; // legacy plaintext row
  $raw = base64_decode(substr($payload, 5), true);
  if ($raw === false) return '[decryption failed]';
  try {
    $j = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
    if (!isset($j['iv'], $j['tag'], $j['data'])) return '[decryption failed]';
    $pt = openssl_decrypt(base64_decode($j['data']), 'aes-256-gcm', enc_key(), OPENSSL_RAW_DATA, base64_decode($j['iv']), base64_decode($j['tag']));
    return $pt === false ? '[decryption failed]' : $pt;
  } catch (Throwable $t) {
    return '[decryption failed]';
  }
}
