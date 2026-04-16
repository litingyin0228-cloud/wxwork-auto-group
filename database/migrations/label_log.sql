-- 用户标签操作日志表
-- 用途：记录每次打标签的时间、原因、VIP到期日，用于去重和追溯
CREATE TABLE `wxwork_label_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键ID',
  `user_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户ID（联系人user_id）',
  `org_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '企业ID（org_id）',
  `label_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标签ID',
  `label_groupid` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标签组ID',
  `business_type` tinyint(4) NOT NULL DEFAULT '1' COMMENT '标签类型：1=企业标签 2=个人标签',
  `vip_end_date` date DEFAULT NULL COMMENT '触发打标签时对应的VIP到期日',
  `apply_id` int(11) DEFAULT NULL COMMENT '关联的 apply_contact_list.id（可追溯来源）',
  `is_current` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否为当前有效标签（0=已失效 1=有效）',
  `is_success` tinyint(1) NOT NULL DEFAULT '1' COMMENT '标签操作是否成功：0=失败 1=成功',
  `retry_count` tinyint(2) NOT NULL DEFAULT '0' COMMENT '失败重试次数',
  `label_ids` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '本次操作的全部标签ID列表（JSON数组）',
  `error_msg` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '失败时的错误信息',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_org_id` (`org_id`),
  KEY `idx_is_current` (`is_current`),
  KEY `idx_user_org` (`user_id`, `org_id`, `is_current`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户标签操作日志';
