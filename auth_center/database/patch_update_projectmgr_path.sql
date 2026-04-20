-- Ensure ProjectMgr tile forces projectmgr mode
UPDATE modules
SET path='/index.php?module=projectmgr'
WHERE module_key='projectmgr';
