<?php
namespace app\controller;

use app\BaseController;
use app\model\ApplyContactList;
use app\model\ContactRoom;
use app\service\JuhebotService;
use app\service\LogService;
use app\service\WxWorkService;
use think\facade\Db;
use think\facade\Event;
use think\Request;

class Index extends BaseController
{
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
                return $this->success($res,'动态二维码获取成功');
            }
            $roomId = ContactRoom::where("user_id", $user_id)->value("room_id");
            if(empty($roomId)){
                $service = new WxWorkService();
                $res = $service->addContactWay($mobile);
                return $this->success($res,'动态二维码获取成功');
            }
            // 获取群二维码
            $service = new JuhebotService();
            $res = $service->getRoomQRCode($roomId, $qr_code);
            $result = $res['data'];
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
            $juhebot = new JuhebotService();
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
     * 发送消息
     * send_type: 1=文本，2=小程序
     * msg_type: 1=文字，2=图片，3=链接（仅send_type=1有效）
     * scope: 1=群+个人，2=群，3=个人
     */
    public function sendMessage(Request $request)
    {
        try {
            $sendType = (int) $request->param('send_type', 0);
            $msgType  = (int) $request->param('msg_type', 1);
            $content  = $request->param('content', '');
            $scope    = (int) $request->param('scope', 1);
            $mobile = $request->param('mobile', '');
            $path = $request->param('path', '');// 小程序路径

            if(empty($mobile)){
                return $this->error('手机号不能为空');
            }
            if (!in_array($sendType, [1, 2])) {
                return $this->error('发送方式仅支持1(文本)或2(小程序)');
            }
            if ($sendType == 1 && empty($content)) {
                return $this->error('消息内容不能为空');
            }
            // return ;

            $service = new JuhebotService();

            $scopeInfo = $this->getScopeUsers($scope, ['user_id', 'room_id'], $mobile);
            
            if (empty($scopeInfo)) {
                return $this->error('未找到符合条件的用户');
            }
            $success = 0;
            $fail = 0;
            if ($scope === 3) {
                $conversationUserId = 'S:' . ($scopeInfo['user_id'] ?? '');
            }
            if ($scope === 2) {
                $conversationRoomId = 'R:' . ($scopeInfo['room_id'] ?? '');
            }
            
            // 小程序发送方式
            if ($sendType === 2) {
                try {
                    switch ($scope) {
                        case 1:
                            $service->sendWeApp($conversationUserId, 
                            '', 
                            '一键零申报', 
                            'http://wx.qlogo.cn/mmhead/7SPO0mRJt6BfLTkRTASKrUvNmibO4IBHgBibhuZuKhD6kXL9iav0FLJwzlRpLzR6vdeEDONKdIVVjw/96', 
                            '一键零申报-不止零申报', 
                            $path);
                            $service->sendWeApp($conversationRoomId, 
                            '', 
                            '一键零申报', 
                            'http://wx.qlogo.cn/mmhead/7SPO0mRJt6BfLTkRTASKrUvNmibO4IBHgBibhuZuKhD6kXL9iav0FLJwzlRpLzR6vdeEDONKdIVVjw/96', 
                            '一键零申报-不止零申报', 
                            $path);
                            break;
                        case 2:
                            $service->sendWeApp($conversationRoomId, 
                            '', 
                            '一键零申报', 
                            'http://wx.qlogo.cn/mmhead/7SPO0mRJt6BfLTkRTASKrUvNmibO4IBHgBibhuZuKhD6kXL9iav0FLJwzlRpLzR6vdeEDONKdIVVjw/96', 
                            '一键零申报-不止零申报', 
                            $path);
                            break;
                        case 3:
                            $service->sendWeApp($conversationUserId, 
                            '', 
                            '一键零申报', 
                            'http://wx.qlogo.cn/mmhead/7SPO0mRJt6BfLTkRTASKrUvNmibO4IBHgBibhuZuKhD6kXL9iav0FLJwzlRpLzR6vdeEDONKdIVVjw/96', 
                            '一键零申报-不止零申报', 
                            $path);
                            break;
                    }                                     
                    $success++;
                } catch (\Throwable $e) {
                    $fail++;
                }
                return $this->success(['success' => $success, 'fail' => $fail], '小程序发送完成');
            } else {
                // 文本发送方式 - 目前仅支持文字消息
                try {                    
                    switch ($scope) {
                        case 1:
                            $service->sendText($conversationUserId, $content);
                            $service->sendText($conversationRoomId, $content);
                            break;
                        case 2:
                            $service->sendText($conversationRoomId, $content);
                            break;
                        case 3:
                            $service->sendText($conversationUserId, $content);
                            break;
                    }
                    $success++;
                } catch (\Throwable $e) {
                    $fail++;
                }
                return $this->success(['success' => $success, 'fail' => $fail], '文本发送完成');
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
        return $result;
    }
}
