<?php
// +----------------------------------------------------------------------
// | 队列设置
// +----------------------------------------------------------------------

return [
    // 默认队列连接配置
    'default' => env('queue.driver', 'redis'),

    // 队列连接配置
    'connections' => [
        // 同步队列（调试用，生产环境不推荐）
        'sync' => [
            'type' => 'sync',
        ],

        // 数据库队列（适合没有 Redis 的环境）
        'database' => [
            'type'       => 'database',
            'queue'      => 'default',
            'table'      => 'jobs',
            'retry'      => 3,
            'timeout'    => 60,
            'sleep'      => 3,
            'max_errors' => 3,
        ],

        // Redis 队列（推荐生产环境使用）
        'redis' => [
            'type'       => 'redis',
            'queue'      => 'default',
            'host'       => env('redis.host', '127.0.0.1'),
            'port'       => env('redis.port', 6379),
            'password'   => env('redis.password', ''),
            'select'     => env('redis.queue_db', 6),
            'timeout'    => 0,
            'persistent' => false,
            'retry'      => 3,
            'timeout'    => 60,
            'sleep'      => 3,
        ],
    ],

    // 全局任务失败处理
    'failed' => [
        'type'    => 'none',   // none=仅记录日志；database=写入 failed_jobs 表
        'table'   => 'failed_jobs',
    ],
];
 