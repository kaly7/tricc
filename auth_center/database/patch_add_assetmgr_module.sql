-- Add 'assetmgr' module to Auth Center modules table
-- Adjust port/path if your assetmgr runs on a different port.

INSERT INTO modules (module_key, module_name, port, path, is_enabled)
SELECT 'assetmgr', 'Eszköz nyilvántartó', 87, '/assets.php?module=assetmgr', 1
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE module_key='assetmgr');
