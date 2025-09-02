DROP DATABASE IF EXISTS music_db;
CREATE DATABASE music_db;
USE music_db;  -- 🔹 This ensures all table creations happen inside music_db

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

CREATE TABLE sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255),
    date DATE NOT NULL,
    -- pub_date is the original publication timestamp if available
    pub_date DATETIME DEFAULT NULL,
    duration TIME,
    crew TEXT,
    image_path VARCHAR(255),
    location VARCHAR(255),
    description TEXT,
    summary TEXT,
    keywords TEXT,
    rating TEXT,
    explicit BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT unique_jam_date UNIQUE (date)  -- ✅ Ensures only one jam per day
);

CREATE TABLE songs (
    song_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    duration TIME,
    genre_id INT,
    style_id INT,
    session_id INT,
    type ENUM('loop', 'song') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (genre_id) REFERENCES genres(genre_id) ON DELETE SET NULL,
    FOREIGN KEY (style_id) REFERENCES styles(style_id) ON DELETE SET NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE
);

CREATE TABLE files (
    file_id INT PRIMARY KEY AUTO_INCREMENT,
    file_name VARCHAR(4096) NOT NULL,
    file_type ENUM('audio', 'video') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Junction table for many-to-many relationship between Jam Sessions and songs
CREATE TABLE session_songs (
    session_id INT NOT NULL,
    song_id INT NOT NULL,
    PRIMARY KEY (session_id, song_id),
    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (song_id) REFERENCES songs(song_id) ON DELETE CASCADE
);

-- Junction table for many-to-many relationship between songs and files
CREATE TABLE song_files (
    song_id INT NOT NULL,
    file_id INT NOT NULL,
    PRIMARY KEY (song_id, file_id),
    FOREIGN KEY (song_id) REFERENCES songs(song_id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(file_id) ON DELETE CASCADE
);

-- New table: musicians
CREATE TABLE musicians (
    musician_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Junction table for many-to-many relationship between Jam Sessions and musicians
CREATE TABLE session_musicians (
    session_id INT NOT NULL,
    musician_id INT NOT NULL,
    PRIMARY KEY (session_id, musician_id),
    FOREIGN KEY (session_id) REFERENCES sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (musician_id) REFERENCES musicians(musician_id) ON DELETE CASCADE
);

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

