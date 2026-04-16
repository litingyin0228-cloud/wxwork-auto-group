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
    private string $appid;
    private string $apiUrl;

    const API_BASE = 'https://chat-api.juhebot.com/open/GuidRequest';

    public function __construct()
    {
        $this->apiUrl    = env('JUHEBOT.API_URL', self::API_BASE);
        $this->appKey   = env('JUHEBOT.APP_KEY', '');
        $this->appSecret = env('JUHEBOT.APP_SECRET', '');
        $this->guid     = env('JUHEBOT.GUID', '');
        $this->appid     = env('JUHEBOT.APP_ID', '');

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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取会话列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取会话列表失败: ' . ($res['message'] ?? ''));
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取会话详情失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取会话详情失败: ' . ($res['message'] ?? ''));
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取消息列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取消息列表失败: ' . ($res['message'] ?? ''));
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '创建会话失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('创建会话失败: ' . ($res['message'] ?? ''));
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '删除会话失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('删除会话失败: ' . ($res['message'] ?? ''));
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
    public function syncContact(string $seq = '', int $limit = 10): array
    {
        $data = [
            'guid' => $this->guid,
        ];

        if (!empty($seq)) {
            $data['seq'] = $seq;
        }

        if ($limit > 0) {
            $data['limit'] = $limit;
        }

        $res = $this->post('/contact/sync_contact', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同步联系人失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同步联系人失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '联系人同步成功',
            'data'    => ['seq' => $res['data']['last_seq'], "contact_list"=>$res['data']['contact_list']],
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
    public function syncApplyContact( string $seq = '', int $limit = 10): array
    {
        $data = [
            'guid' => $this->guid,
        ];

        if (!empty($seq)) {
            $data['seq'] = $seq;
        }

        if ($limit > 0) {
            $data['limit'] = $limit;
        }

        $res = $this->post('/contact/sync_apply_contact', $data);
        
        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同步申请好友列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同步申请好友列表失败: ' . ($res['message'] ?? ''));
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
        LogService::info([
            'tag'     => 'agreeContact',
            'message' => '同意联系人申请',
            'data'    => $res,
        ]);
        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同意联系人申请失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同意联系人申请失败: ' . ($res['message'] ?? ''));
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
        
        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '获取客户群列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('获取客户群列表失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '获取客户群列表成功',
            'data'    => $res,
        ]);

        return $res['data'];
    }

    /**
     * 批量获取群详细信息
     *
     * @param array $roomList 群ID列表
     * @return array
     */
    public function batchGetRoomDetail(array $roomList = []): array
    {
        $data = [
            'guid'      => $this->guid,
            'room_list' => $roomList,
        ];

        $res = $this->post('/room/batch_get_room_detail', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '批量获取群详细信息失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('批量获取群详细信息失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量获取群详细信息成功',
            'data'    => $res,
        ]);

        return $res;
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '设置实例通知地址失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('设置实例通知地址失败: ' . ($res['message'] ?? ''));
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '创建外部群失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('创建外部群失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '外部群创建成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 获取群二维码
     *
     * @param string $roomId 群ID
     * @param string $guid 设备GUID
     * @param int $getType 获取类型（0默认, 1刷新等）
     * @return array
     */
    public function getRoomQRCode(string $roomId = '', string $default_qr_code = ''): array
    {
        $data = [
            'guid'     => $this->guid,
            'room_id'  => $roomId,
            'get_type' => 1,
        ];

        $res = $this->post('/room/get_room_qrcode', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            $res['data']['qr_code'] = $default_qr_code;
        } else {
            $res['data']['qr_code'] = base64_decode($res['data']['image_url']);
        }
        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '群二维码获取成功',
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

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '发送文本消息失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('发送文本消息失败: ' . ($res['message'] ?? ''));
        }
        return $res;
    }

    /**
     * 修改群名称
     *
     * @param string $roomId 群ID
     * @param string $roomName 新群名称
     * @return array
     */
    public function modifyRoomName(string $roomId = '', string $roomName = ''): array
    {
        $data = [
            'guid'      => $this->guid,
            'room_id'   => $roomId,
            'room_name' => $roomName,
        ];

        $res = $this->post('/room/modify_room_name', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '修改群名称失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('修改群名称失败: ' . ($res['message'] ?? ''));
        }
        // 开启禁止互相添加为联系人、禁止修改群名
        $this->modifyRoomAdminFlag($roomId, true, true);
        return $res;
    }

    /**
     * 开启/关闭禁止互相添加为联系人、禁止修改群名
     *
     * @param string $roomId 群ID
     * @param bool $forbidAddContact 是否禁止互相添加为联系人
     * @param bool $forbidModName 是否禁止修改群名
     * @return array
     */
    public function modifyRoomAdminFlag(string $roomId = '', bool $forbidAddContact = false, bool $forbidModName = false): array
    {
        $data = [
            'guid'              => $this->guid,
            'room_id'           => $roomId,
            'forbid_add_contact' => $forbidAddContact,
            'forbid_mod_name'   => $forbidModName,
        ];

        $res = $this->post('/room/modify_room_admin_flag', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '设置群管理标志失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('设置群管理标志失败: ' . ($res['message'] ?? ''));
        }
        return $res;
    }

    /**
     * 发送小程序
     *
     * @param string $conversationId 会话ID（群ID以 R: 开头，联系人ID以 S: 开头）
     * @param string $username 原始ID
     * @param string $appname 小程序名称
     * @param string $appicon 小程序图标
     * @param string $title 小程序标题
     * @param string $pagePath 小程序页面路径
     * @param string $fileId 文件ID
     * @param int $size 大小
     * @param string $aesKey AES密钥
     * @param string $md5 MD5值
     * @return array
     */
    public function sendWeApp(
        string $conversationId = '',
        string $username = '一键零申报',
        string $appname = '一键零申报',
        string $appicon = 'http://wx.qlogo.cn/mmhead/7SPO0mRJt6BfLTkRTASKrUvNmibO4IBHgBibhuZuKhD6kXL9iav0FLJwzlRpLzR6vdeEDONKdIVVjw/96',
        string $title = '一键零申报-不止零申报',
        string $pagePath = 'pages/index/index.html',
        string $fileId = '306b0201020464306202010002049c67101202031e903802042ac6f46d020469dfb8900435323632343030303031385f3138343432323037395f326630623937323132333339363533336363616263313934366634326237666502031038000202785004000201010201000400',
        int $size = 109283,
        string $aesKey = '7779726170737879756F72786E676D63',
        string $md5 = '2f0b972123396533ccabc1946f42b7fe'
    ): array {
        $data = [
            'guid'            => $this->guid,
            'conversation_id' => $conversationId,
            'username'        => $username,
            'appid'           => $this->appid,
            'appname'         => $appname,
            'appicon'         => $appicon,
            'title'           => $title,
            'page_path'       => $pagePath,
            'file_id'         => $fileId,
            'size'            => $size,
            'aes_key'         => $aesKey,
            'md5'             => $md5,
        ];

        $res = $this->post('/msg/send_weapp', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '发送小程序失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('发送小程序失败: ' . ($res['message'] ?? ''));
        }
        return $res;
    }

    /**
     * 发送图片消息
     *
     * @param string $conversationId 会话ID（群ID以 R: 开头，联系人ID以 S: 开头）
     * @param string $fileId 文件ID
     * @param int $size 文件大小
     * @param int $imageWidth 图片宽度
     * @param int $imageHeight 图片高度
     * @param string $aesKey AES密钥
     * @param string $md5 MD5值
     * @param bool $isHd 是否高清图片
     * @return array
     */
    public function sendImage(
        string $conversationId = '',
        string $fileId = '',
        int $size = 0,
        int $imageWidth = 0,
        int $imageHeight = 0,
        string $aesKey = '',
        string $md5 = '',
        bool $isHd = false
    ): array {
        $data = [
            'guid'            => $this->guid,
            'conversation_id' => $conversationId,
            'file_id'         => $fileId,
            'size'            => $size,
            'image_width'     => $imageWidth,
            'image_height'    => $imageHeight,
            'aes_key'         => $aesKey,
            'md5'             => $md5,
            'is_hd'           => $isHd,
        ];

        $res = $this->post('/msg/send_image', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '发送图片消息失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('发送图片消息失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '图片消息发送成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 发送文件消息
     *
     * @param string $conversationId 会话ID（群ID以 R: 开头，联系人ID以 S: 开头）
     * @param string $fileId 文件ID
     * @param int $size 文件大小
     * @param string $fileName 文件名
     * @param string $aesKey AES密钥（大于20M文件用 big cdn 上传时留空）
     * @param string $md5 MD5值
     * @return array
     */
    public function sendFile(
        string $conversationId = '',
        string $fileId = '',
        int $size = 0,
        string $fileName = '',
        string $aesKey = '',
        string $md5 = ''
    ): array {
        $data = [
            'guid'            => $this->guid,
            'conversation_id' => $conversationId,
            'file_id'         => $fileId,
            'size'            => $size,
            'file_name'       => $fileName,
            'aes_key'         => $aesKey,
            'md5'             => $md5,
        ];

        $res = $this->post('/msg/send_file', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '发送文件消息失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('发送文件消息失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '文件消息发送成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 发送群@消息
     *
     * @param string $conversationId 会话ID（群ID以 R: 开头）
     * @param string $content 消息内容（可在内容中加入 {$@} 占位符调整@人位置）
     * @param array $atList 被@的用户ID列表，传 [0] 表示@全部人（仅群主或管理员可@全部人）
     * @return array
     */
    public function sendRoomAt(string $conversationId = '', string $content = '', array $atList = []): array
    {
        $data = [
            'guid'            => $this->guid,
            'conversation_id' => $conversationId,
            'content'         => $content,
            'at_list'         => $atList,
        ];

        $res = $this->post('/msg/send_room_at', $data);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '发送群@消息失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('发送群@消息失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '群@消息发送成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 更新联系人
     *
     * @param string $guid 联系人唯一标识
     * @param string $userId 用户ID
     * @param string $desc 描述
     * @param string $remark 备注名
     * @param string $remarkUrl 备注 URL
     * @param string $companyRemark 公司备注
     * @param array $phoneList 电话列表
     * @param array $labelInfoList 标签信息列表
     * @return array 
     */
    public function updateContact(
        string $userId = '',
        string $desc = '',
        string $remark = '',
        string $remarkUrl = '',
        string $companyRemark = '',
        array $phoneList = [],
        array $labelInfoList = []
    ): array {
        $body = [
            'guid' => $this->guid,
        ];

        if ($userId !== '') {
            $body['user_id'] = $userId;
        }
        if ($desc !== '') {
            $body['desc'] = $desc;
        }
        if ($remark !== '') {
            $body['remark'] = $remark;
        }
        if ($remarkUrl !== '') {
            $body['remark_url'] = $remarkUrl;
        }
        if ($companyRemark !== '') {
            $body['company_remark'] = $companyRemark;
        }
        if (!empty($phoneList)) {
            $body['phone_list'] = $phoneList;
        }
        if (!empty($labelInfoList)) {
            $body['label_info_list'][] = $labelInfoList;
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '更新联系人参数',
            'data'    => $body,
        ]);
        $res = $this->post('/contact/update_contact', $body);
        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '更新联系人返回结果',
            'data'    => $res,
        ]);
        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '更新联系人失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('更新联系人失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '更新联系人成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 为成员批量添加多个标签
     *
     * @param string $userId  成员用户ID
     * @param array  $labelInfos 标签信息列表，每项包含 label_id, corp_or_vid, label_groupid, business_type
     * @return array
     * @throws \RuntimeException 接口调用失败时抛出
     */
    public function contactAddLabels(string $userId, array $labelInfos): array
    {
        $body = [
            'guid'       => $this->guid,
            'user_id'    => $userId,
            'label_infos' => $labelInfos,
        ];

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量添加标签参数',
            'data'    => $body,
        ]);

        $res = $this->post('/label/contact_add_labels', $body);

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量添加标签返回结果',
            'data'    => $res,
        ]);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '批量添加标签失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('批量添加标签失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量添加标签成功',
            'data'    => $res,
        ]);

        return $res;
    }

    /**
     * 批量为成员添加标签（一次为多个成员添加同一个标签）
     *
     * @param array  $userList    成员用户ID列表
     * @param string $labelId     标签ID
     * @param string $corpOrVid   企业ID
     * @param string $labelGroupid 标签组ID
     * @param int    $businessType 业务类型
     * @return array
     * @throws \RuntimeException 接口调用失败时抛出
     */
    public function contactAddLabel(
        array $userList,
        string $labelId,
        string $corpOrVid = '',
        string $labelGroupid = '',
        int $businessType = 0
    ): array {
        $body = [
            'guid'          => $this->guid,
            'user_list'     => $userList,
            'label_id'      => $labelId,
            'corp_or_vid'   => $corpOrVid,
            'label_groupid' => $labelGroupid,
            'business_type' => $businessType,
        ];
        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量为成员添加标签参数',
            'data'    => $body,
        ]);

        $res = $this->post('/label/contact_add_label', $body);    
        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量为成员添加标签返回结果',
            'data'    => $res,
        ]);

        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '批量为成员添加标签失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('批量为成员添加标签失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '批量为成员添加标签成功',
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
        $raw = json_decode($response->getBody()->getContents(), true) ?? [];
        if (isset($raw['err_code']) && !isset($raw['error_code'])) {
            $raw['error_code'] = $raw['err_code'];
        }
        return $raw;
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

        $raw = json_decode($response->getBody()->getContents(), true) ?? [];
        if (isset($raw['err_code']) && !isset($raw['error_code'])) {
            $raw['error_code'] = $raw['err_code'];
        }
        return $raw;
    }

    private function delete(string $uri): array
    {
        $response = $this->http->delete($uri);
        $raw = json_decode($response->getBody()->getContents(), true) ?? [];
        if (isset($raw['err_code']) && !isset($raw['error_code'])) {
            $raw['error_code'] = $raw['err_code'];
        }
        return $raw;
    }

    /**
     * 同步获取标签列表
     *
     * @param string $seq  分页标识，默认为空
     * @param int    $syncType  1=企业标签 2=个人标签（默认）
     * @return array
     */
    public function syncLabelList(string $seq = '', int $syncType = 2): array
    {
        $data = [
            'guid'     => $this->guid,
            'seq'      => $seq,
            'sync_type' => $syncType,
        ];

        $res = $this->post('/label/sync_label_list', $data);
        var_dump($res);
        if (($res['error_code'] ?? -1) !== 0) {
            LogService::error([
                'tag'     => 'Juhebot',
                'message' => '同步标签列表失败',
                'data'    => $res,
            ]);
            throw new \RuntimeException('同步标签列表失败: ' . ($res['message'] ?? ''));
        }

        LogService::info([
            'tag'     => 'Juhebot',
            'message' => '标签列表同步成功',
            'data'    => $res,
        ]);

        return $res;
    }
}
