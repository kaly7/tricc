-- Add PBX kind (analog/digital) to pbx_systems
-- If you already have a similar column, skip this.
ALTER TABLE pbx_systems
  ADD COLUMN kind ENUM('analog','digital') NOT NULL DEFAULT 'analog' AFTER location;
