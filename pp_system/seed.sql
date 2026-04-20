
INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'user');

INSERT INTO users (email, name, password_hash, role_id) VALUES
('admin@example.com','Admin','$2y$10$2x2wQ9Y0QfQ2Cw5v3k8E3e1r3r6R9yTQ2I6k3vPmxzqRZr3n4b9hW',1);

INSERT INTO cities (name) VALUES ('Budapest'), ('Debrecen'), ('Szeged');

INSERT INTO pp_status (name, color_hex) VALUES
  ('Új',          '#E3F2FD'),
  ('Folyamatban', '#FFF8E1'),
  ('Kész',        '#E8F5E9'),
  ('Várakozik',   '#FCE4EC');
