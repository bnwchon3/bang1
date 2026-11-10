<?php
declare(strict_types=1);
require __DIR__.'/../inc/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST' || !hash_equals(config('deploy_token'),(string)($_SERVER['HTTP_X_DEPLOY_TOKEN']??''))) json_out(['success'=>false],403);
$statements=[
"CREATE TABLE IF NOT EXISTS source_rows (sheet_name VARCHAR(120) NOT NULL, source_row INT NOT NULL, payload LONGTEXT NOT NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(sheet_name,source_row)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS schools (id BIGINT PRIMARY KEY, name VARCHAR(255) NOT NULL, nickname VARCHAR(100), active VARCHAR(60), UNIQUE KEY uq_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS coaches (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), email VARCHAR(255) NOT NULL, school_name VARCHAR(255), password_hash VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'Teacher', auth_version INT NOT NULL DEFAULT 1, UNIQUE KEY uq_email(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS runners (id BIGINT PRIMARY KEY AUTO_INCREMENT, school_name VARCHAR(255) NOT NULL, level VARCHAR(50), first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, citizen_id VARCHAR(32), birth_year SMALLINT UNSIGNED, photo_url TEXT, proof_url TEXT, gender VARCHAR(20), status VARCHAR(80) DEFAULT 'รอตรวจสอบ', created_by VARCHAR(255), created_at DATETIME NULL, checked_by VARCHAR(255), fail_reason TEXT, INDEX idx_school(school_name), INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS events (id BIGINT PRIMARY KEY, name VARCHAR(255) NOT NULL, category VARCHAR(80), level VARCHAR(60), gender VARCHAR(30), remark VARCHAR(255), heat VARCHAR(50), heat_plan VARCHAR(50), team_count INT, total_teams INT, stage VARCHAR(100), ranking_json LONGTEXT, source_order INT, INDEX idx_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS registrations (id BIGINT PRIMARY KEY AUTO_INCREMENT, event_id BIGINT NULL, event_name VARCHAR(255) NOT NULL, category VARCHAR(80), level VARCHAR(60), school_name VARCHAR(255) NOT NULL, runner_ids TEXT NOT NULL, runner_names TEXT, photos TEXT, gender VARCHAR(100), age VARCHAR(100), registration_key VARCHAR(500), qr_url TEXT, heat INT NULL, lane INT NULL, created_by VARCHAR(255), created_at DATETIME NULL, INDEX idx_event(event_name), INDEX idx_school(school_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS heats (id BIGINT PRIMARY KEY AUTO_INCREMENT, event_id BIGINT NULL, event_name VARCHAR(255), category VARCHAR(80), level VARCHAR(60), gender VARCHAR(30), heat INT, INDEX idx_event(event_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS feature_config (id BIGINT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, opens_at DATETIME NULL, closes_at DATETIME NULL, status VARCHAR(40), UNIQUE KEY uq_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, action_code VARCHAR(80) NOT NULL, action_text VARCHAR(255), module VARCHAR(80), target_type VARCHAR(80), target_id VARCHAR(120), target_name VARCHAR(255), runner_id VARCHAR(120), reg_id VARCHAR(120), event_name VARCHAR(255), user_email VARCHAR(255), user_name VARCHAR(255), user_role VARCHAR(50), user_school VARCHAR(255), detail TEXT, INDEX idx_action(action_code), INDEX idx_time(event_time)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_results (id BIGINT PRIMARY KEY AUTO_INCREMENT, source_sheet VARCHAR(80) NOT NULL, source_row INT NOT NULL, payload LONGTEXT NOT NULL, UNIQUE KEY uq_source(source_sheet,source_row)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS event_results (id BIGINT PRIMARY KEY AUTO_INCREMENT, event_id BIGINT NOT NULL, round_type VARCHAR(32) NOT NULL, heat_no INT NOT NULL DEFAULT 1, ranking_json LONGTEXT NOT NULL, staff_id BIGINT NOT NULL, staff_name VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_fixture(event_id,round_type,heat_no), INDEX idx_event_results_staff(staff_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS certificates (id BIGINT PRIMARY KEY AUTO_INCREMENT, event_name VARCHAR(255), medal VARCHAR(60), school_name VARCHAR(255), athlete_name VARCHAR(255), certificate_number VARCHAR(100), sport VARCHAR(100)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS admin_security_audit (id BIGINT AUTO_INCREMENT PRIMARY KEY, event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, action_code VARCHAR(80) NOT NULL, actor_id BIGINT NOT NULL, actor_email VARCHAR(255) NOT NULL, detail TEXT, INDEX idx_event_time(event_time)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS account_applications (id BIGINT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(120) NOT NULL, last_name VARCHAR(120) NOT NULL, school_name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_by BIGINT NULL, reviewed_at DATETIME NULL, UNIQUE KEY uq_account_app_email(email), INDEX idx_account_app_status(status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS account_reset_challenges (id BIGINT AUTO_INCREMENT PRIMARY KEY, coach_id BIGINT NOT NULL, code_hash VARCHAR(255) NOT NULL, ip_hash CHAR(64) NOT NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, expires_at DATETIME NOT NULL, verified_at DATETIME NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_account_reset_coach(coach_id,created_at), INDEX idx_account_reset_ip(ip_hash,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS account_public_limits (id BIGINT AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, action_name VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_account_rate(ip_hash,action_name,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_checkins (registration_id BIGINT NOT NULL PRIMARY KEY,checked_by BIGINT NOT NULL,checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_race_checkins_time(checked_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_athlete_checkins (registration_id BIGINT NOT NULL,athlete_key VARCHAR(48) NOT NULL,checked_by BIGINT NOT NULL,checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(registration_id,athlete_key),INDEX idx_race_athlete_checkins_time(checked_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_live_state (id TINYINT UNSIGNED NOT NULL PRIMARY KEY,fixture_key CHAR(64) NULL,updated_by BIGINT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_live_display (id TINYINT UNSIGNED NOT NULL PRIMARY KEY,display_mode VARCHAR(12) NOT NULL DEFAULT 'checkin',updated_by BIGINT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_history_batches (id BIGINT AUTO_INCREMENT PRIMARY KEY,season_label VARCHAR(80) NOT NULL,source_type VARCHAR(30) NOT NULL,is_confirmed TINYINT(1) NOT NULL DEFAULT 0,result_count INT NOT NULL DEFAULT 0,final_count INT NOT NULL DEFAULT 0,captured_by BIGINT NOT NULL,captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_history_confirmed(is_confirmed,captured_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS race_history_results (id BIGINT AUTO_INCREMENT PRIMARY KEY,batch_id BIGINT NOT NULL,source_event_id BIGINT NOT NULL,event_name VARCHAR(255) NOT NULL,event_key CHAR(64) NOT NULL,category VARCHAR(80),level VARCHAR(60),gender VARCHAR(30),round_type VARCHAR(32) NOT NULL,heat_no INT NOT NULL,ranking_json LONGTEXT NOT NULL,source_updated_at DATETIME NULL,UNIQUE KEY uq_history_fixture(batch_id,source_event_id,round_type,heat_no),INDEX idx_history_event(event_key,round_type,batch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS import_batches (id BIGINT AUTO_INCREMENT PRIMARY KEY, imported_at DATETIME DEFAULT CURRENT_TIMESTAMP, rows_imported INT NOT NULL DEFAULT 0, note VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];
try {
 foreach($statements as $sql) db()->exec($sql);
 // Preserve imported CoachID values while allowing safe auto-generated IDs for approved accounts.
 $column=db()->query("SHOW COLUMNS FROM coaches LIKE 'id'")->fetch();
 if(!str_contains((string)($column['Extra']??''),'auto_increment'))
   db()->exec('ALTER TABLE coaches MODIFY COLUMN id BIGINT NOT NULL AUTO_INCREMENT');
 $column=db()->query("SHOW COLUMNS FROM coaches LIKE 'auth_version'")->fetch();
 if(!$column)db()->exec('ALTER TABLE coaches ADD COLUMN auth_version INT NOT NULL DEFAULT 1');
 // Access to LIVE athlete check-in is explicitly granted per Manager by Admin.
 // Existing and future Manager accounts are DENIED by default.
 $column=db()->query("SHOW COLUMNS FROM coaches LIKE 'can_live_checkin'")->fetch();
 if(!$column)db()->exec('ALTER TABLE coaches ADD COLUMN can_live_checkin TINYINT(1) NOT NULL DEFAULT 0');
 // Images are stored on the host and survive season resets; only generated
 // filenames (never user-supplied URLs) are stored in the schools table.
 $column=db()->query("SHOW COLUMNS FROM schools LIKE 'logo_file'")->fetch();
 if(!$column)db()->exec('ALTER TABLE schools ADD COLUMN logo_file VARCHAR(160) NULL');
 $column=db()->query("SHOW COLUMNS FROM schools LIKE 'logo_updated_at'")->fetch();
 if(!$column)db()->exec('ALTER TABLE schools ADD COLUMN logo_updated_at DATETIME NULL');
 // Configurable left-to-right LIVE school positions, independent of race lanes.
 $column=db()->query("SHOW COLUMNS FROM schools LIKE 'live_position'")->fetch();
 if(!$column)db()->exec('ALTER TABLE schools ADD COLUMN live_position SMALLINT UNSIGNED NULL');
 // Only an actually approved image may become the comparison reference.
 // Backfill existing approved runners ONCE; unapproved legacy photos
 // are not trusted and must still be checked against their ID document.
 $column=db()->query("SHOW COLUMNS FROM runners LIKE 'verified_photo_url'")->fetch();
 if(!$column)db()->exec('ALTER TABLE runners ADD COLUMN verified_photo_url TEXT NULL');
 $column=db()->query("SHOW COLUMNS FROM runners LIKE 'verified_photo_at'")->fetch();
 if(!$column)db()->exec('ALTER TABLE runners ADD COLUMN verified_photo_at DATETIME NULL');
 db()->exec("UPDATE runners SET verified_photo_url=photo_url
   WHERE status='ผ่านการตรวจสอบ'
     AND (verified_photo_url IS NULL OR verified_photo_url='')
     AND photo_url IS NOT NULL AND photo_url<>''");
 json_out(['success'=>true,'tables'=>count($statements),'message'=>'MariaDB schema ready']);
} catch(Throwable $e) {
 error_log('Bang1 migration: '.$e->getMessage());
 json_out(['success'=>false,'message'=>'ไม่สามารถสร้างโครงสร้างฐานข้อมูล'],500);
}
