<?php
declare(strict_types=1);

namespace app\controller;

use app\service\JsonRpcServer;
use think\Request;
use think\Response;

/**
 * JSON-RPC HTTP 入口控制器
 *
 * 暴露路由：
 *   POST /rpc          — 处理 JSON-RPC 2.0 请求
 *   GET  /rpc/methods  — 获取所有可用方法列表（introspection）
 *   GET  /rpc/health   — RPC 服务健康检查
 */
class RpcController
{
    private JsonRpcServer $server;

    public function __construct()
    {
        $this->server = new JsonRpcServer();
    }

    /**
     * POST /rpc
     * 处理 JSON-RPC 请求 
     * @return mixed
     */
    public function index()
    {
        $result = $this->server->handle();

        if ($result === null) {
            return json(['jsonrpc' => '2.0', 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        return json($result)->header(['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * GET /rpc/methods
     * 返回所有已注册的方法列表
     */
    public function methods(): \think\Response
    {
        $methods = $this->server->listMethods();

        return json([
            'code'    => 0,
            'data'    => $methods,
            'message' => 'ok',
        ]);
    }

    /**
     * GET /rpc/health
     * 健康检查
     */
    public function health(): \think\Response
    {
        return json([
            'code'    => 0,
            'data'    => [
                'status'  => 'ok',
                'service' => 'wxwork-auto-group-rpc',
                'version' => '1.0.0',
            ],
            'message' => 'ok',
        ]);
    }
}
