<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Log as ThinkLog;

/**
 * 统一日志服务
 * 所有日志通过单一参数（数组）记录，格式：['tag' => '', 'message' => '', 'data' => []]
 *
 * 用法：
 *   LogService::info(['tag' => 'Foo', 'msg' => 'hello']);  // 默认写 default 通道
 *   LogService::info(['tag' => 'Foo', 'msg' => 'hello'], 'job');  // 写 job 通道
 */
class LogService
{
    /**
     * 记录 INFO 级别日志
     * @param array  $log    参数：['tag' => string, 'msg' => string, 'data' => array]
     * @param string $channel 日志通道，默认 'file'
     */
    public static function info(array $log, string $channel = ''): void
    {
        self::write('info', $log, $channel);
    }

    /**
     * 记录 ERROR 级别日志
     */
    public static function error(array $log, string $channel = ''): void
    {
        self::write('error', $log, $channel);
    }

    /**
     * 记录 WARNING 级别日志
     */
    public static function warning(array $log, string $channel = ''): void
    {
        self::write('warning', $log, $channel);
    }

    /**
     * 记录 DEBUG 级别日志
     */
    public static function debug(array $log, string $channel = ''): void
    {
        self::write('debug', $log, $channel);
    }

    /**
     * 统一写入口
     */
    private static function write(string $level, array $log, string $channel): void
    {
        $msg = self::format($log);
        if ($channel !== '') {
            ThinkLog::channel($channel)->{$level}($msg);
        } else {
            ThinkLog::{$level}($msg);
        }
    }

    /**
     * 格式化日志输出
     */
    private static function format(array $log): string
    {
        $tag     = $log['tag'] ?? 'App';
        $message = $log['msg']  ?? ($log['message'] ?? '');
        $data    = $log['data'] ?? [];

        $output = "[{$tag}] {$message}";

        if (!empty($data)) {
            $output .= ' | data: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return $output;
    }
}
