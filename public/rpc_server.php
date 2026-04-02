<?php
/**
 * JSON-RPC 2.0 独立服务入口（兼容 PHP 内置服务器）
 *
 * 使用方式：
 *   方式一（PHP 内置服务器，推荐开发环境）：
 *     php -S 0.0.0.0:8090 -t public public/rpc.php
 *
 *   方式二（Workerman，生产环境推荐）：
 *     1. composer require workerman/workerman
 *     2. 修改下面的 host/port 为实际值
 *     3. php public/rpc_server.php start
 *
 *   方式三（Swoole，生产环境推荐）：
 *     1. composer require swoole/swoole
 *     2. php public/rpc_server.php start --type=swoole
 *
 * 认证方式：在请求头中携带：
 *   X-RPC-Key: your_auth_key
 */

require __DIR__ . '/../vendor/autoload.php';

use think\App;

// ThinkPHP App 构造函数只接收根目录路径，运行时配置由 config/app.php 自动加载
$app = new App(__DIR__ . '/..');

// 将 RPC 路由注册到默认 HTTP 应用
$http = $app->http;

// 启动 HTTP 应用（ThinkPHP 会通过 public/index.php 的路由匹配到 /rpc）
$response = $http->run();

$response->send();

$http->end($response);
