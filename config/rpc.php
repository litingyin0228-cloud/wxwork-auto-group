<?php
// +----------------------------------------------------------------------
// | RPC 服务配置
// +----------------------------------------------------------------------

return [
    // RPC 服务开关
    'enable' => env('RPC_ENABLE', false),

    // 服务端地址（仅客户端模式使用）
    'host'   => env('RPC_HOST', '127.0.0.1'),
    'port'   => env('RPC_PORT', 8090),

    // RPC 服务端口（服务端模式监听）
    'server' => [
        'host'  => env('RPC_SERVER_HOST', '0.0.0.0'),
        'port'  => env('RPC_SERVER_PORT', 8090),
        'debug' => env('APP_DEBUG', false),
    ],

    // 认证密钥（调用方需在请求头中携带）
    'auth' => [
        'key'    => env('RPC.RPC_AUTH_KEY', 'change_me_rpc_secret_key'),
        'enable' => env('RPC.RPC_AUTH_ENABLE', true),
    ],

    // 可暴露的服务列表（class 或 service.name 格式）
    'services' => [
        // 格式1: 'WxWorkService'    → 暴露 app\service\WxWorkService
        // 格式2: 'wxwork' => 'WxWorkService' → 以 wxwork 为命名空间暴露
        'wxwork'   => 'WxWorkService',
        // 'juhebot'  => 'JuhebotService',
        // 'autogroup' => 'AutoGroupService',
    ],

    // JSON-RPC 请求超时时间（秒）
    'timeout' => env('RPC_TIMEOUT', 30),
];
