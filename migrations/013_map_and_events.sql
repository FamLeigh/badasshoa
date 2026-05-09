-- Geocoded coordinates for the embedded map + community events table.

USE badassHOA;

ALTER TABLE associations
    ADD COLUMN latitude  DECIMAL(10,7) DEFAULT NULL AFTER country,
    ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL AFTER latitude;

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    association_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(255),
    starts_at DATETIME NOT NULL,
    ends_at   DATETIME,
    audience ENUM('all','members','board') DEFAULT 'members',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_assoc_starts (association_id, starts_at),
    KEY idx_assoc_audience (association_id, audience),
    CONSTRAINT fk_event_assoc FOREIGN KEY (association_id) REFERENCES associations(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_user  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
