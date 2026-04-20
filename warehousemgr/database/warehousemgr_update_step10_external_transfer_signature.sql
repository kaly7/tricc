ALTER TABLE stock_transfers
  ADD COLUMN IF NOT EXISTS receiver_signature_data LONGTEXT NULL AFTER auto_reference,
  ADD COLUMN IF NOT EXISTS receiver_signature_signed_at DATETIME NULL AFTER receiver_signature_data;
