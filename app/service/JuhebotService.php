<?php
declare(strict_types=1);

namespace app\service;

use app\service\LogService;
use GuzzleHttp\Client;

/**
 * Juhebot 接口服务
 * 管理聚合机器人相关 API
 */
class JuhebotService
{
    private Client $http;
    private string $appKey;
    private string $appSecret;
    private string $guid;
    private string $apiUrl;

    const API_BASE = 'https://chat-api.juhebot.com/open/GuidRequest';

    public function __construct()
    {
        $this->apiUrl    = env('JUHEBOT.API_URL', self::API_BASE);
        $this->appKey   = env('JUHEBOT.APP_KEY', '');
        $this->appSecret = env('JUHEBOT.APP_SECRET', '');
        $this->guid     = env('JUHEBOT.GUID', '');

        if (empty($this->appKey) || empty($this->appSecret)) {
            throw new \RuntimeException('JUHEBOT_APP_KEY 或 JUHEBOT_APP_SECRET 未配置');
        }

        $this->http = new Client([
            'base_uri' => $this->apiUrl,
            'timeout'  => 30,
            'headers'  => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }


    /**
     * 获取会话列表
     *
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array
     */
    public function getChatList(int $page = 1, int $pageSize = 20): array
    {
        $res = $this->get('/v1/chats', [
            'page'      => $page,
            'page_size' => $pageSize,
        ]);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取会话列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取会话列表失败: ' . $res['message']);
        }

        return $res;
    }

    /**
     * 获取会话详情
     *
     * @param string $chatId 会话ID
     * @return array
     */
    public function getChatDetail(string $chatId): array
    {
        $res = $this->get('/v1/chats/' . $chatId);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取会话详情失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取会话详情失败: ' . $res['message']);
        }

        return $res;
    }

    /**
     * 获取消息列表
     *
     * @param string $chatId 会话ID
     * @param int $limit 数量限制
     * @return array
     */
    public function getMessages(string $chatId, int $limit = 50): array
    {
        $res = $this->get('/v1/chats/' . $chatId . '/messages', [
            'limit' => $limit,
        ]);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取消息列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取消息列表失败: ' . $res['message']);
        }

