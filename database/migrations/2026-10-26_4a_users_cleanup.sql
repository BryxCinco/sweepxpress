-- ====================================================================
-- Phase 4a: users table cleanup
-- --------------------------------------------------------------------
-- Pinatakbo at pumasa sa local (MariaDB 10.4) noong 2026-10-26.
-- Sa live server: PATAKBUHIN NANG ISA-ISANG STEP at tingnan ang
-- verification bago lumipat sa susunod. Huwag patakbuhin nang buo.
--
-- Kailangan: naka-deploy na ang Phase 4a code (commit 09336cb o mas bago).
-- ====================================================================

-- --------------------------------------------------------------------
-- STEP 0 (sa labas ng MySQL): FULL BACKUP MUNA
-- --------------------------------------------------------------------
-- mysqldump -u USER -p --single-transaction --routines --triggers --events
--   --default-character-set=utf8mb4 --result-file=backup_before_4a.sql DBNAME
-- Tiyaking nagtatapos ang file sa "-- Dump completed".
-- Huwag ilagay ang backup sa loob ng project folder.

-- --------------------------------------------------------------------
-- STEP 1: Backup table sa loob ng DB (para sa mabilis na pagkuha ng lumang value)
-- --------------------------------------------------------------------
CREATE TABLE users_backup_4a AS SELECT * FROM users;

-- verify: dapat magkapareho ang dalawang bilang
-- SELECT (SELECT COUNT(*) FROM users) AS users_rows, (SELECT COUNT(*) FROM users_backup_4a) AS backup_rows;

-- --------------------------------------------------------------------
-- STEP 2: I-merge ang data mula sa lumang column papunta sa canonical
-- (phone_number → phone, birth_date → birthdate, profileimage → profile_image)
-- Pinupunan lang ang canonical kapag blangko ito; hindi pinapalitan ang may laman.
-- --------------------------------------------------------------------
START TRANSACTION;

UPDATE users SET phone = phone_number
  WHERE (phone IS NULL OR phone = '') AND phone_number IS NOT NULL AND phone_number <> '';
UPDATE users SET birthdate = birth_date
  WHERE birthdate IS NULL AND birth_date IS NOT NULL;
UPDATE users SET profile_image = profileimage
  WHERE (profile_image IS NULL OR profile_image = '') AND profileimage IS NOT NULL AND profileimage <> '';

-- verify: LAHAT dapat 0 bago mag-COMMIT. Kapag may hindi 0 → ROLLBACK.
SELECT
  COALESCE(SUM(phone_number <> '' AND (phone IS NULL OR phone = '')), 0)              AS phone_lost,
  COALESCE(SUM(birth_date IS NOT NULL AND birthdate IS NULL), 0)                       AS bdate_lost,
  COALESCE(SUM(profileimage <> '' AND (profile_image IS NULL OR profile_image = '')), 0) AS img_lost
FROM users;

COMMIT;   -- o ROLLBACK;

-- --------------------------------------------------------------------
-- STEP 3: Tanggalin ang mga lumang column
-- ⚠ HINDI ito nababawi ng ROLLBACK. Tiyaking tapos ang Step 0–2.
-- --------------------------------------------------------------------
ALTER TABLE users
  DROP COLUMN phone_number,
  DROP COLUMN birth_date,
  DROP COLUMN profileimage;

-- verify: dapat walang lumabas
-- SELECT COLUMN_NAME FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--   AND COLUMN_NAME IN ('phone_number', 'birth_date', 'profileimage');

-- --------------------------------------------------------------------
-- STEP 4: Username backfill para sa mga blangko
-- Format: unang salita ng pangalan (lowercase, a-z0-9 lang, max 20) + id → "juan12"
-- Laging unique dahil unique ang id.
--
-- Kailangan ng REGEXP_REPLACE: MariaDB 10.0.5+ o MySQL 8.0+.
-- Para sa MySQL 5.7, gamitin ang ALTERNATIBO sa ibaba.
-- --------------------------------------------------------------------
UPDATE users
SET username = CONCAT(
      COALESCE(NULLIF(LEFT(REGEXP_REPLACE(LOWER(SUBSTRING_INDEX(TRIM(name), ' ', 1)), '[^a-z0-9]', ''), 20), ''), 'user'),
      id)
WHERE username IS NULL OR username = '';

-- ALTERNATIBO para sa MySQL 5.7 (walang REGEXP_REPLACE): "user" + id → "user12"
-- UPDATE users SET username = CONCAT('user', id) WHERE username IS NULL OR username = '';

-- verify: parehong dapat 0 / walang lumabas
-- SELECT COUNT(*) FROM users WHERE username IS NULL OR username = '';
-- SELECT username, COUNT(*) c FROM users GROUP BY username HAVING c > 1;

-- --------------------------------------------------------------------
-- STEP 5: Gawing UNIQUE ang username
-- --------------------------------------------------------------------
ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);

-- verify: dapat may uq_users_username
-- SHOW INDEX FROM users;

-- --------------------------------------------------------------------
-- CLEANUP (pagkatapos ng ilang araw na stable)
-- --------------------------------------------------------------------
-- DROP TABLE users_backup_4a;

-- --------------------------------------------------------------------
-- ROLLBACK (kung kailangan)
-- --------------------------------------------------------------------
-- Para sa buong rollback, i-import ang mysqldump mula sa Step 0.
-- (Walang index/AUTO_INCREMENT ang users_backup_4a, kaya hindi ito pamalit sa buong table.)
