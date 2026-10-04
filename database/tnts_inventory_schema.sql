-- TNTS Inventory and Property Accountability System
-- Database schema (MySQL / MariaDB, XAMPP)
-- Based on the revised ERD. Import with phpMyAdmin (Import tab) or:
--   mysql -u root < tnts_inventory_schema.sql

CREATE DATABASE IF NOT EXISTS tnts_inventory
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tnts_inventory;

-- ---------------------------------------------------------------
-- People and courses
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('supply_officer','tvl_head','teacher') NOT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courses (
  course_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_name VARCHAR(100) NOT NULL,
  program     VARCHAR(50) NULL,
  PRIMARY KEY (course_id),
  UNIQUE KEY uq_courses_name (course_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A teacher can handle more than one course
CREATE TABLE IF NOT EXISTS teacher_courses (
  user_id   INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, course_id),
  CONSTRAINT fk_tc_user   FOREIGN KEY (user_id)   REFERENCES users (user_id),
  CONSTRAINT fk_tc_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Receiving and inspection
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deliveries (
  delivery_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_number      VARCHAR(50) NULL,
  dr_number      VARCHAR(50) NULL,
  supplier_name  VARCHAR(150) NULL,
  date_received  DATE NOT NULL,
  received_by    INT UNSIGNED NOT NULL,
  dr_image_path  VARCHAR(255) NULL,   -- photo or scan of the Delivery Receipt
  status         ENUM('pending_inspection','partially_accepted','accepted')
                 NOT NULL DEFAULT 'pending_inspection',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_id),
  CONSTRAINT fk_del_user FOREIGN KEY (received_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_items (
  delivery_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_id      INT UNSIGNED NOT NULL,
  item_name        VARCHAR(150) NOT NULL,
  description      TEXT NULL,          -- specs of the item
  unit             VARCHAR(20) NULL,
  qty_delivered    INT UNSIGNED NOT NULL DEFAULT 0,
  qty_accepted     INT UNSIGNED NOT NULL DEFAULT 0,
  unit_cost        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (delivery_item_id),
  CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (delivery_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inspection_reports (
  iar_id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_id    INT UNSIGNED NOT NULL,
  iar_number     VARCHAR(50) NULL,
  inspected_by   INT UNSIGNED NOT NULL,
  date_inspected DATE NOT NULL,
  result         ENUM('complete','incomplete') NOT NULL,
  remarks        TEXT NULL,
  PRIMARY KEY (iar_id),
  UNIQUE KEY uq_iar_delivery (delivery_id),
  CONSTRAINT fk_iar_delivery FOREIGN KEY (delivery_id)  REFERENCES deliveries (delivery_id),
  CONSTRAINT fk_iar_user     FOREIGN KEY (inspected_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Inventory (accepted items)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_items (
  item_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  delivery_item_id  INT UNSIGNED NULL,
  inventory_item_no VARCHAR(50) NULL,
  item_name         VARCHAR(150) NOT NULL,
  description       TEXT NULL,
  item_type         ENUM('equipment','supply') NOT NULL DEFAULT 'equipment',
  unit              VARCHAR(20) NULL,
  unit_cost         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  quantity          INT UNSIGNED NOT NULL DEFAULT 0,   -- accepted quantity
  PRIMARY KEY (item_id),
  CONSTRAINT fk_inv_delivery_item FOREIGN KEY (delivery_item_id)
    REFERENCES delivery_items (delivery_item_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- ICS issuance
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ics (
  ics_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ics_number          VARCHAR(50) NOT NULL,
  teacher_id          INT UNSIGNED NOT NULL,
  course_id           INT UNSIGNED NOT NULL,
  issued_by           INT UNSIGNED NOT NULL,
  date_issued         DATE NOT NULL,
  teacher_signed_date DATE NULL,
  status              ENUM('active','closed') NOT NULL DEFAULT 'active',
  PRIMARY KEY (ics_id),
  UNIQUE KEY uq_ics_number (ics_number),
  CONSTRAINT fk_ics_teacher FOREIGN KEY (teacher_id) REFERENCES users (user_id),
  CONSTRAINT fk_ics_course  FOREIGN KEY (course_id)  REFERENCES courses (course_id),
  CONSTRAINT fk_ics_issuer  FOREIGN KEY (issued_by)  REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ics_items (
  ics_item_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ics_id         INT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED NOT NULL,
  qty_issued     INT UNSIGNED NOT NULL,
  item_condition ENUM('serviceable','for_repair','damaged') NOT NULL DEFAULT 'serviceable',
  PRIMARY KEY (ics_item_id),
  CONSTRAINT fk_icsi_ics  FOREIGN KEY (ics_id)  REFERENCES ics (ics_id) ON DELETE CASCADE,
  CONSTRAINT fk_icsi_item FOREIGN KEY (item_id) REFERENCES inventory_items (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Item life: repairs, borrowing, condemnation, transfers
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS repair_logs (
  repair_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ics_item_id   INT UNSIGNED NOT NULL,
  reported_by   INT UNSIGNED NOT NULL,
  issue         TEXT NOT NULL,
  action_taken  TEXT NULL,
  date_reported DATE NOT NULL,
  date_repaired DATE NULL,
  PRIMARY KEY (repair_id),
  CONSTRAINT fk_rep_icsi FOREIGN KEY (ics_item_id) REFERENCES ics_items (ics_item_id),
  CONSTRAINT fk_rep_user FOREIGN KEY (reported_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS borrow_logs (
  borrow_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ics_item_id     INT UNSIGNED NOT NULL,
  borrower_id     INT UNSIGNED NOT NULL,
  quantity        INT UNSIGNED NOT NULL DEFAULT 1,
  program_purpose VARCHAR(200) NULL,
  date_borrowed   DATE NOT NULL,
  date_returned   DATE NULL,
  status          ENUM('borrowed','returned') NOT NULL DEFAULT 'borrowed',
  PRIMARY KEY (borrow_id),
  CONSTRAINT fk_bor_icsi FOREIGN KEY (ics_item_id) REFERENCES ics_items (ics_item_id),
  CONSTRAINT fk_bor_user FOREIGN KEY (borrower_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS condemnations (
  condemnation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_number     VARCHAR(50) NULL,
  ics_item_id     INT UNSIGNED NOT NULL,
  quantity        INT UNSIGNED NOT NULL DEFAULT 1,
  reason          TEXT NOT NULL,
  requested_by    INT UNSIGNED NOT NULL,
  approved_by     INT UNSIGNED NULL,
  date_approved   DATE NULL,
  status          ENUM('requested','approved','rejected') NOT NULL DEFAULT 'requested',
  outcome         ENUM('returned','disposed') NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (condemnation_id),
  CONSTRAINT fk_con_icsi      FOREIGN KEY (ics_item_id)  REFERENCES ics_items (ics_item_id),
  CONSTRAINT fk_con_requester FOREIGN KEY (requested_by) REFERENCES users (user_id),
  CONSTRAINT fk_con_approver  FOREIGN KEY (approved_by)  REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transfers (
  transfer_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ics_item_id      INT UNSIGNED NOT NULL,
  from_teacher     INT UNSIGNED NOT NULL,
  to_teacher       INT UNSIGNED NOT NULL,
  decided_by       INT UNSIGNED NOT NULL,
  carried_out_by   INT UNSIGNED NOT NULL,
  new_ics_id       INT UNSIGNED NULL,
  reason           TEXT NULL,
  date_transferred DATE NOT NULL,
  PRIMARY KEY (transfer_id),
  CONSTRAINT fk_tr_icsi    FOREIGN KEY (ics_item_id)    REFERENCES ics_items (ics_item_id),
  CONSTRAINT fk_tr_from    FOREIGN KEY (from_teacher)   REFERENCES users (user_id),
  CONSTRAINT fk_tr_to      FOREIGN KEY (to_teacher)     REFERENCES users (user_id),
  CONSTRAINT fk_tr_decider FOREIGN KEY (decided_by)     REFERENCES users (user_id),
  CONSTRAINT fk_tr_doer    FOREIGN KEY (carried_out_by) REFERENCES users (user_id),
  CONSTRAINT fk_tr_newics  FOREIGN KEY (new_ics_id)     REFERENCES ics (ics_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Audit trail
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS history_logs (
  log_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(50) NOT NULL,
  module     VARCHAR(50) NOT NULL,
  record_id  INT UNSIGNED NULL,
  details    TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY idx_hist_user (user_id),
  CONSTRAINT fk_hist_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
