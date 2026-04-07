<?php
declare(strict_types=1);

namespace app\service;

use app\model\JuhebotMessageCallback;
use app\model\ApplyContactList;
use app\model\ContactRoom;
use app\model\RoomList;
use think\facade\Db;

/**
 * 消息回调业务处理服务
 * 统一处理消息回调和好友申请的业务逻辑
 */
class MessageCallbackService
{
    private JuhebotService $juhebot;

    public function __construct()
    {
        $this->juhebot = new JuhebotService();
    }

    /**
     * 处理待处理的消息回调
     *
     * @param string|null $guid 指定GUID，不指定则处理全部
     * @param int $limit 每次处理的数量
     * @return array ['success' => int, 'fail' => int]
     */
    public function processMessageCallbacks(?string $guid, int $limit = 100): array
    {
        $messages = !empty($guid)
            ? JuhebotMessageCallback::getPendingMessagesByGuid($guid, $limit)
            : JuhebotMessageCallback::getPendingMessages($limit);

        if (empty($messages)) {
            return ['success' => 0, 'fail' => 0];
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($messages as $message) {
            try {
                $this->handleMessage($message);
                JuhebotMessageCallback::markAsProcessed($message['id']);
                $successCount++;
            } catch (\Throwable $e) {
                $failCount++;
                LogService::error([
                    'tag'     => 'MessageCallback',
                    'message' => '处理单条消息失败',
                    'data'    => [
                        'message_id' => $message['id'],
                        'error'     => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ],
                ]);
            }
        }

        return ['success' => $successCount, 'fail' => $failCount];
    }

    /**
     * 处理待处理的好友申请
     *
     * @param string|null $guid 指定GUID
     * @param int $limit 每次处理的数量
     * @return array ['success' => int, 'fail' => int]
     */
    public function processApplyContacts(?string $guid, int $limit = 100): array
    {
        $guid = $guid ?: env('JUHEBOT.GUID', '');
        $applies = ApplyContactList::getPendingList(10);
        
        if (empty($applies)) {
            return ['success' => 0, 'fail' => 0];
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($applies as $apply) {
            try {
                $this->handleApplyContact($apply);
                $successCount++;
                ApplyContactList::markAsAgreed($apply['id']);
            } catch (\Throwable $e) {
                $failCount++;
                LogService::error([
                    'tag'     => 'MessageCallback',
                    'message' => '处理好友申请失败',
                    'data'    => [
                        'apply_id' => $apply['id'],
                        'error'    => $e->getMessage(),
                        'file'     => $e->getFile(),
                        'line'     => $e->getLine(),
                    ],
                ]);
            }
        }
        return ['success' => $successCount, 'fail' => $failCount];
    }

    /**
     * 处理单条消息
     *
     * @param array $message
     * @return void
     */
    public function handleMessage(array $message): void
    {
        $notifyType = $message['notify_type'] ?? 0;

        LogService::info([
            'tag'     => 'MessageCallback',
            'message' => '开始处理消息',
            'data'    => [
                'notify_type' => $notifyType,
                'sender'      => $message['sender'] ?? '',
                'sender_name' => $message['sender_name'] ?? '',
                'content'     => $message['content'] ?? '',
            ],
        ]);

        switch ($notifyType) {
            case 11010:
                $this->handleNormalMessage($message);
                break;

            default:
                LogService::warning([
                    'tag'     => 'MessageCallback',
                    'message' => '未知的消息类型',
                    'data'    => [
                        'notify_type' => $notifyType,
                        'message_id'  => $message['id'] ?? 0,
                    ],
                ]);
                break;
        }
    }

    /**
     * 处理普通消息（11010）
     *
     * @param array $message
     * @return void
     */
    public function handleNormalMessage(array $message): void
    {
        $content = $message['content'] ?? '';
        $senderName = $message['sender_name'] ?? '';
        $sender = $message['sender'] ?? '';

        // 1、同意好友请求
        $this->juhebot->agreeContact($sender);

        // 2、创建外部群（含客服人员）
        $roomResult = $this->juhebot->createOuterRoom([$sender, '1688853366477816']);

        // 3、发送欢迎语
        $roomId = $roomResult['data']['roomid'] ?? '';
        $content =  "您好，欢迎咨询一键零申报！\n\n✨ 让报税，像点外卖一样简单！\n我们专注为全国小微企业、个体工商户，提供智能、合规、极简的一站式财税服务。\n\n 💰 服务价格 · 清晰透明\n\n小规模纳税人 / 个体工商户：\n1️⃣ 自助申报：0 元/年（不含工商税务年报）\n2️⃣ 托管申报（全程代办）：360 元/年\n\n一般纳税人：\n1️⃣零申报 ：360 元/年\n2️⃣非零申报 ：998 元/年\n\n一键开票：199 元/年（电子发票）";
        $this->juhebot->sendText(
            'R:' . $roomId,
            $content
        );

        LogService::info([
            'tag'     => 'MessageCallback',
            'message' => '普通消息处理完成',
            'data'    => [
                'message_id' => $message['id'] ?? 0,
                'room_id'    => $roomId,
            ],
        ]);
    }

    /**
     * 处理单条好友申请
     *
     * @param array $apply
     * @return void
     */
    public function handleApplyContact(array $apply): void
    {
        $roomName = $apply['room_name'] ?? '';
        $userId = $apply['user_id'] ?? '';
        $applyId = $apply['id'] ?? 0;

        LogService::info([
            'tag'     => 'MessageCallback',
            'message' => '开始处理好友申请',
            'data'    => [
                'apply_id'  => $applyId,
                'room_name' => $roomName,
                'user_id'   => $userId,
            ],
        ]);

        $labelInfo = [
            'label_id'      => '14073753009969296',
            'corp_or_vid'   => '1970324956094061',
            'label_groupid' => '14073749395893864',
            'business_type' => 0,
        ];
        $this->juhebot->updateContact($userId, '', '', '', '', [], $labelInfo);

        if ($apply['in_room'] != 0) {
            return;
        }

        $defaultUserList = [
            '7881300953909122',
            '1688857676604016',
            '1688853366478174',
            '1688858005772698',
            '1688858160817011',
            '1688853366655965',
            '1688853366477841',
            '1688853366477816',
        ];

        $roomResult = $this->juhebot->createOuterRoom(array_merge($defaultUserList, [$userId]));
        $roomId = $roomResult['data']['roomid'] ?? 0;

        $this->juhebot->sendText(
            'R:' . $roomId,
            ($apply['name'] ?? '') . ' 欢迎加入群聊，我们将为您提供专业的一对一服务。'
        );

        sleep(rand(1, 5));

        $roomName = $this->getRoomName($apply) ?: ($apply['name'] ?? '');

        $this->juhebot->modifyRoomName($roomId, "一键零申报&{$roomName}");

        ContactRoom::create([
            'room_id' => $roomId,
            'user_id' => $userId,
        ]);

        ApplyContactList::markAsInRoom($applyId, ApplyContactList::STATUS_AGREED);

        LogService::info([
            'tag'     => 'MessageCallback',
            'message' => '好友申请处理完成',
            'data'    => [
                'apply_id'  => $applyId,
                'room_id'   => $roomId,
                'user_id'   => $userId,
                'room_name' => $roomName,
            ],
        ]);
    }

    public function getRoomName($apply): string
    {
        if (!$apply['bind_org_id']) {
            // 没找到的话可以再去查询一下
            $bind_org_id = $this->updateApplyContact($apply['name']);
            if (!$bind_org_id) {
                return $apply['name'] ?? '';
            }
            $apply['bind_org_id'] = $bind_org_id;
        }
        $orgName = Db::table("tax_org")->where("tax_id", $apply['bind_org_id'])->value("name");
        return $orgName ?: ($apply['name'] ?? '');
    }

    /**
     * 强制更新一波
     */
    private function updateApplyContact($name): string
    {
        $contact = Db::table("wxwork_group_chat_log")->where("customer_name", $name)
        ->where("mobile", "!=", "")
        ->limit(1)->find();
        if ($contact && $contact['mobile']) {
            $apply = ApplyContactList::where("name", $name)->limit(1)->find();
            if ($apply) {
                $apply['mobile'] = $contact['mobile'];
                $apply['bind_org_id'] = Db::table("tax_members")->where("phone", $contact['mobile'])->value("org_id") ?? '';
                $apply->save();
                return $apply['bind_org_id'];
            }
        }
        return '';
    }
}
