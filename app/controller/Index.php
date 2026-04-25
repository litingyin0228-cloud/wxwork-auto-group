<?php
namespace app\controller;

use app\BaseController;
use app\model\ApplyContactList;
use app\model\ContactRoom;
use app\model\LabelLog;
use app\service\InvoiceSessionService;
use app\service\JuhebotService;
use app\service\LogService;
use app\service\WxWorkService;
use think\facade\Db;
use think\facade\Event;
use think\Request;

class Index extends BaseController
{ 
    private const FILE_TYPE_IMAGE = 2;
    private const FILE_TYPE_FILE  = 5;

    private const SCOPE_BOTH = 1; // 群+个人
    private const SCOPE_ROOM = 2; // 仅群
    private const SCOPE_USER = 3;  // 仅个人

    private const MSG_TYPE_TEXT  = 1;
    private const MSG_TYPE_IMAGE = 2;
    private const MSG_TYPE_FILE  = 4;

    private const SEND_TYPE_MESSAGE = 1; // 普通消息（文本/图片/文件）
    private const SEND_TYPE_WAPP   = 2; // 小程序


    private const CORP_ID = "1970324956094061";

    private ?JuhebotService $juhebot = null;

    private InvoiceSessionService $invoiceService;

    public function getInvoiceService(): InvoiceSessionService
    {
        if ($this->invoiceService === null) {
            $this->invoiceService = new InvoiceSessionService();
        }
        return $this->invoiceService;
    }

    /**
     * 获取 JuhebotService 单例
     */
    private function getJuhebot(): JuhebotService
    {
        if ($this->juhebot === null) {
            $this->juhebot = new JuhebotService();
        }
        return $this->juhebot;
    }

    public function index()
    {
        return '<style type="text/css">*{ padding: 0; margin: 0; } div{ padding: 4px 48px;} a{color:#2E5CD5;cursor: pointer;text-decoration: none} a:hover{text-decoration:underline; } body{ background: #fff; font-family: "Century Gothic","Microsoft yahei"; color: #333;font-size:18px;} h1{ font-size: 100px; font-weight: normal; margin-bottom: 12px; } p{ line-height: 1.6em; font-size: 42px }</style><div style="padding: 24px 48px;"> <h1>:) </h1><p> ThinkPHP V' . \think\facade\App::version() . '<br/><span style="font-size:30px;">16载初心不改 - 你值得信赖的PHP框架</span></p><span style="font-size:25px;">[ V6.0 版本由 <a href="https://www.yisu.com/" target="yisu">亿速云</a> 独家赞助发布 ]</span></div><script type="text/javascript" src="https://e.topthink.com/Public/static/client.js"></script><think id="ee9b1aa918103c4fc"></think>';
    }

    public function hello($name = 'ThinkPHP6')
    {
        return 'hello,' . $name;
    }

    public function isCreateGroup()
    {
        $total = Db::table('wxwork_group_chat_log')->where("is_process","=", 0)->field(["id", "external_userid","staff_userid","customer_name"])->find();
        return json([
            'code' => 0,
            'msg' => 'ok',  
            'data' => $total,
        ]);
    }

