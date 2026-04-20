INSERT INTO auth_db.modules (module_key, module_name, port, path, is_enabled)
SELECT 'warehousemgr', 'Raktárkezelő', 8789, '/', 1
WHERE NOT EXISTS (
    SELECT 1 FROM auth_db.modules WHERE module_key = 'warehousemgr'
);
