-- Külsős átadás PDF + email snapshot mezők
-- MariaDB 10.11+ támogatja az IF NOT EXISTS-t oszlopra.
ALTER TABLE asset_external_assignments
  ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(255) NULL AFTER signature_path,
  ADD COLUMN IF NOT EXISTS ext_email VARCHAR(255) NULL AFTER ext_phone;
