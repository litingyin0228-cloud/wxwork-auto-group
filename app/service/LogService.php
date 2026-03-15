<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Log as ThinkLog;

/**
 * 统一日志服务
 * 所有日志通过单一参数（数组）记录，格式：['tag' => '', 'message' => '', 'data' => []]
 */
class LogService
{
    /**
     * 记录 INFO 级别日志
     * @param array $log 单一参数，结构：['tag' => string, 'message' => string, 'data' => array]
     */
    public static function info(array $log): void
    {
        ThinkLog::info(self::format($log));
    }

    /**
     * 记录 ERROR 级别日志
     * @param array $log 单一参数，结构：['tag' => string, 'message' => string, 'data' => array]
     */
    public static function error(array $log): void
    {
        ThinkLog::error(self::format($log));
    }

    /**
     * 记录 WARNING 级别日志
     * @param array $log 单一参数，结构：['tag' => string, 'message' => string, 'data' => array]
     */
    public static function warning(array $log): void
    {
        ThinkLog::warning(self::format($log));
    }

    /**
     * 记录 DEBUG 级别日志
     * @param array $log 单一参数，结构：['tag' => string, 'message' => string, 'data' => array]
     */
    public static function debug(array $log): void
    {
        ThinkLog::debug(self::format($log));
    }

    /**
     * 格式化日志输出
     */
    private static function format(array $log): string
    {
        $tag     = $log['tag'] ?? 'App';
        $message = $log['message'] ?? '';
        $data    = $log['data'] ?? [];

        // 格式：[TAG] message | data: {...}
        $output = "[{$tag}] {$message}";
        
        if (!empty($data)) {
            $output .= ' | data: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return $output;
    }
}
