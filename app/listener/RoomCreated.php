<?php
declare(strict_types=1);

namespace app\listener;

use app\model\ApplyContactList;
use app\model\GroupChatLog;
use app\model\Keywords;
use app\service\InvoiceSessionService;
use app\service\JuhebotService;
use app\service\LogService;
use Fukuball\Jieba\Finalseg;
use Fukuball\Jieba\Jieba;
use think\facade\Cache;
use think\facade\Db;

/**
 * 建群事件监听器
 */
class RoomCreated
{
    // 消息通知类型枚举
    const NOTIFY_TYPE_UNKNOWN = 0;                       // 未知类型
    const NOTIFY_TYPE_MANAGER_SEND_TASK = 573;          // 管理员发送任务通知
    const NOTIFY_TYPE_READY = 11001;                     // 机器人就绪（初始化完成，可正常收发消息）
    const NOTIFY_TYPE_LOGIN_QR_CODE_CHANGE = 11002;      // 登录二维码变更（需重新扫码）
    const NOTIFY_TYPE_USER_LOGIN = 11003;                // 用户登录成功
    const NOTIFY_TYPE_USER_LOGOUT = 11004;               // 用户登出
    const NOTIFY_TYPE_INIT_FINISH = 11005;               // 初始化完成
    const NOTIFY_TYPE_HEART_BEAT_ERROR = 11006;          // 心跳错误（连接异常，需重连）
    const NOTIFY_TYPE_SESSION_TIMEOUT = 11007;           // 会话超时
    const NOTIFY_TYPE_LOGIN_FAILED = 11008;              // 登录失败
    const NOTIFY_TYPE_CONTACT_SYNC_FINISH = 11009;       // 联系人同步完成
    const NOTIFY_TYPE_NEW_MSG = 11010;                    // 收到新消息
    const NOTIFY_TYPE_LOGIN_OTHER_DEVICE = 11011;        // 账号在其他设备登录
    const NOTIFY_TYPE_LOGIN_SAFE_VERIFY = 11012;         // 安全验证（需在后台扫码或调用二维码接口重新扫码，过期会退登）
    const NOTIFY_TYPE_BATCH_NEW_MSG = 11013;              // 批量收到新消息
    const NOTIFY_TYPE_FRIEND_CHANGE = 2131;               // 好友变更（调用同步联系人接口，传入 seq 获取增量数据）
    const NOTIFY_TYPE_FRIEND_APPLY = 2132;               // 好友申请（调用同步申请好友列表接口，传入 seq 获取增量数据）
    const NOTIFY_TYPE_ROOM_NAME_CHANGE = 1001;           // 群名称变更
    const NOTIFY_TYPE_ROOM_DISMISS = 1023;               // 群解散
    const NOTIFY_TYPE_SYSTEM_TIPS = 1037;                 // 系统提示消息
    const NOTIFY_TYPE_ROOM_INFO_CHANGE = 2118;            // 群信息变更（调用群增量同步接口，传入 version 获取增量数据）
    const NOTIFY_TYPE_ROOM_MEMBER_ADD = 1002;            // 群成员增加
    const NOTIFY_TYPE_ROOM_MEMBER_DEL = 1003;            // 群成员减少
    const NOTIFY_TYPE_ROOM_KICK_MEMBER = 1004;           // 群踢出成员
    const NOTIFY_TYPE_ROOM_EXIT = 1005;                   // 主动退出群
    const NOTIFY_TYPE_ROOM_CREATE = 1006;                 // 群创建成功
    const NOTIFY_TYPE_ROOM_CONFIRM_ADD_MEMBER_NOTIFY = 1029; // 确认添加群成员通知
    const NOTIFY_TYPE_MUTA_INFO_CHANGE = 2115;            // 会话设置信息变更
    const NOTIFY_TYPE_VOIP_NOTIFY = 2166;                 // 语音通话通知
    const NOTIFY_TYPE_WEWORK_VOIP_NOTIFY = 2120;          // 企业微信通话通知
    const NOTIFY_TYPE_SNS_CHANGE_NOTIFY = 2215;           // 朋友圈变更通知
    const NOTIFY_TYPE_SNS_NOTIFY = 529;                   // 朋友圈通知
    const NOTIFY_TYPE_ADMIN_TIPS_NOTIFY = 573;             // 管理员发消息通知

