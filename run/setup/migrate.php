<?php
declare(strict_types=1);
require __DIR__.'/../inc/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST' || !hash_equals(config('deploy_token'),(string)($_SERVER['HTTP_X_DEPLOY_TOKEN']??''))) json_out(['success'=>false],403);
$statements=[
"CREATE TABLE IF NOT EXISTS source_rows (sheet_name VARCHAR(120) NOT NULL, source_row INT NOT NULL, payload LONGTEXT NOT NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(sheet_name,source_row)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS schools (id BIGINT PRIMARY KEY, name VARCHAR(255) NOT NULL, nickname VARCHAR(100), active VARCHAR(60), UNIQUE KEY uq_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS coaches (id BIGINT PRIMARY KEY, name VARCHAR(255), email VARCHAR(255) NOT NULL, school_name VARCHAR(255), password_hash VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'Teacher', UNIQUE KEY uq_email(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
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
"CREATE TABLE IF NOT EXISTS import_batches (id BIGINT AUTO_INCREMENT PRIMARY KEY, imported_at DATETIME DEFAULT CURRENT_TIMESTAMP, rows_imported INT NOT NULL DEFAULT 0, note VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];
try {
 foreach($statements as $sql) db()->exec($sql);
 json_out(['success'=>true,'tables'=>count($statements),'message'=>'MariaDB schema ready']);
} catch(Throwable $e) {
 error_log('Bang1 migration: '.$e->getMessage());
 json_out(['success'=>false,'message'=>'ไม่สามารถสร้างโครงสร้างฐานข้อมูล'],500);
}
