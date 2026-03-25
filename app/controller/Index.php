<?php
namespace app\controller;

use app\BaseController;
use app\service\LogService;
use think\facade\Db;

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
        $total = Db::table('group_chat_log')->where("is_process","=", 0)->field(["id", "external_userid","staff_userid","customer_name"])->find();
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => $total,
        ]);
    }

    /**
     * {
        "guid": "xxx",
        "notify_type": 11010,
        "data": {
            "seq": "7272646",
            "id": "1067188",
            "appinfo": "CAUQu+WWsAYYzKLu+vKDwOAXIMzQh6QF",
            "sender": "16888",
            "receiver": "788",
            "roomid": "0",
            "sendtime": 1711649467,
            "sender_name": "小助手",
            "content_type": 2,
            "referid": "0",
            "flag": 16777216,
            "content": "hello world",
            "at_list": [],
            "quote_content": "",
            "quote_appinfo": "",
            "send_flag": 1,
            "msg_type": 2
        }
    }
     */

    /**
     * 处理 Juhebot 消息回调
     *
     * 请求参数：
     * {
     *   "guid": "xxx",
     *   "notify_type": 11010,
     *   "data": {
     *     "seq": "7272646",
     *     "id": "1067188",
     *     "appinfo": "CAUQu+WWsAYYzKLu+vKDwOAXIMzQh6QF",
     *     "sender": "16888",
     *     "receiver": "788",
     *     "roomid": "0",
     *     "sendtime": 1711649467,
     *     "sender_name": "小助手",
     *     "content_type": 2,
     *     "referid": "0",
     *     "flag": 16777216,
     *     "content": "hello world",
     *     "at_list": [],
     *     "quote_content": "",
     *     "quote_appinfo": "",
     *     "send_flag": 1,
     *     "msg_type": 2
     *   }
     * }
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

            if (empty($guid) || empty($data)) {
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
            $result = Db::table('juhebot_message_callback')->insert([
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

                // TODO: 根据业务需求处理消息
                // 例如：自动回复、转发消息等

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
}
