
-- Sablonok
CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  fields_csv VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Sablon érvényessége PP-státuszokra
CREATE TABLE IF NOT EXISTS email_template_status (
  template_id INT NOT NULL,
  pp_status_id INT NOT NULL,
  PRIMARY KEY (template_id, pp_status_id),
  FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (pp_status_id) REFERENCES pp_status(id) ON DELETE CASCADE
);

-- Ki KÜLDHETI (admin mindent)
CREATE TABLE IF NOT EXISTS email_template_permissions (
  template_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (template_id, user_id),
  FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Kik KAPJÁK (regisztrált userek e-mail címe alapján)
CREATE TABLE IF NOT EXISTS email_template_recipients (
  template_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (template_id, user_id),
  FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Küldési napló (egy sablon + egy rekord csak egyszer)
CREATE TABLE IF NOT EXISTS email_sends (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  record_id BIGINT NOT NULL,
  template_id INT NOT NULL,
  sent_by INT NOT NULL,
  sent_to VARCHAR(190) NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_record_template (record_id, template_id),
  FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE,
  FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE CASCADE,
  FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
);