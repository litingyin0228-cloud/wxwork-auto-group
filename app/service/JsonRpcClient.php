<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * JSON-RPC 2.0 客户端
 *
 * 用法示例：
 *
 * ```php
 * $client = new JsonRpcClient([
 *     'host' => 'http://127.0.0.1:8080',
 *     'key'  => 'your_rpc_auth_key',
 * ]);
 *
 * // 调用 WxWorkService::createGroupChat()
 * $result = $client->call('wxwork.createGroupChat', [
 *     'chatName' => '测试群',
 *     'owner'    => 'zhangsan',
 *     'userList' => ['zhangsan', 'lisi'],
 * ]);
 *
 * // 批量调用
 * $results = $client->batch([
 *     ['method' => 'wxwork.getAccessToken', 'params' => ['app']],
 *     ['method' => 'juhebot.getChatList', 'params' => [1, 20]],
 * ]);
 * ```
 */
class JsonRpcClient
{
    private Client $http;
    private string $host;
    private string $authKey;
    private int $timeout;

    private int $id = 1;

    public function __construct(array $config = [])
    {
        $rpcConfig = config('rpc') ?? [];

        $this->host     = $config['host']     ?? env('RPC_HOST', 'http://127.0.0.1:8090');
        $this->authKey  = $config['key']      ?? $rpcConfig['auth']['key'] ?? '';
        $this->timeout = (int) ($config['timeout'] ?? $rpcConfig['timeout'] ?? 30);

        $this->http = new Client([
            'base_uri' => $this->host,
            'timeout'  => $this->timeout,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'X-RPC-Key'     => $this->authKey,
            ],
        ]);
    }

    /**
     * 同步调用远程方法
     *
     * @param string $method  方法名，如 "wxwork.createGroupChat"
     * @param array  $params  参数（支持位置参数或命名参数）
     * @return mixed          返回远程方法的结果
     * @throws RuntimeException
     */
    public function call(string $method, array $params = []): mixed
    {
        $request = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $this->id++,
        ];

        $payload = json_encode($request, JSON_UNESCAPED_UNICODE);

        LogService::info([
            'tag'     => 'JsonRpcClient',
            'message' => 'RPC 请求发送',
            'data'    => [
                'host'   => $this->host,
                'method' => $method,
                'params' => $params,
            ],
        ]);

        try {
            $response = $this->http->post('/rpc', [
                'body'    => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-RPC-Key'    => $this->authKey,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (($body['jsonrpc'] ?? '') !== '2.0') {
                throw new RuntimeException("Invalid JSON-RPC response: " . json_encode($body));
            }

            if (isset($body['error'])) {
                LogService::error([
                    'tag'     => 'JsonRpcClient',
                    'message' => 'RPC 调用失败',
                    'data'    => [
                        'method' => $method,
                        'error'  => $body['error'],
                    ],
                ]);
                throw new RuntimeException(
                    "RPC Error [{$body['error']['code']}]: {$body['error']['message']}"
                );
            }

            LogService::info([
                'tag'     => 'JsonRpcClient',
                'message' => 'RPC 调用成功',
                'data'    => [
                    'method' => $method,
                    'result' => $body['result'] ?? null,
                ],
            ]);

            return $body['result'] ?? null;
        } catch (GuzzleException $e) {
            LogService::error([
                'tag'     => 'JsonRpcClient',
                'message' => 'RPC 请求异常',
                'data'    => [
                    'method' => $method,
                    'error'  => $e->getMessage(),
                ],
            ]);
            throw new RuntimeException('RPC 请求失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 批量调用（JSON-RPC Batch Request）
     *
     * @param array $calls  每项为 ['method' => string, 'params' => array]
     * @return array        每项为响应结果，顺序与请求一致
     */
    public function batch(array $calls): array
    {
        $requests = [];
        foreach ($calls as $call) {
            $requests[] = [
                'jsonrpc' => '2.0',
                'method'  => $call['method'],
                'params'  => $call['params'] ?? [],
                'id'      => $this->id++,
            ];
        }

        $payload = json_encode($requests, JSON_UNESCAPED_UNICODE);

        try {
            $response = $this->http->post('/rpc', [
                'body'    => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-RPC-Key'    => $this->authKey,
                ],
            ]);

            $bodies = json_decode($response->getBody()->getContents(), true);

            if (!is_array($bodies)) {
                return [];
            }

            // 按 id 排序，保证顺序
            usort($bodies, fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));

            $results = [];
            foreach ($bodies as $body) {
                if (isset($body['error'])) {
                    $results[] = [
                        'success' => false,
                        'error'   => $body['error'],
                    ];
                } else {
                    $results[] = [
                        'success' => true,
                        'result'  => $body['result'] ?? null,
                    ];
                }
            }

            return $results;
        } catch (GuzzleException $e) {
            throw new RuntimeException('RPC Batch 请求失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取可用方法列表（调用 /rpc/methods）
     */
    public function listMethods(): array
    {
        try {
            $response = $this->http->get('/rpc/methods', [
                'headers' => ['X-RPC-Key' => $this->authKey],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);
            return $body['data'] ?? [];
        } catch (GuzzleException $e) {
            throw new RuntimeException('获取 RPC 方法列表失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 快捷方法：调用企业微信服务
     */
    public function wxwork(): WxWorkService
    {
        return new WxWorkService();
    }

    /**
     * 快捷方法：调用聚合机器人服务
     */
    public function juhebot(): JuhebotService
    {
        return new JuhebotService();
    }
}