    private static ?JuhebotService $juhebotService = null;

    private static ?InvoiceSessionService $invoiceService = null;
    /**
     * 获取 JuhebotService 单例
     *
     * @return JuhebotService
     */
    private function getJuhebot(): JuhebotService
    {
        if (self::$juhebotService === null) {
            self::$juhebotService = new JuhebotService();
        }
        return self::$juhebotService;
    }

    /**
     * 处理建群事件
     *
     * @param array $event
     * @return void
     */
    public function handle(array $event): void
    {
        $guid = $event['guid'] ?? '';
        $notifyType = $event['notify_type'] ?? 0;
        $data = $event['data'] ?? [];
        LogService::info([
            'tag'     => 'RoomCreated',
            'message' => '收到建群事件',
            'data'    => [
                'guid'        => $guid,
                'notify_type' => $notifyType,
                'data'        => $data,
            ],
        ]);
        try {
            if (empty($guid)) {
                return;
            }

            switch ($notifyType) {
                case self::NOTIFY_TYPE_FRIEND_APPLY:
                    $result = $this->syncApplyContact();
                    LogService::info([
                        'tag'     => 'RoomCreated',
                        'message' => "{$result}位好友申请处理成功！"
                    ]);
                    break;

                case self::NOTIFY_TYPE_FRIEND_CHANGE:
                    $result = $this->syncContact();
                    LogService::info([
                        'tag'     => 'RoomCreated',
                        'message' => "{$result}位联系人处理成功！"
                    ]);
                    break;
                case self::NOTIFY_TYPE_NEW_MSG: // notify_type = 11010,content_type = 2\
                    if (empty($data['at_list']) || !in_array("1688853366477816", $data['at_list'])) {
                        return;
                    }
                    // 处理普通消息
                    $this->handleNormalMessage($data);// 处理普通消息
                    break;
                default:
                    break;
            }
        } catch (\Throwable $e) {
            LogService::error([
                'tag'     => 'RoomCreated',
                'message' => '处理建群事件失败',
                'data'    => [
                    'error'   => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'event'   => $event,
                ],
            ]);
        }
    }

