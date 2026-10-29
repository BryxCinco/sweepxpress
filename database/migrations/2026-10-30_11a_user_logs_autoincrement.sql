-- Phase 11a: ayusin ang user_logs.id (walang PRIMARY KEY at walang AUTO_INCREMENT sa ilang database).
--
-- Sintomas: ang lahat ng row ay may id = 0. Sa database na may STRICT mode, nabibigo pa nga ang pag-INSERT ng
-- log ("Field 'id' doesn't have a default value"), kaya tahimik na nawawala ang mga tala (log_user_action()
-- ay nagla-log lang sa error_log).
--
-- Ligtas patakbuhin sa kahit anong database at kahit ilang beses:
--   * Kung AUTO_INCREMENT na ang user_logs.id, walang gagawin.
--   * Kung hindi pa: bibigyan ng sunod-sunod na numero ang mga lumang row (ayon sa petsa), saka gagawing
--     PRIMARY KEY + AUTO_INCREMENT. Walang row na mabubura o magbabago ang laman maliban sa id.
-- Ayon sa README ng folder na ito: mag-mysqldump muna.

SET @needs_fix := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_logs' AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'
) AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_logs');

-- 1. Bigyan ng natatanging id ang mga lumang row (pinakaluma ang unang numero)
SET @sql := IF(@needs_fix,
    'UPDATE user_logs SET id = (@n := @n + 1) ORDER BY created_at, action_type, user_id',
    'DO 0');
SET @n := 0;
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- 2. PRIMARY KEY + AUTO_INCREMENT
SET @sql := IF(@needs_fix,
    'ALTER TABLE user_logs ADD PRIMARY KEY (id), MODIFY id INT NOT NULL AUTO_INCREMENT',
    'DO 0');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- 3. Idagdag ang indexes na inaasahan ng log_helper.php (kung wala pa)
SET @sql := IF(@needs_fix,
    'ALTER TABLE user_logs ADD INDEX idx_user_id (user_id), ADD INDEX idx_action_type (action_type), ADD INDEX idx_created_at (created_at)',
    'DO 0');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

SELECT COUNT(*) AS rows_, COUNT(DISTINCT id) AS distinct_ids, MIN(id) AS min_id, MAX(id) AS max_id FROM user_logs;
