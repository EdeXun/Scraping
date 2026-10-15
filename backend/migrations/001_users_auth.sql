USE radar_tienda;

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL
);

ALTER TABLE products
  ADD COLUMN user_id CHAR(36) NULL AFTER id,
  ADD INDEX idx_products_user (user_id),
  ADD CONSTRAINT fk_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
