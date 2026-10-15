# Backup & Recovery Runbook - Police Database Security System

## What to back up
1. **Database** `police_db_security` (MySQL/MariaDB)
2. **Photos** `<app>/uploads/` (especially `suspects/`)

## Automated (recommended)
```bat
C:\xampp\php\php.exe docs\backup_run.php
```
- Uses least-privilege `police_app` credentials from env (never root)
- Writes dumps outside the webroot (`<parent>/backups/`)
- Copies uploads; logs a row in `backup_logs`

Schedule daily (Windows Task Scheduler example):
```bat
schtasks /Create /TN "PoliceDB Backup" /SC DAILY /ST 02:00 /TR "C:\xampp\php\php.exe C:\xampp\htdocs\finalyearproject\Joshua\police_db\docs\backup_run.php"
```

## Manual dump (if CLI runner unavailable)
```bat
C:\xampp\mysql\bin\mysqldump.exe -u police_app -p --port=3307 -h 127.0.0.1 --single-transaction --routines police_db_security > backup-YYYYMMDD.sql
```
Store the file **outside** the webroot. Do not leave `.sql` under `htdocs`.

## Restore test (monthly / before changes)
```bat
C:\xampp\mysql\bin\mysql.exe -u root -p --port=3307 -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS restore_test;"
C:\xampp\mysql\bin\mysql.exe -u root -p --port=3307 -h 127.0.0.1 restore_test < backup-YYYYMMDD.sql
C:\xampp\mysql\bin\mysql.exe -u root -p --port=3307 -h 127.0.0.1 -e "SELECT COUNT(*) FROM restore_test.users; SELECT COUNT(*) FROM restore_test.audit_log;"
```
Log the test in **Backup** UI (checkbox "Restore tested OK").

## Recovery order
1. Restore database dump
2. Restore `uploads/` photos
3. Verify admin login + spot-check crime/case/audit counts
4. Confirm audit hash chain on Audit Logs page

## Retention
Keep at least 3 copies: local + external disk + offsite/encrypted. Rotate dumps weekly; purge dumps older than 90 days (or per policy).
