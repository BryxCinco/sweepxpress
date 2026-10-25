-- ====================================================================
-- Phase 4b-3: rate_limits table
-- --------------------------------------------------------------------
-- Ginagamit ng rate_limited() / rate_limit_record() sa config.php para sa
-- register OTP, login, contact at forgot password.
-- Pinatakbo sa local (MariaDB 10.4) noong 2026-10-26.
--
-- Ligtas patakbuhin ulit (IF NOT EXISTS). Ligtas ding mauna ang code:
-- "fail open" ang helpers kapag wala pa ang table (walang limit, pero hindi nagka-crash).
-- ====================================================================

CREATE TABLE IF NOT EXISTS rate_limits (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action     VARCHAR(30)  NOT NULL,   -- 'register_otp', 'login_fail_ip', 'login_fail_email', 'contact', 'forgot_ip'
  rl_key     VARCHAR(64)  NOT NULL,   -- IP address, 'user:<id>', o sha256(email)
  created_at DATETIME     NOT NULL,
  KEY idx_action_key_time (action, rl_key, created_at),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- verify: 4 columns, 3 indexes (PRIMARY, idx_action_key_time, idx_created_at)
-- SHOW COLUMNS FROM rate_limits;
-- SHOW INDEX FROM rate_limits;

-- ROLLBACK (kung kailangan): walang ibang table na umaasa rito.
-- DROP TABLE rate_limits;
