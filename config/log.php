<?php

// +----------------------------------------------------------------------
// | 日志设置
// +----------------------------------------------------------------------
return [
    // 默认日志记录通道
    'default'      => env('log.channel', 'file'),
    // 日志记录级别
    'level'        => ["error", "warning", "info", "debug"],
    // 日志类型记录的通道 ['error'=>'email',...]
    'type_channel' => [],
    // 关闭全局日志写入
    'close'        => false,
    // 全局日志处理 支持闭包
    'processor'    => null,

    // 日志通道列表
    'channels'     => [
        'file' => [
            // 日志记录方式
            'type'           => 'File',
            // 日志保存目录
            'path'           => '',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => [],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
        // 队列任务日志（写入 runtime/log/job/）
        'job' => [
            'type'           => 'File',
            'path'           => runtime_path() . 'log' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR,
            'single'         => false,
            'apart_level'    => ['error', 'warning', 'info', 'debug'],
            'max_files'      => 30,
            'json'           => false,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => true,
        ],
    ],

];