        return $res;
    }

    /**
     * 创建会话
     *
     * @param string $title 会话标题
     * @param string $prompt 提示词
     * @return array
     */
    public function createChat(string $title, string $prompt = ''): array
    {
        $body = [
            'title' => $title,
        ];

        if (!empty($prompt)) {
            $body['prompt'] = $prompt;
        }

        $res = $this->post('/v1/chats', $body);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '创建会话失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('创建会话失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '会话创建成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 删除会话
     *
     * @param string $chatId 会话ID
     * @return array
     */
    public function deleteChat(string $chatId): array
    {
        $res = $this->delete('/v1/chats/' . $chatId);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '删除会话失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('删除会话失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '会话删除成功',
            'data'    => ['chat_id' => $chatId],
        ]);

        return $res;
    }

    /**
     * 流式发送消息（SSE）
     *
     * @param string $content 消息内容
     * @param string $conversationId 会话ID
     * @param string $guid 用户ID
     * @param callable $callback 流式回调
     * @return void
     */
    public function streamTextMessage(string $content, string $conversationId, string $guid, callable $callback): void
    {
        $innerData = [
            'content'         => $content,
            'conversation_id' => $conversationId,
            'guid'           => $guid,
            'stream'         => true,
        ];

        $body = [
            'app_key'    => $this->appKey,
            'app_secret' => $this->appSecret,
            'path'       => '/msg/send_text',
            'data'       => $innerData,
        ];

        try {
            LogService::info([
                'tag'     => 'Juhebot',
                'message' => '流式发送消息参数',
                'data'    => $body,
            ]);

            $response = $this->http->post('', [
                'json'    => $body,
                'stream'  => true,
                'headers' => [
                    'Accept' => 'text/event-stream',
                ],
            ]);

            $stream = $response->getBody();

            while (!$stream->eof()) {
                $line = $stream->read(1024);
                if (!empty($line)) {
                    $callback($line);
                }
            }
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '流式消息发送失败',
                'data'    => ['error' => $e->getMessage()],
            ]);
            throw $e;
        }
    }

    /**
     * 同步联系人
     *
     * @param string $guid 用户ID（必填）
     * @param string $seq 分页标识（可选，默认为空字符串）
     * @param int $limit 每页数量（可选，默认10）
     * @return array
     */
    public function syncContact(string $guid, string $seq = '', int $limit = 10): array
    {
        $data = [
            'guid' => $guid,
        ];

        if (!empty($seq)) {
            $data['seq'] = $seq;
        }

        if ($limit > 0) {
            $data['limit'] = $limit;
        }

        $res = $this->post('/contact/sync_contact', $data);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同步联系人失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同步联系人失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '联系人同步成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 同步申请好友列表
     *
     * @param string $guid 用户ID（必填）
     * @param string $seq 分页标识（可选，默认为空字符串）
     * @param int $limit 每页数量（可选，默认10）
     * @return array
     */
    public function syncApplyContact(string $guid, string $seq = '', int $limit = 10): array
    {
        $data = [
            'guid' => $guid,
        ];

        if (!empty($seq)) {
            $data['seq'] = $seq;
        }

        if ($limit > 0) {
            $data['limit'] = $limit;
        }

        $res = $this->post('/contact/sync_apply_contact', $data);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同步申请好友列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同步申请好友列表失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '申请好友列表同步成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 同意联系人申请
     *
     * @param string $guid 用户ID（必填）
     * @param string $userId 用户ID（可选，默认'0'）
     * @param string $corpId 企业ID（可选，默认'0'）
     * @return array
     */
    public function agreeContact(string $userId = '0', string $corpId = '0'): array
    {
        $data = [
            'guid'    => $this->guid,
            'user_id' => $userId,
            'corp_id' => $corpId,
        ];

        $res = $this->post('/contact/agree_contact', $data);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同意联系人申请失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同意联系人申请失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '同意联系人申请成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 获取客户群列表（自己是群主）
     *
     * @param string $guid 用户ID（必填）
     * @param int $startIndex 起始索引（可选，默认0）
     * @param int $limit 每页数量（可选，默认10）
     * @return array
     */
    public function getRoomList(int $startIndex = 0, int $limit = 10): array
    {
        $data = [
            'guid'         => $this->guid,
            'start_index' => $startIndex,
            'limit'        => $limit,
        ];

        $res = $this->post('/room/get_room_list', $data);
        
        if ($res['error_code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取客户群列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取客户群列表失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '获取客户群列表成功',
            'data'    => $res,
        ]);

        return $res['data'];
    }

    /**
     * 设置实例通知地址
     *
     * @param string $guid 用户ID（必填）
     * @param string $notifyUrl 通知地址（必填）
     * @return array
     */
    public function setNotifyUrl(string $guid, string $notifyUrl): array
    {
        $data = [
            'guid'        => $guid,
            'notify_url'  => $notifyUrl,
        ];

        $res = $this->post('/client/set_notify_url', $data);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '设置实例通知地址失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('设置实例通知地址失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '实例通知地址设置成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 创建外部群
     *
     * @param array $userList 用户ID列表（可选，默认为空数组）
     * @return array
     */
    public function createOuterRoom(array $userList = []): array
    {
        $data = [
            'guid'      => $this->guid,
            'user_list' => $userList,
        ];

        $res = $this->post('/room/create_outer_room', $data);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '创建外部群失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('创建外部群失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '外部群创建成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 发送文本消息
     *
     * @param string $conversationId 会话ID，群ID开头要有 R:，联系人ID开头要有 S:
     * @param string $content 文本内容
     * @return array
     */
    public function sendText(string $conversationId = '', string $content = ''): array
    {
        $data = [
            'guid'            => $this->guid,
            'conversation_id' => $conversationId,
            'content'         => $content,
        ];

        $res = $this->post('/msg/send_text', $data);

        if ($res['code'] !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '发送文本消息失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('发送文本消息失败: ' . $res['message']);
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '文本消息发送成功',
            'data'    => $res,
        ]);

        return $res;
    }

    // ─────────────────────────────────────────────
    // HTTP 工具方法
    // ─────────────────────────────────────────────

    private function get(string $uri, array $query = []): array
    {
        $response = $this->http->get($uri, ['query' => $query]);
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * 请求的参数封装成这样
     */
    private function post(string $uri, array $data = []): array
    {
        // 封装请求数据格式
        $body = [
            'app_key'    => $this->appKey,
            'app_secret' => $this->appSecret,
            'path'       => $uri,
            'data'       => $data,
        ];

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => 'API 请求参数',
            'data'    => $body,
        ]);

        $response = $this->http->post('', [
            'json' => $body,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    private function delete(string $uri): array
    {
        $response = $this->http->delete($uri);
        return json_decode($response->getBody()->getContents(), true) ?? [];
    }
}
