ALTER TABLE asset_external_assignments
  ADD COLUMN source_warehouse_id INT UNSIGNED NULL AFTER assigned_by_user_id,
  ADD COLUMN returned_to_warehouse_id INT UNSIGNED NULL AFTER returned_to_employee_id,
  ADD KEY idx_aea_source_warehouse (source_warehouse_id),
  ADD KEY idx_aea_returned_to_warehouse (returned_to_warehouse_id);
