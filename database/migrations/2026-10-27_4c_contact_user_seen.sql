-- ====================================================================
-- Phase 4c: contact_messages.user_seen
-- --------------------------------------------------------------------
-- Dating idinadagdag ng contact.php sa runtime (ALTER TABLE sa page load).
-- Inilipat dito dahil kadalasang walang ALTER privilege ang DB user ng
-- website sa live server, kaya magka-crash ang Contact page.
--
-- Kung umiiral na ang column (hal. dati nang nabuksan ang Contact page),
-- laktawan ito. Tingnan muna:
--   SHOW COLUMNS FROM contact_messages LIKE 'user_seen';
-- ====================================================================

ALTER TABLE contact_messages ADD COLUMN user_seen TINYINT(1) DEFAULT 0;

-- MariaDB lang (ligtas patakbuhin ulit):
-- ALTER TABLE contact_messages ADD COLUMN IF NOT EXISTS user_seen TINYINT(1) DEFAULT 0;

-- verify:
-- SHOW COLUMNS FROM contact_messages LIKE 'user_seen';   → tinyint(1), default 0
