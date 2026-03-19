-- =============================================================================
-- notification_course – DDL
-- Standalone PHP app that runs alongside Moodle.
-- Table prefix : notifcourse_
-- Conventions  : BIGINT PKs (AUTO_INCREMENT), BIGINT Unix-epoch timestamps.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. notifcourse_schedule – Agendas de aula
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifcourse_schedule` (
    `id`           BIGINT       NOT NULL AUTO_INCREMENT,
    `courseid`     BIGINT       NOT NULL COMMENT 'FK mdl_course.id',
    `lesson_date`  BIGINT       NOT NULL COMMENT 'Unix timestamp da aula',
    `send_at`      BIGINT       NOT NULL COMMENT 'Unix timestamp do disparo automático',
    `link_aula`    TEXT                  DEFAULT NULL COMMENT 'Link do curso (qualquer URL)',
    `subject`      VARCHAR(255) NOT NULL,
    `body`         TEXT         NOT NULL,
    `status`       VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending | sent | cancelled',
    `timecreated`  BIGINT       NOT NULL,
    `createdby`    BIGINT       NOT NULL COMMENT 'FK mdl_user.id',
    PRIMARY KEY (`id`),
    KEY `idx_schedule_courseid`  (`courseid`),
    KEY `idx_schedule_send_at`   (`send_at`),
    KEY `idx_schedule_status`    (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. notifcourse_log – Log de todos os disparos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifcourse_log` (
    `id`                  BIGINT       NOT NULL AUTO_INCREMENT,
    `userid`              BIGINT       NOT NULL COMMENT 'FK mdl_user.id',
    `courseid`            BIGINT       NOT NULL COMMENT 'FK mdl_course.id',
    `schedule_id`         BIGINT                DEFAULT NULL COMMENT 'FK notifcourse_schedule.id – null for notification types start and end',
    `notification_type`   VARCHAR(10)  NOT NULL COMMENT 'start | lesson | end',
    `origin`              VARCHAR(10)  NOT NULL DEFAULT 'auto' COMMENT 'auto | manual',
    `manual_dispatch_id`  VARCHAR(64)           DEFAULT NULL,
    `dedupe_key`          VARCHAR(191) NOT NULL,
    `timesent`            BIGINT       NOT NULL,
    `status`              VARCHAR(20)  NOT NULL COMMENT 'success | failed | abandoned | dry_run',
    `attempts`            TINYINT      NOT NULL DEFAULT 1,
    `next_retry_at`       BIGINT                DEFAULT NULL,
    `last_error`          TEXT                  DEFAULT NULL,
    `is_simulation`       TINYINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_log_dedupe_key`              (`dedupe_key`),
    KEY `idx_log_courseid_timesent`             (`courseid`, `timesent`),
    KEY `idx_log_schedule_id_origin`            (`schedule_id`, `origin`),
    KEY `idx_log_status_next_retry_at`          (`status`, `next_retry_at`),
    KEY `idx_log_userid`                        (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. notifcourse_config – key/value configuration
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifcourse_config` (
    `id`            BIGINT       NOT NULL AUTO_INCREMENT,
    `config_key`    VARCHAR(100) NOT NULL,
    `config_value`  TEXT                  DEFAULT NULL,
    `timemodified`  BIGINT       NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default configuration rows
-- UNIX_TIMESTAMP() is used so timemodified is set to the moment of installation.
INSERT INTO `notifcourse_config` (`config_key`, `config_value`, `timemodified`) VALUES
    ('start_subject',     NULL,               UNIX_TIMESTAMP()),
    ('start_body',        NULL,               UNIX_TIMESTAMP()),
    ('start_hours_before','24',               UNIX_TIMESTAMP()),
    ('end_subject',       NULL,               UNIX_TIMESTAMP()),
    ('end_body',          NULL,               UNIX_TIMESTAMP()),
    ('end_hours_after',   '24',               UNIX_TIMESTAMP()),
    ('end_survey_url',    NULL,               UNIX_TIMESTAMP()),
    ('batch_size',        '50',               UNIX_TIMESTAMP()),
    ('max_attempts',      '3',                UNIX_TIMESTAMP()),
    ('display_timezone',  'America/Sao_Paulo',UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;  -- no-op if already seeded

-- -----------------------------------------------------------------------------
-- 4. notifcourse_categories – Active categories
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifcourse_categories` (
    `id`          BIGINT  NOT NULL AUTO_INCREMENT,
    `categoryid`  BIGINT  NOT NULL COMMENT 'FK mdl_course_categories.id',
    `active`      TINYINT NOT NULL DEFAULT 1,
    `timecreated` BIGINT  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_categoryid` (`categoryid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
