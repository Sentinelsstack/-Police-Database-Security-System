<?php
// includes/crud.php - helpers: IDs, selects, upload, assigned-case checks
declare(strict_types=1);

require_once __DIR__ . '/security.php';

function gen_id(string $prefix): string {
  return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

function crime_types(): array {
  return ['Theft','Burglary','Assault','Fraud','Cybercrime','Homicide','Kidnapping','Drug Offence','Vandalism','Other'];
}

function crime_statuses(): array {
  return ['Reported','Under Investigation','Closed','Dismissed'];
}

function case_statuses(): array {
  return ['Open','In Progress','Awaiting Trial','Closed'];
}

function genders(): array {
  return ['Male','Female','Other'];
}

function evidence_types(): array {
  return ['Document','Photo','Video','Weapon','Device','Forensic','Other'];
}

// Investigating Officer's linked officer (for assigned-cases-only)
function io_officer_id(): ?string {
  return $_SESSION['officer_id'] ?? null;
}

// Is this case assigned to the current IO?
function case_assigned_to_me(string $caseId): bool {
  $oid = io_officer_id();
  if (!$oid) return false;
  try {
    $stmt = db()->prepare('SELECT 1 FROM cases WHERE case_id = :c AND officer_id = :o LIMIT 1');
    $stmt->execute([':c' => $caseId, ':o' => $oid]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $t) { return false; }
}

// Is this crime linked to one of my assigned cases (or created by me)?
function crime_assigned_to_me(string $crimeId): bool {
  $oid = io_officer_id();
  $me = $_SESSION['user_id'] ?? null;
  try {
    $pdo = db();
    if ($me) {
      $s = $pdo->prepare('SELECT 1 FROM crimes WHERE crime_id = :c AND created_by = :u LIMIT 1');
      $s->execute([':c' => $crimeId, ':u' => $me]);
      if ($s->fetchColumn()) return true;
    }
    if ($oid) {
      $s = $pdo->prepare('SELECT 1 FROM cases WHERE crime_id = :c AND officer_id = :o LIMIT 1');
      $s->execute([':c' => $crimeId, ':o' => $oid]);
      if ($s->fetchColumn()) return true;
    }
  } catch (Throwable $t) { /* deny */ }
  return false;
}

/**
 * Secure suspect photo upload.
 * Returns relative path (e.g. uploads/suspects/SP_AB12CD.jpg) or null if none.
 * Throws RuntimeException with user-safe message on failure.
 */
function handle_photo_upload(array $file): ?string {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
  if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Photo upload failed. Please try again.');
  if (($file['size'] ?? 0) > 2 * 1024 * 1024) throw new RuntimeException('Photo must be at most 2MB.');
  $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) throw new RuntimeException('Photo must be JPG or PNG.');
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($file['tmp_name']);
  if (!in_array($mime, ['image/jpeg', 'image/png'], true)) throw new RuntimeException('Photo content is not a valid JPG/PNG image.');
  // Re-encode to strip EXIF/metadata
  $rand = strtoupper(bin2hex(random_bytes(4)));
  $destRel = 'uploads/suspects/SP_' . $rand . ($mime === 'image/png' ? '.png' : '.jpg');
  $destAbs = dirname(__DIR__) . '/' . $destRel;
  if ($mime === 'image/png') {
    $src = @imagecreatefrompng($file['tmp_name']);
    if (!$src) throw new RuntimeException('Could not read PNG photo.');
    if (!@imagepng($src, $destAbs)) { imagedestroy($src); throw new RuntimeException('Could not save photo.'); }
    imagedestroy($src);
  } else {
    $src = @imagecreatefromjpeg($file['tmp_name']);
    if (!$src) throw new RuntimeException('Could not read JPG photo.');
    if (!@imagejpeg($src, $destAbs, 88)) { imagedestroy($src); throw new RuntimeException('Could not save photo.'); }
    imagedestroy($src);
  }
  return $destRel;
}

function valid_date(?string $d): bool {
  if (!$d) return false;
  $t = strtotime($d);
  return $t !== false && $t <= time() + 86400;
}