    /**
     * 同步联系人
     */
    protected function syncContact()
    {
        $seq = ApplyContactList::order('id', 'desc')->limit(1)->value('seq');
        // 同步联系人
        $contactList = $this->getJuhebot()->syncContact($seq);

        if (empty($contactList['data']['contact_list'])) {
            return 0;
        }
        $insertCount = 0;
        foreach ($contactList['data']['contact_list'] as $contact) {
            if (ApplyContactList::where("user_id",$contact['user_id'])->limit(1)->find()) {
                 LogService::info([
                    'tag'     => 'RoomCreated',
                    'message' => '联系人已存在',
                    'data'    => $contact,
                ]);
                continue;
            }
            // 处理每个联系人
            $insertData = $contact;
            $insertData['extend_info_desc'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark'] = $contact['extend_info']['desc'];
            $insertData['extend_info_company_remark'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark_time'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark_url'] = $contact['extend_info']['desc'];
            $insertData['mobile'] = GroupChatLog::where("customer_name", $contact['name'])->where("mobile", "!=", "")->limit(1)->value("mobile");
            $insertData['bind_org_id'] = $insertData['mobile']?$this->getUserOrgId($insertData['mobile']):0;
            ApplyContactList::create($insertData);
            $insertCount++;
        }
        return $insertCount;
    }

    /**
     * 同步好友申请
     * @param array $event
     * @return int
     */
    protected function syncApplyContact()
    {
        $seq = Cache::get('last_apply_seq');
        // 同步好友申请
        $contactList = $this->getJuhebot()->syncApplyContact($seq);
        
        LogService::info([
            'tag'     => 'AutoGroup',
            'message' => '同步好友申请',
            'data'    => $contactList,
        ]);
        
        if (empty($contactList['data']['contact_list'])) {
            return;
        }
        Cache::set('last_apply_seq', $contactList['data']['last_seq']); // 缓存SEQ
        $insertCount = 0;
        foreach ($contactList['data']['contact_list'] as $contact) {
            if (ApplyContactList::where("user_id",$contact['user_id'])->limit(1)->find()) {
                 LogService::info([
                    'tag'     => 'AutoGroup',
                    'message' => '好友申请已存在',
                    'data'    => $contact,
                ]);
                continue;
            }
            // 处理每个联系人
            $insertData = $contact;
            $insertData['extend_info_desc'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark'] = $contact['extend_info']['desc'];
            $insertData['extend_info_company_remark'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark_time'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark_url'] = $contact['extend_info']['desc'];
            $insertData['mobile'] = GroupChatLog::where("customer_name", $contact['name'])->where("mobile", "!=", "")->limit(1)->value("mobile");
            $insertData['bind_org_id'] = $insertData['mobile']?$this->getUserOrgId($insertData['mobile']):0;
            ApplyContactList::create($insertData);
            $insertCount++;
        }
        return $insertCount;
    }

    /**
     * 获取ORGid
     */
    private function getUserOrgId(string $mobile): int
    {
        $org_id = Db::table("tax_members")->where("phone", $mobile)->value("org_id");
        if (empty($org_id)) {
            return 0;
        }
        return (int)$org_id;
    }

    /**
     * 处理文本消息
     * @param array $data
     * @return void
     */
    private function handleNormalMessage(array $data): void
    {
        $sender = $data['sender'] ?? ''; // 发送用户ID
        $senderName = $data['sender_name'] ?? ''; // 发送用户名称
        $content = $data['content'] ?? ''; // 消息内容
        $roomId = $data['roomid'] ?? ''; // 群ID
        $goInvoice = false;

        // $hotWords = HotWordList::where("status",1)->select();        
        ini_set('memory_limit', '1024M');
        Jieba::init();
        Finalseg::init();
        // 截取 @一键零申报 哈哈发撒龙卷风  如何去掉@一键零申报
        $content = preg_replace('/@一键零申报/s', '', $content);
        // $this->getJuhebot()->sendText("R:".$roomId, $content);
        // $content = preg_replace('/@[^\s]+/s', '', $content);
        LogService::info([
            'tag'     => 'RoomCreated',
            'message' => 'data内容：',
            'data'    => $data,
        ]);

        $words = Jieba::cut($content); // 分词
        LogService::info([
            'tag'     => 'RoomCreated',
            'message' => '分词结果',
            'data'    => $words,
        ]);

        $hotWords = Keywords::where("status",1)->column('word');
        
        $is_trigger = false;
        // 遍历$words，看是否在$hotWords中存在
        foreach ($words as $word) {
            if (in_array($word, $hotWords)) {
                $is_trigger = true;
                break;
            }
        }
        if ($is_trigger) {
            // 根据热词库中的内容进行回复
            $conversationId = 'R:'.$roomId;            
            // $this->getJuhebot()->sendWeApp($conversationId);            
        } else {
            // $this->getJuhebot()->sendText("R:".$roomId, "#你好我是群智能助手，欢迎使用一键零申报，这个技能我还没有学会。");
           
        }
        $message = [
            'msg_id'       => time(),
            'room_id'      => $roomId,
            'sender'       => $sender,
            'sender_name'  => $senderName,
            'content'      => $content,
            'at_list'      => $data['at_list'],
            'notify_type'  => self::NOTIFY_TYPE_NEW_MSG,
        ];
        self::getInvoiceService()->handleMessage($message);
    }

    private static function getInvoiceService(): InvoiceSessionService
    {
        if (self::$invoiceService === null) {
            self::$invoiceService = new InvoiceSessionService();
        }
        return self::$invoiceService;
    }
}
