DROP DATABASE IF EXISTS music_db;
CREATE DATABASE music_db;
USE music_db;  -- ensure subsequent statements target music_db

/********************
 * Reference tables *
 ********************/
CREATE TABLE genres (
    genre_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE styles (
    style_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

/********************
 * Core entities    *
 ********************/
CREATE TABLE sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    date DATE NOT NULL,
    -- published_at is the original publication timestamp if available
    published_at DATETIME DEFAULT NULL,
    duration_seconds INT NULL,  -- store as seconds for easy math/queries
    cover_image_url VARCHAR(1024),
    location VARCHAR(255),
    description TEXT,
    summary TEXT,
    keywords TEXT,
    rating DECIMAL(2,1),             -- 1-5 optional
    explicit TINYINT(1) DEFAULT 0,
    -- New fields to support multiple event types/orgs per date
    event_type ENUM('band','wedding') DEFAULT NULL,
    org_name VARCHAR(128) NOT NULL DEFAULT 'default',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Allow multiple sessions per date by org
    CONSTRAINT unique_session_date_org UNIQUE (date, org_name)
);

CREATE TABLE songs (
    song_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    duration_seconds INT NULL,  -- store as seconds
    genre_id INT,
    style_id INT,
    -- Broaden to support generic event labels (e.g., wedding table names)
    type ENUM('loop', 'song', 'event_label') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (genre_id) REFERENCES genres(genre_id) ON DELETE SET NULL,
    FOREIGN KEY (style_id) REFERENCES styles(style_id) ON DELETE SET NULL
);

/********************
 * Media & linking  *
 ********************/
CREATE TABLE files (
    file_id INT PRIMARY KEY AUTO_INCREMENT,
    file_name VARCHAR(4096) NOT NULL,
    file_type ENUM('audio', 'video') NOT NULL,
    -- Link directly to session for easy association and per-session sequencing
    session_id INT NULL,
    seq INT NULL,
    duration_seconds INT NULL,
    -- Useful metadata for uploads
    media_info JSON NULL,
    media_info_tool VARCHAR(255) NULL,
    mime_type VARCHAR(255) NULL,
    size_bytes BIGINT NULL,
    checksum_sha256 CHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_session FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE SET NULL,
    CONSTRAINT uq_files_session_seq UNIQUE (session_id, seq)
);

-- Many-to-many: sessions ↔ songs
CREATE TABLE session_songs (
    session_id INT NOT NULL,
    song_id INT NOT NULL,
    position INT NULL,              -- optional track order within the session
    duration_seconds INT NULL,      -- per-session cut length if applicable
    PRIMARY KEY (session_id, song_id),
    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (song_id) REFERENCES songs(song_id) ON DELETE CASCADE
);

-- Many-to-many: songs ↔ files
CREATE TABLE song_files (
    song_id INT NOT NULL,
    file_id INT NOT NULL,
    PRIMARY KEY (song_id, file_id),
    FOREIGN KEY (song_id) REFERENCES songs(song_id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(file_id) ON DELETE CASCADE
);

/********************
 * People           *
 ********************/
CREATE TABLE musicians (
    musician_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Many-to-many: sessions ↔ musicians (with optional role)
CREATE TABLE session_musicians (
    session_id INT NOT NULL,
    musician_id INT NOT NULL,
    role VARCHAR(255) NULL,
    PRIMARY KEY (session_id, musician_id),
    UNIQUE KEY uniq_session_musician_role (session_id, musician_id, role),
    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (musician_id) REFERENCES musicians(musician_id) ON DELETE CASCADE
);

/********************
 * Auth             *
 ********************/
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash CHAR(60) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  activation_token CHAR(32) NULL,
  reset_token CHAR(32) NULL,
  reset_expires DATETIME NULL,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

