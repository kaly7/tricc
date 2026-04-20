-- Auth Center module registration for pp_center running on port 8790
INSERT INTO modules (module_key, module_name, port, path, is_enabled)
SELECT 'pp_center', 'PP Center', 8790, '/', 1
WHERE NOT EXISTS (
    SELECT 1 FROM modules WHERE module_key = 'pp_center'
);
