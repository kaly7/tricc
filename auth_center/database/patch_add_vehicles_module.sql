-- Add 'vehicles' module to Auth Center modules table
-- This makes "Járművek" appear in /apps.php once permission is assigned.

INSERT INTO modules (module_key, module_name, port, path, is_enabled)
SELECT 'vehicles', 'Járművek', 83, '/vehicles.php?module=vehicles', 1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE module_key='vehicles');
