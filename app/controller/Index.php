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

            LogService::info([
                'tag'     => 'JuhebotCallback',
                'message' => '接收到消息回调',
                'data'    => $params,
            ]);

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
                LogService::info([
                    'tag'     => 'JuhebotCallback',
                    'message' => '消息保存成功',
                    'data'    => [
                        'message_id' => $messageId,
                        'sender'     => $sender,
                        'content'    => $content,
                    ],
                ]);                

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
}
