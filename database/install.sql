-- 建库（如果尚未创建）
CREATE DATABASE IF NOT EXISTS `wxwork_auto_group`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `wxwork_auto_group`;

-- 建群日志表
CREATE TABLE IF NOT EXISTS `group_chat_log` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT  COMMENT '自增主键',
    `external_userid`  VARCHAR(64)      NOT NULL DEFAULT ''      COMMENT '客户 external_userid',
    `staff_userid`     VARCHAR(64)      NOT NULL DEFAULT ''      COMMENT '跟进员工 userid',
    `customer_name`    VARCHAR(128)     NOT NULL DEFAULT ''      COMMENT '客户昵称',
    `chat_id`          VARCHAR(64)      NOT NULL DEFAULT ''      COMMENT '创建的群聊 chatid',
    `group_name`       VARCHAR(128)     NOT NULL DEFAULT ''      COMMENT '群聊名称',
    `status`           TINYINT(1)       NOT NULL DEFAULT 1       COMMENT '1=成功 0=失败',
    `error_msg`        TEXT             NOT NULL                 COMMENT '失败原因',
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    INDEX `idx_external_userid` (`external_userid`),
    INDEX `idx_staff_userid`    (`staff_userid`),
    INDEX `idx_created_at`      (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自动建群日志';