    /**
     * 微信工作台消息回调
     */
    public function wxworkMsgCallback()
    {
        try {
            // 获取请求参数
            $params = json_decode(file_get_contents('php://input'), true);

            if (empty($params)) {
                LogService::error([
                    'tag'     => 'JuhebotCallback',
                    'message' => '接收到空的请求参数',
                ]);
                return json([
                    'code'    => 400,
                    'message' => '请求参数为空',
                ]);
            }

            $guid = $params['guid'] ?? '';
            $notifyType = $params['notify_type'] ?? 0;
            $data = $params['data'] ?? [];

            // notify_type = 2132 建群回调 
            // 触发建群回调事件
            Event::trigger('RoomCreated', [
                'guid'        => $guid,
                'notify_type' => $notifyType,
                'data'        => $data,
            ]);
            
            if (empty($guid)) {
                LogService::error([
                    'tag'     => 'JuhebotCallback',
                    'message' => '缺少必要参数',
                    'data'    => $params,
                ]);
                return json([
                    'code'    => 400,
                    'message' => '缺少必要参数',
                ]);
            }

            // 提取数据字段
            $seq = $data['seq'] ?? '';
            $messageId = $data['id'] ?? '';
            $appinfo = $data['appinfo'] ?? '';
            $sender = $data['sender'] ?? '';
            $receiver = $data['receiver'] ?? '';
            $roomId = $data['roomid'] ?? '0';
            $sendTime = $data['sendtime'] ?? 0;
            $senderName = $data['sender_name'] ?? '';
            $contentType = $data['content_type'] ?? 0;
            $referId = $data['referid'] ?? '0';
            $flag = $data['flag'] ?? 0;
            $content = $data['content'] ?? '';
            $atList = json_encode($data['at_list'] ?? []);
            $quoteContent = $data['quote_content'] ?? '';
            $quoteAppinfo = $data['quote_appinfo'] ?? '';
            $sendFlag = $data['send_flag'] ?? 1;
            $msgType = $data['msg_type'] ?? 0;

            // 保存消息到数据库
            $result = Db::table('wxwork_juhebot_message_callback')->insert([
                'guid'          => $guid,
                'notify_type'   => $notifyType,
                'seq'           => $seq,
                'msg_id'    => $messageId,
                'appinfo'       => $appinfo,
                'sender'        => $sender,
                'receiver'      => $receiver,
                'room_id'       => $roomId,
                'send_time'     => $sendTime,
                'sender_name'   => $senderName,
                'content_type'  => $contentType,
                'refer_id'      => $referId,
                'flag'          => $flag,
                'content'       => $content,
                'at_list'       => $atList,
                'quote_content' => $quoteContent,
                'quote_appinfo' => $quoteAppinfo,
                'send_flag'     => $sendFlag,
                'msg_type'      => $msgType,
                'raw_data'      => json_encode($data),
                'is_processed'  => 0,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            if ($result) {                           
                return json([
                    'code'    => 0,
                    'message' => '消息处理成功',
                ]);

            } else {
                LogService::error([
                    'tag'     => 'JuhebotCallback',
                    'message' => '消息保存失败',
                    'data'    => $params,
                ]);
                return json([
                    'code'    => 500,
                    'message' => '消息保存失败',
                ]);
            }

        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'JuhebotCallback',
                'message' => '处理消息回调异常',
                'data'    => [
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ],
            ]);
            return json([
                'code'    => 500,
                'message' => '服务器内部错误',
            ]);
        }
    }

