-- Add optional return note and store who the asset was returned to (HR employee id)
ALTER TABLE asset_external_assignments
  ADD COLUMN returned_to_employee_id INT NULL AFTER returned_by_user_id,
  ADD COLUMN return_note TEXT NULL AFTER returned_to_employee_id;
