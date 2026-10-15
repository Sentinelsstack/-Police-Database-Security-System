<?php
// docs/seed_demo.php - demo data (idempotent). CLI only:
//   C:\xampp\php\php.exe docs\seed_demo.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}
require __DIR__ . '/../includes/crypto.php';

$pdo = db();
$pdo->beginTransaction();
try {
  // Officer used by demo cases/evidence (FK target)
  $pdo->prepare("INSERT INTO officers (officer_id, service_number, name, rank, department, station) VALUES ('OFF001','PF/2020/001','Insp. Dan Okoro','Inspector','Criminal Investigation','Awka Division') ON DUPLICATE KEY UPDATE name=VALUES(name)")->execute();
  // Optional creator user if an admin already exists (created_by FK)
  $creator = null;
  try { $creator = $pdo->query("SELECT user_id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn() ?: null; } catch (Throwable $t0) { $creator = null; }
  // 5 crimes
  $crimes = [
    ['DEMO_CR1','Theft','Phone snatching at Eke Awka Market, victim report attached.','Eke Awka Market','2026-06-02','Under Investigation'],
    ['DEMO_CR2','Burglary','Shop break-in on Zik Avenue, padlocks cut overnight.','Zik Avenue, Awka','2026-06-09','Reported'],
    ['DEMO_CR3','Fraud','POS operator defrauded with fake transfer alert.','UNIZIK Junction','2026-06-15','Under Investigation'],
    ['DEMO_CR4','Assault','Bus park dispute at Kwata junction, two injured.','Kwata Junction','2026-06-21','Reported'],
    ['DEMO_CR5','Cybercrime','Fake bank alert used to collect goods on credit.','Amawbia','2026-06-28','Reported'],
  ];
  foreach ($crimes as [$id,$t,$d,$l,$dt,$s]) {
    $pdo->prepare('INSERT INTO crimes (crime_id,crime_type,description,location,date_reported,status,created_by) VALUES (:id,:t,:d,:l,:dt,:s,:u) ON DUPLICATE KEY UPDATE crime_type=VALUES(crime_type), description=VALUES(description)')
        ->execute([':id'=>$id,':t'=>$t,':d'=>$d,':l'=>$l,':dt'=>$dt,':s'=>$s,':u'=>$creator]);
  }
  // 3 suspects (contact/ID encrypted with AES-256-GCM)
  $suspects = [
    ['DEMO_SP1','Chinedu Eze','Male','1998-03-11','No 4 Zik Avenue, Awka','0803-111-2233','NIN 1234-5678'],
    ['DEMO_SP2','Fatima Bello','Female','2000-07-25','No 12 UNIZIK Road, Awka','0805-444-5566',"Voter card AWK/0241"],
    ['DEMO_SP3','Emeka Nwosu','Male','1995-11-02','Amawbia, Awka South','0807-777-8899','DL AWK-99812'],
  ];
  foreach ($suspects as [$id,$n,$g,$dob,$a,$c,$i]) {
    $pdo->prepare('INSERT INTO suspects (suspect_id,full_name,gender,date_of_birth,address,contact_info,id_details) VALUES (:id,:n,:g,:d,:a,:c,:i) ON DUPLICATE KEY UPDATE full_name=VALUES(full_name), contact_info=VALUES(contact_info), id_details=VALUES(id_details)')
        ->execute([':id'=>$id,':n'=>$n,':g'=>$g,':d'=>$dob,':a'=>$a,':c'=>enc_encrypt($c),':i'=>enc_encrypt($i)]);
  }
  // 2 cases on OFF001 (invest's assigned officer) + suspect links
  $pdo->prepare("INSERT INTO cases (case_id,crime_id,officer_id,case_number,date_opened,investigation_status) VALUES ('DEMO_CS1','DEMO_CR1','OFF001','PD/26/101','2026-06-03','Open') ON DUPLICATE KEY UPDATE investigation_status=VALUES(investigation_status)")->execute();
  $pdo->prepare("INSERT INTO cases (case_id,crime_id,officer_id,case_number,date_opened,investigation_status) VALUES ('DEMO_CS2','DEMO_CR3','OFF001','PD/26/102','2026-06-16','In Progress') ON DUPLICATE KEY UPDATE investigation_status=VALUES(investigation_status)")->execute();
  foreach ([['DEMO_CS1','DEMO_SP1'],['DEMO_CS1','DEMO_SP3'],['DEMO_CS2','DEMO_SP2']] as [$c,$s]) {
    $pdo->prepare('INSERT IGNORE INTO case_suspects (case_id,suspect_id) VALUES (:c,:s)')->execute([':c'=>$c,':s'=>$s]);
  }
  // 2 evidence rows
  $pdo->prepare("INSERT INTO evidence (evidence_id,case_id,evidence_type,description,date_collected,collecting_officer_id,storage_ref) VALUES ('DEMO_EV1','DEMO_CS1','Document','Complainant statement and market receipt copies.','2026-06-03','OFF001','STORE-A/101') ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
  $pdo->prepare("INSERT INTO evidence (evidence_id,case_id,evidence_type,description,date_collected,collecting_officer_id,storage_ref) VALUES ('DEMO_EV2','DEMO_CS2','Device','POS terminal seized for forensic review.','2026-06-16','OFF001','STORE-B/204') ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
  $pdo->commit();
  echo "Demo seed OK: 5 crimes, 3 suspects, 2 cases, 2 evidence.\n";
} catch (Throwable $t) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fwrite(STDERR, 'Seed failed: ' . $t->getMessage() . PHP_EOL);
  exit(1);
}