    /**
     * 获取动态二维码
     */
    public function getAddContactWayErCode(Request $request)
    {
        $qr_code = "https://wework.qpic.cn/wwpic3az/355116_4Zr9L0xNQi-ZfeV_1775115274/0"; // 默认二维码，没携带State参数
        try {
            $mobile = $request->param('mobile');
            if(empty($mobile)){
                return $this->error('手机号不能为空');
            }
            if(strlen($mobile) != 11){
                return $this->error('手机号格式不正确');
            }
            if(!preg_match('/^1[3-9]\d{9}$/', $mobile)){
                return $this->error('手机号格式不正确');
            }
            // 通过手机号码去
            $user_id = ApplyContactList::where("mobile", $mobile)->value("user_id");
            if(empty($user_id)){
                $service = new WxWorkService();
                $res = $service->addContactWay($mobile);
                $res['type'] = 1;
                return $this->success($res,'动态二维码获取成功');
            }
            $roomId = ContactRoom::where("user_id", $user_id)->value("room_id");
            if(empty($roomId)){
                $service = new WxWorkService();
                $res = $service->addContactWay($mobile);
                $res['type'] = 1;
                return $this->success($res,'动态二维码获取成功');
            }
            // 获取群二维码
            $service = $this->getJuhebot();
            $res = $service->getRoomQRCode($roomId, $qr_code);
            $result['type'] = 2;
            $result['qr_code'] = $res['data']['room_qrcode'];// 把base64解码
        
            // aHR0cHM6Ly93ZXdvcmsucXBpYy5jbi93d3BpYzNhei8xOTQxNDdfZUJzTEN4R0tTSGFMMTU0XzE3NzQ5MTg3MzcvMA== php解码
            // $result['qr_code'] = urldecode($result['image_url']);
            
            return $this->success($result,'群二维码获取成功');
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'getAddContactWayErCode',
                'message' => '获取动态二维码异常',
                'data'    => [
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ],
            ]);
            return $this->error($e->getMessage() );
        }
    }

    /**
     * 修改用户标签
     *
     * @param Request $request
     * type      - 标签类型（1=企业标签 2=个人标签）→ business_type
     * org_id    - 企业ID → corp_or_vid
     * phone     - 用户手机号（通过 ApplyContactList 查询 user_id）
     * label_id  - 标签ID（可选，默认为企业标签ID）
     * label_groupid - 标签组ID（可选）
     */
    public function updateContactLabel(Request $request)
    {
        $type  = (int)$request->param('type', 0);
        $orgId = trim($request->param('org_id', ''));
        $phone = trim($request->param('phone', ''));
        $labelId    = trim($request->param('label_id', ''));
        $labelGroupid = trim($request->param('label_groupid', ''));

        if ($type === 0) {
            return $this->error('type（标签类型）不能为空');
        }
        if (!in_array($type, [1, 2], true)) {
            return $this->error('type 必须是 1（企业标签）或 2（个人标签）');
        }
        if ($orgId === '') {
            return $this->error('org_id（企业ID）不能为空');
        }
        if ($phone === '') {
            return $this->error('phone（手机号）不能为空');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return $this->error('手机号格式不正确');
        }

        // 通过手机号查找 user_id
        $userId = ApplyContactList::where('mobile', $phone)->value('user_id');
        if ($userId === null || $userId === '') {
            return $this->error('未找到该手机号对应的用户');
        }

        $labelInfo = [
            'label_id'      => $labelId !== '' ? $labelId : '14073753009969296',
            'corp_or_vid'   => $orgId,
            'label_groupid' => $labelGroupid !== '' ? $labelGroupid : '14073749395893864',
            'business_type' => $type,
        ];

        try {
            $juhebot = $this->getJuhebot();
            $res = $juhebot->updateContact($userId, '', '', '', '', [], $labelInfo);

            LogService::info([
                'tag'     => 'UpdateLabel',
                'message' => '修改用户标签成功',
                'data'    => [
                    'user_id'   => $userId,
                    'phone'     => $phone,
                    'label_info' => $labelInfo,
                    'result'    => $res,
                ],
            ]);

            return $this->success($res, '标签修改成功');
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'UpdateLabel',
                'message' => '修改用户标签失败',
                'data'    => [
                    'user_id'  => $userId,
                    'phone'    => $phone,
                    'label_id' => $labelId,
                    'error'    => $e->getMessage(),
                ],
            ]);
            return $this->error('标签修改失败: ' . $e->getMessage());
        }
    }

    /**
     * 发送文件：图片或者文件，通过url上传到CDN，然后发送消息
     *
     * @param string $conversationId
     * @param string $url
     * @param string $fileName 带后缀的文件名
     * @param integer $fileType 2=图片 5=文件
     * @return array
     */
    private function sendFile(JuhebotService $service, string $conversationId, string $url, string $fileName, int $fileType = self::FILE_TYPE_IMAGE): array
    {
        if ($conversationId === '' || $url === '') {
            return ['code' => 400, 'message' => 'conversationId 和 url 不能为空'];
        }

        if ($fileType !== self::FILE_TYPE_IMAGE && $fileType !== self::FILE_TYPE_FILE) {
            return ['code' => 400, 'message' => '不支持的文件类型: ' . $fileType];
        }

        $cdnInfo = $service->getCdnInfo();
        $baseRequest = $cdnInfo['data'] ?? $cdnInfo;

        $uploadRes = $service->c2cUploadForUrl($cdnInfo, $url, $fileType);
        $data = $uploadRes['data'] ?? [];

        $conversationId = $data['conversation_id'] ?? $conversationId;

        if ($fileType === self::FILE_TYPE_IMAGE) {
            $res = $service->sendImage(
                $conversationId,
                $data['file_id'] ?? '',
                $data['aes_key'] ?? '',
                $data['file_md5'] ?? '',
                $data['file_size'] ?? 0,
                $data['image_width'] ?? 0,
                $data['image_height'] ?? 0,
                false
            );
        } elseif ($fileType === self::FILE_TYPE_FILE) {
            $res = $service->sendFile(
                $conversationId,
                $data['file_id'] ?? '',
                $data['file_size'] ?? 0,
                $fileName ?: '文件.pdf',
                $data['aes_key'] ?? '',
                $data['file_md5'] ?? ''
            );
        }

        return ['code' => 0, 'message' => '发送文件成功', 'data' => $res];
    }

    /**
     * 根据消息类型分发发送逻辑
     *
     * @param JuhebotService $service
     * @param int $msgType 1=文字 2=图片 4=文件
     * @param string $conversationId
     * @param string $content 图片/文件时为URL，文字时为文本内容
     * @param string $fileName 文件名
     * @return void
     */
    private function sendByMsgType(JuhebotService $service, int $msgType, string $conversationId, string $content, string $fileName = ''): void
    {
        switch ($msgType) {
            case self::MSG_TYPE_TEXT:
                $service->sendText($conversationId, $content);
                break;
            case self::MSG_TYPE_IMAGE:
                $this->sendFile($service, $conversationId, $content, $fileName, self::FILE_TYPE_IMAGE);
                break;
            case self::MSG_TYPE_FILE:
                $this->sendFile($service, $conversationId, $content, $fileName, self::FILE_TYPE_FILE);
                break;
            default:
                throw new \InvalidArgumentException('不支持的消息类型: ' . $msgType);
        }
    }

    /**
     * 发送消息
     *
     * ## 功能说明
     * 根据指定手机号查询用户及其关联群组，向对应会话发送消息。支持文本、小程序、图片、文件四种消息类型。
     *
     * ## 请求参数
     * | 参数名       | 类型   | 必填 | 默认值 | 说明                                                         |
     * |-------------|--------|------|--------|--------------------------------------------------------------|
     * | send_type   | int    | 是   | -      | 发送方式：1=普通消息（文本/图片/文件），2=小程序                   |
     * | msg_type    | int    | 否   | 1      | 消息类型：1=文字，2=图片，4=文件（仅 send_type=1 时有效）        |
     * | content     | string | 条件 | -      | 消息内容。文字时为文本内容，图片/文件时为资源 URL                  |
     * | scope       | int    | 否   | 1      | 发送范围：1=群+个人，2=仅群，3=仅个人                           |
     * | mobile      | string | 是   | -      | 用户手机号，用于定位发送对象                                    |
     * | path        | string | 条件 | -      | 小程序页面路径（send_type=2 时必填）                            |
     * | file_name   | string | 否   | -      | 文件名（msg_type=4 时用于指定发送的文件名）                      |
     *
     * ## 消息类型与 content 字段对应关系
     * | msg_type | 消息类型 | content 传值示例                                          |
     * |----------|---------|----------------------------------------------------------|
     * | 1        | 文字     | `你好，这是测试消息`                                        |
     * | 2        | 图片     | `https://example.com/image.jpg`（图片 URL）               |
     * | 4        | 文件     | `https://example.com/doc.pdf`（文件 URL）                |
     *
     * ## scope 与发送目标对应关系
     * | scope | 发送目标                            | 条件说明                              |
     * |-------|-----------------------------------|---------------------------------------|
     * | 1     | 同时发给用户个人和所属群             | 最常用，覆盖最广                       |
     * | 2     | 仅发给所属群                        | 群消息通知                            |
     * | 3     | 仅发给用户个人                      | 私信通知                              |
     *
     * ## 请求示例
     * ```bash
     * # 发送文本消息给用户及群
     * curl "http://host/index/index/sendMessage?mobile=13800138000&send_type=1&msg_type=1&content=Hello"
     *
     * # 发送图片消息仅给用户个人
     * curl "http://host/index/index/sendMessage?mobile=13800138000&send_type=1&msg_type=2&content=https://example.com/img.jpg&scope=3"
     *
     * # 发送文件消息给群
     * curl "http://host/index/index/sendMessage?mobile=13800138000&send_type=1&msg_type=4&content=https://example.com/doc.pdf&file_name=文档.pdf&scope=2"
     *
     * # 发送小程序消息
     * curl "http://host/index/index/sendMessage?mobile=13800138000&send_type=2&path=pages/index/index"
     * ```
     *
     * ## 返回格式
     * ```json
     * {
     *     "code": 0,
     *     "msg": "发送完成",
     *     "data": {
     *         "success": 1,
     *         "fail": 0,
     *         "errors": []
     *     }
     * }
     * ```
     *
     * ## 业务逻辑说明
     * 1. 通过 mobile 在 `wxwork_apply_contact_list` 表查询用户
     * 2. 根据 scope 关联 `wxwork_contact_room` 表获取用户所属群组
     * 3. 若 scope=2 但用户无群组记录，退回到使用用户 ID 作为会话 ID
     * 4. 图片/文件消息：content 为资源 URL，先上传至 CDN，再发送文件消息
     *
     * ## 错误码
     * | code | 说明                          |
     * |------|------------------------------|
     * | 400  | 参数缺失或非法（手机号为空、send_type 不支持、消息类型不支持） |
     * | 1    | 业务错误（未找到符合条件的用户、发送失败）                   |
     */
    public function sendMessage(Request $request)
    {
        try {
            $sendType  = (int) $request->param('send_type', 0);
            $msgType   = (int) $request->param('msg_type', self::MSG_TYPE_TEXT);
            $content   = $request->param('content', []);
            $scope     = (int) $request->param('scope', self::SCOPE_BOTH);
            $mobile    = $request->param('mobile', '');
            $path      = $request->param('path', '');
            $fileName  = $request->param('file_name', '');

            if (empty($mobile)) {
                return $this->error('手机号不能为空');
            }

            if (!in_array($sendType, [self::SEND_TYPE_MESSAGE, self::SEND_TYPE_WAPP], true)) {
                return $this->error('发送方式仅支持1(文本/图片/文件)或2(小程序)');
            }

            if (!in_array($scope, [self::SCOPE_BOTH, self::SCOPE_ROOM, self::SCOPE_USER], true)) {
                return $this->error('发送范围仅支持1(群+个人)、2(仅群)、3(仅个人)');
            }

            $validMsgTypes = [self::MSG_TYPE_TEXT, self::MSG_TYPE_IMAGE, self::MSG_TYPE_FILE];
            if ($sendType === self::SEND_TYPE_MESSAGE && !in_array($msgType, $validMsgTypes, true)) {
                return $this->error('消息类型仅支持1(文字)、2(图片)、4(文件)');
            }

            if ($sendType === self::SEND_TYPE_MESSAGE && $msgType === self::MSG_TYPE_TEXT && empty($content)) {
                return $this->error('消息内容不能为空');
            }

            if (in_array($msgType, [self::MSG_TYPE_IMAGE, self::MSG_TYPE_FILE], true)) {
                if (empty($content) || !filter_var($content, FILTER_VALIDATE_URL)) {
                    return $this->error('图片/文件消息的 content 必须是有效的 URL');
                }
            }

            if ($sendType === self::SEND_TYPE_WAPP && empty($path)) {
                return $this->error('小程序页面路径不能为空');
            }

            $service = $this->getJuhebot();

            $scopeInfo = $this->getScopeUsers($scope, ['user_id', 'room_id'], $mobile);

            if (empty($scopeInfo)) {
                return $this->error('未找到符合条件的用户');
            }

            $userId = $scopeInfo['user_id'] ?? '';
            $roomId = $scopeInfo['room_id'] ?? '';

            $conversationUserId = 'S:' . $userId;
            $conversationRoomId = 'R:' . $roomId;
            if (strlen($conversationRoomId) < 5) {
                $conversationRoomId = $conversationUserId;
            }

            $success = 0;
            $fail = 0;
            $errorMessages = [];

            if ($sendType === self::SEND_TYPE_WAPP) {
                $targets = $this->resolveTargets($scope, $conversationUserId, $conversationRoomId, $userId);
                foreach ($targets as $target) {
                    try {
                        $service->sendWeApp($target, $path);
                        $success++;
                    } catch (\Throwable $e) {
                        $fail++;
                        $errorMessages[] = $e->getMessage();
                    }
                }
                return $this->success([
                    'success' => $success,
                    'fail'    => $fail,
                    'errors'  => $errorMessages,
                ], '小程序发送完成');
            } else {
                $targets = $this->resolveTargets($scope, $conversationUserId, $conversationRoomId, $userId);
                foreach ($targets as $target) {
                    try {
                        $this->sendByMsgType($service, $msgType, $target, $content, $fileName);
                        $success++;
                    } catch (\Throwable $e) {
                        $fail++;
                        $errorMessages[] = $e->getMessage();
                    }
                }
                return $this->success([
                    'success' => $success,
                    'fail'    => $fail,
                    'errors'  => $errorMessages,
                ], '发送完成');
            }
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'SendMessage',
                'message' => '发送消息异常',
                'data'    => [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            return $this->error('发送消息失败: ' . $e->getMessage());
        }
    }

    /**
     * 根据 scope 解析出实际需要发送的目标 ID 列表
     *
     * @param int    $scope
     * @param string $conversationUserId
     * @param string $conversationRoomId
     * @param string $userId
     * @return string[]
     */
    private function resolveTargets(int $scope, string $conversationUserId, string $conversationRoomId, string $userId): array
    {
        switch ($scope) {
            case self::SCOPE_BOTH:
                return [$conversationUserId, $conversationRoomId];
            case self::SCOPE_ROOM:
                return [$conversationRoomId];
            case self::SCOPE_USER:
                return [$conversationUserId];
            default:
                return [];
        }
    }

    /**
     * Undocumented function
     *
     * @param Request $request
     * @return array
     */
    public function updateRoomName(Request $request)
    {
        $labelInfo = [
            'label_id'      => '14073750253938239',
            'label_groupid' => '14073750563560224',
            'name'          => '已成交',
        ];
        try {
            $orgId  = $request->param('org_id', '');
            $mobile = $request->param('mobile', '');
            $isVip  = (int) $request->param('is_vip', 0);

            if (empty($mobile)) {
                return $this->error('手机号不能为空');
            }

            if (empty($orgId)) {
                return $this->error('企业ID不能为空');
            }

            $contact = Db::table('wxwork_apply_contact_list')
                ->where('mobile', $mobile)
                ->find();

            if (empty($contact)) {
                return $this->error('该手机号用户不存在');
            }

            $userId = $contact['user_id'] ?? '';
            $existingBindOrgId = $contact['bind_org_id'] ?? '';

            if (empty($existingBindOrgId)) {
                Db::table('wxwork_apply_contact_list')
                    ->where('mobile', $mobile)
                    ->update([
                        'bind_org_id' => $orgId,
                        'update_at' => date('Y-m-d H:i:s'),
                    ]);                
            }            
            // 如果is_vip为1，则修改用户标签
            if ($isVip == 1) {
                $this->applyLabel($contact['user_id'], $labelInfo, $orgId);
            }
            // 重命名群名称
            $this->renameRoom($userId, $orgId);

            return $this->success([
                'user_id'    => $userId,
                'bind_org_id' => $orgId,
                'is_vip'     => $isVip,
            ], '更新成功');
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'UpdateRoomName',
                'message' => '更新房间名称异常',
                'data'    => [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }
    /**
     * 重命名群名称（在原名称前加 VIP）
     *
     * @param string $userId 用户ID
     * @param Output $output 输出对象
     * @param int $orgId 企业ID
     */
    private function renameRoom(string $userId, int $orgId): void
    {
        // 根据 org_id 查找 tax_org 中的企业名称
        $orgName = Db::table('tax_org')
            ->where('tax_id', $orgId)
            ->value('name');
        if (empty($orgName)) {
            return;
        }
        // $orgName 最多8个字，超过的用...代替
        if (mb_strlen($orgName) > 8) {
            $orgName = mb_substr($orgName, 0, 8) . '...';
        }

        // 通过 user_id 查找 contact_room 中的群信息和群名称
        $contactRoom = Db::table('wxwork_contact_room')
            ->where('user_id', $userId)
            ->find();

        if (empty($contactRoom)) {
            return;
        }

        $roomId = $contactRoom['room_id'] ?? '';
        $currentName = $contactRoom['room_name'] ?? '';
        // 一键零申报&ganganlee（钟玲-2026年04月11日）
        // 当前名称中不包含企业名称的话，把企业名称替换 &"企业名称"（ 之前的多余部分
        if (strpos($currentName, $orgName) === false) {
            $currentName = preg_replace('/&.+?（/', "&" . $orgName . "（", $currentName, 1);
        }
        if (empty($currentName)) {
            return;
        }

        // 如果已经带有 VIP 前缀，则不处理
        if (strpos($currentName, 'VIP') === 0) {            
            return;
        }

        $newName = 'VIP' . $currentName;
        
        try {
            // 调用 JuhebotService 修改群名称
            $juhebot = $this->getJuhebot();
            $juhebot->modifyRoomName($roomId, $newName);

            // 更新 contact_room 表中的群名称
            Db::table('wxwork_contact_room')
                ->where('user_id', $userId)
                ->update(['room_name' => $newName, 'update_at' => date('Y-m-d H:i:s')]);

            LogService::info([
                'tag'     => 'RefreshLabel',
                'message' => '群名称重命名成功',
                'data'    => [
                    'user_id'  => $userId,
                    'room_id'  => $roomId,
                    'old_name' => $currentName,
                    'new_name' => $newName,
                ],
            ]);
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'RefreshLabel',
                'message' => '群名称重命名失败',
                'data'    => [
                    'user_id' => $userId,
                    'room_id' => $roomId,
                    'error'   => $e->getMessage(),
                ], 
            ]);
        }
    }

    /**
     * 调用 JuhebotService 为用户打单个标签，并写入标签日志
     *
     * @param string      $userId
     * @param array       $labelInfo
     * @throws \RuntimeException 打标签失败时抛出
     */
    private function applyLabel(string $userId, array $labelInfo, int $orgId): void
    {
        if (empty($labelInfo)) {
            return;
        }
        // 如果标签已存在，则不重复打标签
        $labelLog = LabelLog::where('user_id', $userId)
            ->where('org_id', $orgId)
            ->where('label_id', $labelInfo['label_id'])
            ->find();
        if (!empty($labelLog)) {
            return;
        }

        $juhebot = $this->getJuhebot();
        $res = $juhebot->contactAddLabel([$userId], $labelInfo['label_id'], self::CORP_ID, $labelInfo['label_groupid']);        
        LabelLog::logSuccess(
            $userId, $orgId,
            $labelInfo['label_id'], $labelInfo['label_groupid'], 1,
            null, 0, []
        );
    }


    /**
     * 根据推送范围获取用户列表
     */
    private function getScopeUsers(int $scope, array $field = ['*'], string $mobile = ''): array
    {
        $user_id = Db::table('wxwork_apply_contact_list')->where('mobile', $mobile)->value('user_id');
        if(empty($user_id)){
            return [];
        }
        $result = Db::table('wxwork_contact_room')
        ->where('status', '=', 1)
        ->where('user_id', $user_id)->field($field)->find();
        if(empty($result)){
            return [
                "user_id" => $user_id,
                "room_id" => '',
            ];
        }
        return $result;
    }

}
