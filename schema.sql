-- Police Database Security System - schema
-- DB: police_db_security | Engine: InnoDB | Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS police_db_security
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE police_db_security;

-- Roles (RBAC base)
CREATE TABLE IF NOT EXISTS roles (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

INSERT INTO roles (role_name, description) VALUES
  ('Administrator','Full system control, user/role management, audit review'),
  ('Records Officer','Register/view crimes and suspects, generate reports'),
  ('Investigating Officer','Assigned cases, investigations, evidence'),
  ('Supervisor','Oversight, audit review, reports')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Users (OOADM User object)
CREATE TABLE IF NOT EXISTS users (
  user_id VARCHAR(20) PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL DEFAULT 'System User',
  role_id INT NOT NULL,
  officer_id VARCHAR(20) NULL COMMENT 'Linked officers row (Investigating Officer)',
  account_status ENUM('active','locked','disabled') NOT NULL DEFAULT 'active',
  force_password_reset TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  totp_secret VARCHAR(64) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_users_role (role_id),
  INDEX idx_users_status (account_status),
  INDEX idx_users_officer (officer_id)
) ENGINE=InnoDB;

-- Officers
CREATE TABLE IF NOT EXISTS officers (
  officer_id VARCHAR(20) PRIMARY KEY,
  service_number VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  rank VARCHAR(50) NOT NULL,
  department VARCHAR(80) NULL,
  station VARCHAR(80) NULL,
  contact_info VARCHAR(120) NULL,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_officers_rank (rank)
) ENGINE=InnoDB;

-- Crimes
CREATE TABLE IF NOT EXISTS crimes (
  crime_id VARCHAR(20) PRIMARY KEY,
  crime_type VARCHAR(80) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(150) NOT NULL,
  date_reported DATE NOT NULL,
  reporting_officer_id VARCHAR(20) NULL,
  status ENUM('Reported','Under Investigation','Closed','Dismissed') NOT NULL DEFAULT 'Reported',
  created_by VARCHAR(20) NULL,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_crimes_officer FOREIGN KEY (reporting_officer_id) REFERENCES officers(officer_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_crimes_user FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_crimes_type (crime_type),
  INDEX idx_crimes_status (status),
  INDEX idx_crimes_date (date_reported)
) ENGINE=InnoDB;

-- Suspects
CREATE TABLE IF NOT EXISTS suspects (
  suspect_id VARCHAR(20) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  gender ENUM('Male','Female','Other') NULL,
  date_of_birth DATE NULL,
  address VARCHAR(200) NULL,
  contact_info VARCHAR(120) NULL,
  id_details VARCHAR(150) NULL,
  photo_path VARCHAR(255) NULL,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_suspects_name (full_name)
) ENGINE=InnoDB;

-- Cases (investigation per crime)
CREATE TABLE IF NOT EXISTS cases (
  case_id VARCHAR(20) PRIMARY KEY,
  crime_id VARCHAR(20) NOT NULL,
  officer_id VARCHAR(20) NULL COMMENT 'Assigned investigating officer',
  case_number VARCHAR(40) NOT NULL UNIQUE,
  date_opened DATE NOT NULL,
  investigation_status ENUM('Open','In Progress','Awaiting Trial','Closed') NOT NULL DEFAULT 'Open',
  case_status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
  charges VARCHAR(255) NULL,
  next_hearing DATE NULL,
  custody_status VARCHAR(80) NULL,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cases_crime FOREIGN KEY (crime_id) REFERENCES crimes(crime_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_cases_officer FOREIGN KEY (officer_id) REFERENCES officers(officer_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_cases_officer (officer_id),
  INDEX idx_cases_status (investigation_status)
) ENGINE=InnoDB;

-- Case <-> Suspect link (one person, many cases, no duplication)
CREATE TABLE IF NOT EXISTS case_suspects (
  case_id VARCHAR(20) NOT NULL,
  suspect_id VARCHAR(20) NOT NULL,
  PRIMARY KEY (case_id, suspect_id),
  CONSTRAINT fk_cs_case FOREIGN KEY (case_id) REFERENCES cases(case_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_cs_suspect FOREIGN KEY (suspect_id) REFERENCES suspects(suspect_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Evidence
CREATE TABLE IF NOT EXISTS evidence (
  evidence_id VARCHAR(20) PRIMARY KEY,
  case_id VARCHAR(20) NOT NULL,
  evidence_type VARCHAR(80) NOT NULL,
  description TEXT NULL,
  date_collected DATE NULL,
  collecting_officer_id VARCHAR(20) NULL,
  storage_ref VARCHAR(150) NULL,
  evidence_hash CHAR(64) NULL COMMENT 'SHA-256 integrity hash of evidence metadata',
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ev_case FOREIGN KEY (case_id) REFERENCES cases(case_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ev_officer FOREIGN KEY (collecting_officer_id) REFERENCES officers(officer_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_ev_case (case_id)
) ENGINE=InnoDB;

-- Audit log (tamper-resistant: no UPDATE/DELETE granted to app user;
-- hash chain via prev_hash/row_hash verified in audit_logs.php)
CREATE TABLE IF NOT EXISTS audit_log (
  log_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(20) NULL,
  action VARCHAR(80) NOT NULL,
  record_affected VARCHAR(100) NULL,
  ip_address VARCHAR(45) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  description VARCHAR(255) NULL,
  prev_hash CHAR(64) NULL,
  row_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_action (action),
  INDEX idx_audit_time (created_at)
) ENGINE=InnoDB;

-- Victims / complainants (contact encrypted like suspects)
CREATE TABLE IF NOT EXISTS victims (
  victim_id VARCHAR(20) PRIMARY KEY,
  crime_id VARCHAR(20) NULL,
  full_name VARCHAR(100) NOT NULL,
  gender ENUM('Male','Female','Other') NULL,
  contact_info VARCHAR(255) NULL COMMENT 'AES-256-GCM ENC1: payload',
  address VARCHAR(200) NULL,
  statement TEXT NULL,
  date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_victims_crime FOREIGN KEY (crime_id) REFERENCES crimes(crime_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_victims_name (full_name),
  INDEX idx_victims_crime (crime_id)
) ENGINE=InnoDB;

-- Two-person delete approval workflow (no direct hard delete UI)
CREATE TABLE IF NOT EXISTS delete_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  table_name VARCHAR(30) NOT NULL,
  record_id VARCHAR(20) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  requested_by VARCHAR(20) NULL,
  reviewed_by VARCHAR(20) NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dr_requester FOREIGN KEY (requested_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_dr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_dr_status (status)
) ENGINE=InnoDB;

-- Upgrade path for databases created before hash-chain / victims / delete_requests.
-- Safe to run repeatedly on MariaDB 10.4+.
ALTER TABLE audit_log
  ADD COLUMN IF NOT EXISTS prev_hash CHAR(64) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS row_hash CHAR(64) NULL AFTER prev_hash;
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS officer_id VARCHAR(20) NULL AFTER role_id,
  ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL AFTER locked_until,
  ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
ALTER TABLE cases
  ADD COLUMN IF NOT EXISTS charges VARCHAR(255) NULL AFTER case_status,
  ADD COLUMN IF NOT EXISTS next_hearing DATE NULL AFTER charges,
  ADD COLUMN IF NOT EXISTS custody_status VARCHAR(80) NULL AFTER next_hearing;
ALTER TABLE evidence
  ADD COLUMN IF NOT EXISTS evidence_hash CHAR(64) NULL AFTER storage_ref;

-- Seed users: NONE. No account passwords live in source control.
-- Create the first administrator (password printed once, force-reset on login):
--   C:\xampp\php\php.exe docs\create_admin.php admin "System Administrator" 1
-- Then create officers/cases users as needed with the same script.

-- Dedicated least-privilege application account (never use root in app).
-- 1) Generate a strong password (do not reuse any example):
--      C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(18)), PHP_EOL;"
-- 2) Put it in config/.env as DB_PASS (copy config/.env.example first).
-- 3) Run the statements below with that same password (update both host forms).
-- Password is intentionally NOT hardcoded here.
-- CREATE USER IF NOT EXISTS 'police_app'@'localhost' IDENTIFIED BY '<SET_ME_IN_ENV>';
-- CREATE USER IF NOT EXISTS 'police_app'@'127.0.0.1' IDENTIFIED BY '<SET_ME_IN_ENV>';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON police_db_security.* TO 'police_app'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON police_db_security.* TO 'police_app'@'127.0.0.1';
-- Explicitly no DROP/ALTER/GRANT/CREATE USER; audit_log protected by app logic (no delete UI)
-- FLUSH PRIVILEGES;

-- Case timeline events (notes / hearing updates)
CREATE TABLE IF NOT EXISTS case_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  case_id VARCHAR(20) NOT NULL,
  event_type VARCHAR(40) NOT NULL DEFAULT 'Note',
  title VARCHAR(150) NOT NULL,
  details TEXT NULL,
  event_date DATE NOT NULL,
  created_by VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ce_case FOREIGN KEY (case_id) REFERENCES cases(case_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ce_user FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_ce_case (case_id)
) ENGINE=InnoDB;

-- Backup / restore-test log (admin UI + CLI runner)
CREATE TABLE IF NOT EXISTS backup_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  backup_type ENUM('manual','scheduled','restore_test') NOT NULL DEFAULT 'manual',
  filename VARCHAR(255) NULL,
  row_counts JSON NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_by VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_backup_time (created_at)
) ENGINE=InnoDB;

-- Login history
CREATE TABLE IF NOT EXISTS login_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id VARCHAR(20) NULL,
  login_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  logout_time DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  status ENUM('success','failed','locked') NOT NULL DEFAULT 'success',
  user_agent VARCHAR(255) NULL,
  INDEX idx_lh_user (user_id),
  INDEX idx_lh_status (status)
) ENGINE=InnoDB;


