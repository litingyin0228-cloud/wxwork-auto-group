<?php
declare(strict_types=1);

namespace app\service;

use app\service\LogService;
use GuzzleHttp\Client;
use think\facade\Cache;

/**
 * 企业微信核心服务
 * 封装 access_token 管理、创建群聊、发送消息等操作
 */
class WxWorkService
{
    private Client $http;
    private array  $config;

    const API_BASE = 'https://qyapi.weixin.qq.com/cgi-bin/';

    public function __construct()
    {
        $this->config = config('wxwork');
        $this->http   = new Client([
            'base_uri' => self::API_BASE,
            'timeout'  => 10,
        ]);
    }

    // ─────────────────────────────────────────────
    // Access Token
    // ─────────────────────────────────────────────

    /**
     * 获取 access_token（自动缓存，7000 秒）
     * $type: 'app' | 'contact'
     */
    public function getAccessToken(string $type = 'app'): string
    {
        $cacheKey = $this->config['token_cache_key'] . '_' . $type;

        if ($token = Cache::get($cacheKey)) {
            return $token;
        }

        $secret = $type === 'contact'
            ? $this->config['contact_secret']
            : $this->config['corp_secret'];

        $res = $this->get('gettoken', [
            'corpid'     => $this->config['corp_id'],
            'corpsecret' => $secret,
        ]);

        if ($res['errcode'] !== 0) {
            LogService::error([
                'tag'     => 'WxWork',
                'message' => '获取 access_token 失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取 access_token 失败: ' . $res['errmsg']);
        }

        $token = $res['access_token'];
        Cache::set($cacheKey, $token, 7000);

        return $token;
    }

    // ─────────────────────────────────────────────
    // 群聊相关
    // ─────────────────────────────────────────────

    /**
     * 创建群聊会话（内部群，/appchat/create）
     * 注意：仅自建应用可调，且应用可见范围必须为根部门
     *
     * @param string $chatName  群名称
     * @param string $owner     群主 userid
     * @param array  $userList  成员 userid 列表（含群主，至少 2 人）
     * @param string $chatId    指定 chatid（可选，不传则自动生成）
     * @return array ['chatid' => '...']
     */
    public function createGroupChat(
        string $chatName,
        string $owner,
        array  $userList,
        string $chatId = ''
    ): array {
        $token = $this->getAccessToken('app');

        $body = [
            'name'      => $chatName,
            'owner'     => $owner,
            'userlist'  => array_values(array_unique($userList)),
        ];
        LogService::info([
            'tag'     => 'WxWork',
            'message' => '创建群聊参数',
            'data'    => $body,
        ]);

        if ($chatId !== '') {
            $body['chatid'] = $chatId;
        }

        $res = $this->post('appchat/create?access_token=' . $token, $body);
        LogService::info([
            'tag'     => 'WxWork',
            'message' => '创建群聊请求',
            'data'    => $res,
        ]);
        if ($res['errcode'] !== 0) {
            LogService::error([
                'tag'     => 'WxWork',
                'message' => '创建群聊失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('创建群聊失败: ' . $res['errmsg'] . ' (code=' . $res['errcode'] . ')');
        }

        LogService::info([
            'tag'     => 'WxWork',
            'message' => '群聊创建成功',
            'data'    => ['chatid' => $res['chatid']],
        ]);

        return ['chatid' => $res['chatid']];
    }

    /**
     * 向群聊发送文本消息（/appchat/send）
     */
    public function sendGroupMessage(string $chatId, string $content): bool
    {
        $token = $this->getAccessToken('app');

        $body = [
            'chatid'  => $chatId,
            'msgtype' => 'text',
            'text'    => ['content' => $content],
            'safe'    => 0,
        ];

        $res = $this->post('appchat/send?access_token=' . $token, $body);

        if ($res['errcode'] !== 0) {
            LogService::error([
                'tag'     => 'WxWork',
                'message' => '群聊消息发送失败',
                'data'    => $res,
            ]);
            return false;
        }

        return true;
    }

    // ─────────────────────────────────────────────
    // 外部联系人（客户）相关
    // ─────────────────────────────────────────────

    /**
     * 获取客户详情
     */
    public function getExternalContact(string $externalUserId): array
    {
        $token = $this->getAccessToken('contact');

        $res = $this->get('externalcontact/get', [
            'access_token'    => $token,
            'external_userid' => $externalUserId,
        ]);

        if ($res['errcode'] !== 0) {
            LogService::error([
                'tag'     => 'WxWork',
                'message' => '获取客户详情失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取客户详情失败: ' . $res['errmsg']);
        }

        return $res['external_contact'] ?? [];
    }

    /**
     * 获取客户列表
     */
    public function getExternalContactList($limit = 1000): array
    {
        $token = $this->getAccessToken('contact');
        $body = [
            'cursor'   => '',
            'limit'    => $limit,
        ];

        $res = $this->post('externalcontact/groupchat/list?access_token=' . $token, $body);

        if ($res['errcode'] !== 0) {
            LogService::error([
                'tag'     => 'WxWork',
                'message' => '获取客户列表失败',
                'data'    => $res,
            ]);
            return [];
        }

        return $res ?? [];
    }

    // https://qyapi.weixin.qq.com/cgi-bin/externalcontact/add_contact_way?access_token=ACCESS_TOKEN
    public function addContactWay($state): array
    {
        $token = $this->getAccessToken('contact');
        $body = [
            "type" =>1,
            "scene"=>2,
            "state"=>$state,
            "user"=>["XiaoKe"],
        ];
        $res = $this->post('externalcontact/add_contact_way?access_token=' . $token, $body);
        LogService::error([
            'tag'     => 'WxWork',
            'message' => '添加联系人方式返回信息',
            'state'   => $state,
            'res'     => $res,
        ]);
        return $res;
    }



    // ─────────────────────────────────────────────
    // HTTP 工具方法
    // ─────────────────────────────────────────────

    private function get(string $uri, array $query = []): array
    {
        $response = $this->http->get($uri, ['query' => $query]);
        return json_decode($response->getBody()->getContents(), true);
    }

    private function post(string $uri, array $body = []): array
    {
        $response = $this->http->post($uri, ['json' => $body]);
        return json_decode($response->getBody()->getContents(), true);
    }
}
