CREATE DATABASE IF NOT EXISTS library_pods CHARACTER SET utf8mb4;
USE library_pods;

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sid VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  pw VARCHAR(255) NOT NULL,
  status ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active'
);

CREATE TABLE IF NOT EXISTS staff (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sid VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL DEFAULT '',
  pw VARCHAR(255) NOT NULL,
  role ENUM('librarian','admin') NOT NULL DEFAULT 'librarian'
);

-- times are stored as Unix milliseconds (BIGINT)
CREATE TABLE IF NOT EXISTS sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sid VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  pod INT NOT NULL,
  mins INT NOT NULL,
  status ENUM('pending','active','completed','cancelled') NOT NULL DEFAULT 'pending',
  reqAt BIGINT NOT NULL,
  `start` BIGINT NULL,
  `end` BIGINT NULL,
  actualEnd BIGINT NULL,
  idHeld TINYINT(1) NOT NULL DEFAULT 1,
  `override` TINYINT(1) NOT NULL DEFAULT 0,
  endedBy VARCHAR(20) NULL,
  -- generated columns + UNIQUE keys: the database itself refuses a double-booked pod
  -- or a second current session for the same student (NULL when the session is closed)
  slot INT AS (IF(status IN ('pending','active'), pod, NULL)) STORED,
  holder VARCHAR(20) AS (IF(status IN ('pending','active'), sid, NULL)) STORED,
  UNIQUE KEY one_session_per_pod (slot),
  UNIQUE KEY one_session_per_student (holder),
  INDEX (status),
  FOREIGN KEY (sid) REFERENCES students(sid)
);

CREATE TABLE IF NOT EXISTS incidents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sid VARCHAR(20) NOT NULL,
  pod INT NOT NULL,
  `text` VARCHAR(200) NOT NULL,
  `at` BIGINT NOT NULL,
  `by` VARCHAR(20) NOT NULL,
  FOREIGN KEY (sid) REFERENCES students(sid)
);

CREATE TABLE IF NOT EXISTS overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sid VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  reason VARCHAR(40) NOT NULL,
  note VARCHAR(255) NULL,
  `by` VARCHAR(20) NOT NULL,
  `at` BIGINT NOT NULL,
  pod INT NULL,
  FOREIGN KEY (sid) REFERENCES students(sid)
);

-- settings (cfg) and the demo clock offset
CREATE TABLE IF NOT EXISTS kv (
  k VARCHAR(40) PRIMARY KEY,
  v TEXT NOT NULL
);
