ALTER TABLE library_items
  ADD COLUMN category VARCHAR(32) NOT NULL DEFAULT 'uncategorized' AFTER width_pct;

UPDATE library_items
SET category = 'uncategorized'
WHERE category IS NULL OR category = '';
