<?php

use think\facade\Db;

if (!function_exists('confirm_round_column_exists')) {
    function confirm_round_column_exists(): bool
    {
        static $checked = false;
        if ($checked) {
            return true;
        }
        $prefix = config('database.connections.mysql.prefix');
        $table  = config('database.connections.mysql.database') . '.' . $prefix . 'invoice_session';
        $cols   = array_column(Db::query("SHOW COLUMNS FROM `{$table}`"), 'Field');
        $checked = in_array('confirm_round', $cols);
        return $checked;
    }
}

return [
    'up' => function () {
        if (!confirm_round_column_exists()) {
            Db::execute("
                ALTER TABLE `wxwork_invoice_session`
                    ADD COLUMN `confirm_round` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '用户确认轮次（1=首次确认，N=N次确认）' AFTER `step`
            ");
        }
    },
    'down' => function () {
        if (confirm_round_column_exists()) {
            Db::execute("ALTER TABLE `wxwork_invoice_session` DROP COLUMN `confirm_round`");
        }
    },
];
