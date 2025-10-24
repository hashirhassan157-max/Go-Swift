-- Go Swift Database Schema
-- MySQL / MariaDB

CREATE DATABASE IF NOT EXISTS goswift CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE goswift;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    phone VARCHAR(20) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'rider') NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(64),
    profile_photo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_role (role)
) ENGINE=InnoDB;

-- Cities table
CREATE TABLE cities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB;

-- Areas table
CREATE TABLE areas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    INDEX idx_city (city_id),
    INDEX idx_name (name)
) ENGINE=InnoDB;

-- Vehicles table
CREATE TABLE vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('Car', 'Bike', 'Van') NOT NULL,
    make VARCHAR(50) NOT NULL,
    model VARCHAR(50) NOT NULL,
    year INT NOT NULL,
    plate_number VARCHAR(20) UNIQUE NOT NULL,
    color VARCHAR(30),
    capacity INT NOT NULL,
    city_id INT NOT NULL,
    area_ids JSON,
    status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    docs_json JSON,
    vehicle_photos JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (city_id) REFERENCES cities(id),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_city (city_id)
) ENGINE=InnoDB;

-- Trips/Posts table
CREATE TABLE trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    user_id INT NOT NULL,
    departure_city_id INT NOT NULL,
    departure_area_id INT,
    arrival_city_id INT NOT NULL,
    arrival_area_id INT,
    depart_datetime DATETIME NOT NULL,
    seats_total INT NOT NULL,
    seats_left INT NOT NULL,
    price_per_seat DECIMAL(10, 2) DEFAULT 0,
    luggage_allowance VARCHAR(100),
    notes TEXT,
    allow_partial_booking BOOLEAN DEFAULT TRUE,
    is_recurring BOOLEAN DEFAULT FALSE,
    recurring_pattern VARCHAR(50),
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (departure_city_id) REFERENCES cities(id),
    FOREIGN KEY (departure_area_id) REFERENCES areas(id),
    FOREIGN KEY (arrival_city_id) REFERENCES cities(id),
    FOREIGN KEY (arrival_area_id) REFERENCES areas(id),
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_user (user_id),
    INDEX idx_departure_city (departure_city_id),
    INDEX idx_arrival_city (arrival_city_id),
    INDEX idx_depart_datetime (depart_datetime),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Bookings table
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_id INT NOT NULL,
    rider_user_id INT NOT NULL,
    seats_booked INT NOT NULL,
    total_price DECIMAL(10, 2) DEFAULT 0,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    booking_code VARCHAR(20) UNIQUE,
    cancellation_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trip (trip_id),
    INDEX idx_rider (rider_user_id),
    INDEX idx_status (status),
    INDEX idx_booking_code (booking_code)
) ENGINE=InnoDB;

-- Messages table
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    from_user_id INT NOT NULL,
    to_user_id INT NOT NULL,
    body TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_booking (booking_id),
    INDEX idx_from_user (from_user_id),
    INDEX idx_to_user (to_user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Reviews table
CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    trip_id INT NOT NULL,
    booking_id INT NOT NULL,
    reviewer_user_id INT NOT NULL,
    reviewed_user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_trip (trip_id),
    INDEX idx_reviewed_user (reviewed_user_id),
    INDEX idx_rating (rating)
) ENGINE=InnoDB;

-- Admin users table
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'moderator') DEFAULT 'moderator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- Notifications table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Sample cities data
INSERT INTO cities (name) VALUES 
('Karachi'), ('Lahore'), ('Islamabad'), ('Rawalpindi'), 
('Faisalabad'), ('Multan'), ('Peshawar'), ('Quetta');

-- Sample areas for major cities
INSERT INTO areas (city_id, name) VALUES 
-- Karachi areas
(1, 'Clifton'), (1, 'Defence'), (1, 'Gulshan-e-Iqbal'), (1, 'North Nazimabad'), (1, 'Saddar'),
-- Lahore areas
(2, 'Gulberg'), (2, 'Model Town'), (2, 'Johar Town'), (2, 'DHA'), (2, 'Bahria Town'),
-- Islamabad areas
(3, 'F-6'), (3, 'F-7'), (3, 'F-8'), (3, 'G-9'), (3, 'Blue Area'),
-- Rawalpindi areas
(4, 'Satellite Town'), (4, 'Bahria Town'), (4, 'Chaklala'), (4, 'Saddar'), (4, 'PWD');