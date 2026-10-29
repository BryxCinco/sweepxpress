-- Phase 8e: talaan ng mga tanong sa Admin Copilot (audit at gastos). Dagdag lang; ligtas patakbuhin ulit.
--
-- Itinatala: sino, kailan, ang tanong (hanggang 500 character), anong mga tool ang ginamit, bilang ng tokens,
-- tagal sa millisecond, at status (ok, error, limited). HINDI itinatala ang sagot ng AI.
--
-- Ayon sa README ng folder na ito: mag-mysqldump muna. Walang epekto ang table na ito sa lumang code,
-- kaya puwede itong patakbuhin bago o pagkatapos i-upload ang code.

CREATE TABLE IF NOT EXISTS copilot_log (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT DEFAULT NULL,
    question          VARCHAR(500) NOT NULL,
    tools             VARCHAR(255) DEFAULT NULL,
    prompt_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
    completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    ms                INT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('ok','error','limited') NOT NULL DEFAULT 'ok',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_copilot_user (user_id, created_at),
    KEY idx_copilot_created (created_at),
    CONSTRAINT fk_copilot_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
