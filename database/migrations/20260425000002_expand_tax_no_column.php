-- 修改 invoice_session 表中 tax_no 字段的长度
-- 统一社会信用代码为18位，普通税号最长约20位，统一扩展到50字符

ALTER TABLE `wxwork_invoice_session`
    MODIFY COLUMN `tax_no` VARCHAR(50) NULL DEFAULT NULL COMMENT '税号/统一社会信用代码';

-- 回滚
-- ALTER TABLE `wxwork_invoice_session`
--     MODIFY COLUMN `tax_no` VARCHAR(15) NULL DEFAULT NULL COMMENT '税号/统一社会信用代码';
