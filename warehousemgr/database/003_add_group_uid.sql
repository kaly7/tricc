ALTER TABLE tt_entries
  ADD COLUMN group_uid CHAR(36) DEFAULT NULL AFTER updated_at,
  ADD KEY idx_tt_entries_group (group_uid);
