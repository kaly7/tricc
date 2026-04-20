-- Recommended indexes for faster pagination/export (projectmgr)
-- Run in DB: projectmgr
-- If an index already exists, MariaDB will error; you can ignore or drop existing ones first.

-- Vehicle issues: per-vehicle listing ordered by reported_date/id
CREATE INDEX idx_vehicle_issues_vehicle_reported_id
  ON vehicle_issues (vehicle_id, reported_date, id);

-- Vehicle service entries: per-vehicle listing ordered by service_date/id
CREATE INDEX idx_vse_vehicle_date_id
  ON vehicle_service_entries (vehicle_id, service_date, id);

-- Vehicle fuel entries: per-vehicle listing ordered by fueled_at/id, and import lookups
CREATE INDEX idx_vehicle_fuel_vehicle_fueled_id
  ON vehicle_fuel_entries (vehicle_id, fueled_at, id);
CREATE INDEX idx_vehicle_fuel_import_id
  ON vehicle_fuel_entries (import_id);

-- Vehicle tires: vehicle-specific listing (vehicle.php orders by is_archived ASC, id DESC)
CREATE INDEX idx_vehicle_tires_vehicle_archived_id
  ON vehicle_tires (vehicle_id, is_archived, id);

-- Fuel imports: listing by uploaded_at
CREATE INDEX idx_fuel_imports_uploaded_at_id
  ON fuel_imports (uploaded_at, id);

-- Audit log: current implementation filters by LIKE on changed_fields, which cannot be indexed effectively.
-- If you later add columns (entity_type, entity_id) and query by them, add:
-- CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id, created_at, id);
