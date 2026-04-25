<?php
declare(strict_types=1);

namespace app\service;

use app\model\InvoiceSession;
use app\model\InvoiceMessage;
use app\job\InvoiceJob;
use think\facade\Db;

/**
 * 开票会话服务
 * 
 * 负责：接收消息、解析触发、收集用户确认/修改、驱动队列向前推进。
 *
 * 简化流程（3步对话）：
 *   用户发送 → Service 创建会话 + 记录原始内容 → 队列发"收到" → 队列解析 + 发确认摘要
 *   用户回复确认/修改 → Service 处理 → 队列提交开票 → 队列通知结果
 */
class InvoiceSessionService
{
    private JuhebotService $juhebot; 

    // 触发关键字
    private const TRIGGER_KEYWORDS = ['开票', '开发票', '要发票', '开电子发票', '开纸质发票', '发票'];

    // 确认关键词（用户确认开票）
    private const CONFIRM_WORDS = ['确认', '确定', '好的', '是', 'ok', 'yes', '开', '开票'];

    // 取消关键词
    private const CANCEL_WORDS = ['取消', '算了', '不要了', 'no', 'cancel'];

    // 是否/自然人关键词映射
    private const NATURAL_PERSON_MAP = [
        '是' => 1, 'yes' => 1, 'no' => 0, '否' => 0, '1' => 1, '0' => 0,
        '个人' => 1, '自然人' => 1, '企业' => 0, '公司' => 0,
    ];

    // 票种关键词映射
    private const INVOICE_TYPE_MAP = [
        '普票'   => ['type' => InvoiceSession::INVOICE_TYPE_ELECTRONIC, 'label' => InvoiceSession::LABEL_NORMAL],
        '普通'   => ['type' => InvoiceSession::INVOICE_TYPE_ELECTRONIC, 'label' => InvoiceSession::LABEL_NORMAL],
        '电子'   => ['type' => InvoiceSession::INVOICE_TYPE_ELECTRONIC, 'label' => InvoiceSession::LABEL_NORMAL],
        '专票'   => ['type' => InvoiceSession::INVOICE_TYPE_PAPER, 'label' => InvoiceSession::LABEL_SPECIAL],
        '专用'   => ['type' => InvoiceSession::INVOICE_TYPE_PAPER, 'label' => InvoiceSession::LABEL_SPECIAL]
    ];

    public function __construct()
    {
        $this->juhebot = new JuhebotService();
    }

    /**
     * 主入口：处理单条群消息
     *
     * @param array $message 消息回调数据
     * @return bool 消息是否被消费
     */
    public function handleMessage(array $message): bool
    {
        $msgId      = (string)($message['msg_id'] ?? $message['id'] ?? '');
        $roomId     = (string)($message['room_id'] ?? '');
        $sender     = (string)($message['sender'] ?? '');
        $senderName = (string)($message['sender_name'] ?? '');
        $content    = trim($message['content'] ?? '');

        if ($roomId === '' || $sender === '' || $content === '') {
            return false;
        }

        if (!$this->isMentioned($message)) {
            return false;
        }

        if ($msgId !== '' && InvoiceMessage::isProcessed($msgId)) {
            return false;
        }

        $session = InvoiceSession::findActiveByRoom($roomId);

        if ($session === null) {
            if ($this->isTriggerKeyword($content)) {
                return $this->startSession($roomId, $sender, $senderName, $content, $msgId);
            }
            return false;
        }

        if ($session->next_action === null || $session->next_action === '') {
            $this->replyToRoom($session->room_id, "开票会话已结束，如需再次开票请重新触发。");
            return true;
        }

        return $this->routeByAction($session, $content, $sender, $senderName, $msgId);
    }

    // ─── 流程启动 ─────────────────────────────────────────────────

    /**
     * 启动新的开票会话
     *
     * 创建会话（next_action=send_ack）→ 投递队列 → 队列发"收到"消息
     */
    private function startSession(string $roomId, string $userId, string $userName, string $content, string $msgId): bool
    {
        $apply = Db::table('wxwork_apply_contact_list')
            ->where('user_id', $userId)
            ->find();

        $orgName = '';
        $orgId   = '';

        if (!empty($apply['bind_org_id'])) {
            $taxOrg = Db::table('tax_org')->where('tax_id', $apply['bind_org_id'])->find();
            if ($taxOrg) {
                $orgId   = (string)$taxOrg['tax_id'];
                $orgName = $taxOrg['name'] ?? '';
            }
        }

        $session = InvoiceSession::createSession([
            'room_id'  => $roomId,
            'user_id'  => $userId,
            'org_id'   => $orgId,
            'org_name' => $orgName,
        ]);

        InvoiceMessage::logUser($session->id, $msgId, $userId, $userName, $content, 'trigger');

        // 将用户原始消息内容存入 step_data，供队列解析使用
        $session->step_data = ['raw_content' => $content];
        $session->save();

        // 投递队列 → 发"收到"消息
        InvoiceJob::drive($session->id);

        LogService::info([
            'tag'    => 'InvoiceSession',
            'message' => '开票会话已启动',
            'data'   => [
                'session_id' => $session->id,
                'room_id'   => $roomId,
                'user_id'   => $userId,
            ],
        ]);

        return true;
    }

    // ─── 按 next_action 路由 ─────────────────────────────────────

    /**
     * 根据会话的 next_action 路由用户输入
     *
     * 说明：next_action 表示队列下一步动作，用户可能在任意两个 Job 之间发消息。
     * 对于队列驱动的动作（send_ack / parse_confirm / submit_invoice / wait_result），
     * 用户无法中途干预，统一引导等待；只有 receive_confirm 才支持交互。
     */
    private function routeByAction(
        InvoiceSession $session,
        string $content,
        string $userId,
        string $userName,
        string $msgId
    ): bool {
        if ($this->isCancelWord($content)) {
            return $this->handleCancel($session, $userId, $userName, $content, $msgId);
        }

        switch ($session->next_action) {
            case InvoiceSession::ACTION_RECEIVE_CONFIRM:
                return $this->handleUserReply($session, $content, $userId, $userName, $msgId);

            // 以下动作由队列 Job 驱动，用户在中途发消息 → 统一引导等待
            case InvoiceSession::ACTION_SEND_ACKNOWLEDGING:
                $this->replyToRoom($session->room_id, "收到，请稍等~");
                return true;

            case InvoiceSession::ACTION_PARSE_AND_CONFIRM:
                $stepData = is_array($session->step_data) ? $session->step_data : (array)$session->step_data;
                $content = $stepData['parsed_content'] ?? '';
                if ($content === '') {
                    $content = "正在解析您的开票信息，请稍等，马上就好~";
                }
                $res = $this->applyUserModification($session, $content);
                
                return true;

            case InvoiceSession::ACTION_SUBMIT_INVOICE:
                // $this->replyToRoom($session->room_id, "正在提交开票申请，请稍等~");
                return true;

            case InvoiceSession::ACTION_WAIT_RESULT:
                // $this->replyToRoom($session->room_id, "正在等待开票结果，请稍等片刻~");
                return true;

            // notify_result → 流程已结束（但 session 可能还没完全清理），忽略新消息
            case InvoiceSession::ACTION_NOTIFY_RESULT:
                $this->replyToRoom($session->room_id, "开票会话已结束，如有疑问请联系客服。");
                return true;

            default:
                LogService::warning([
                    'tag'    => 'InvoiceSession',
                    'message' => '未处理的 next_action，忽略消息',
                    'data'   => [
                        'session_id'  => $session->id,
                        'next_action' => $session->next_action,
                    ],
                ]);
                $this->replyToRoom($session->room_id, "当前流程正在进行中，请稍等。");
                return true;
        }
    }

    /**
     * 处理用户回复（确认/修改/补充）
     */
    private function handleUserReply(
        InvoiceSession $session,
        string $content,
        string $userId,
        string $userName,
        string $msgId
    ): bool {
        InvoiceMessage::logUser($session->id, $msgId, $userId, $userName, $content, 'user_reply');

        $lowerContent = mb_strtolower(trim($content));

        // ─── 取消 ───────────────────────────────────────────────────
        if ($this->isCancelWord($lowerContent)) {
            return $this->handleCancel($session, $userId, $userName, $content, $msgId);
        }

        // ─── 确认开票 ─────────────────────────────────────────────
        if ($this->isConfirmWord($lowerContent)) {
            return $this->handleConfirm($session, $content, $userId, $userName, $msgId); 
        }

        // ─── 修改字段 ─────────────────────────────────────────────
        $modified = $this->applyUserModification($session, $content);

        if ($modified) {
            $session->save();
            $session->confirm_round = ($session->confirm_round ?? 1) + 1;
            $session->save();
            $summary = $session->buildConfirmSummary();
            $this->replyToRoom($session->room_id, "# 已更新信息（第 {$session->confirm_round} 轮确认）。\n\n" . $summary);
            return true;
        }

        // ─── 无法识别 ─────────────────────────────────────────────
        $this->replyToRoom($session->room_id, "未识别到有效指令，请直接回复【确认】开票，或告知需要修改的内容（如：把公司：xxx）金额：200，税号：ABC**3232");
        return true;
    }

    /**
     * 用户确认开票 → 投递提交开票任务
     */
    private function handleConfirm(
        InvoiceSession $session,
        string $content,
        string $userId,
        string $userName,
        string $msgId
    ): bool {
        InvoiceMessage::logUser($session->id, $msgId, $userId, $userName, $content, 'confirm');

        $session->updateLatestMsgId($msgId);

        // 直接修改 next_action 为提交开票
        $session->next_action = InvoiceSession::ACTION_SUBMIT_INVOICE;
        $session->save();

        InvoiceJob::drive($session->id);

        LogService::info([
            'tag'    => 'InvoiceSession',
            'message' => '用户确认开票，投递提交任务',
            'data'   => [
                'session_id'  => $session->id,
                'company_name'=> $session->company_name,
                'amount'      => $session->amount,
            ],
        ]);

        return true;
    }

    /**
     * 取消会话
     */
    private function handleCancel(InvoiceSession $session, string $userId, string $userName, string $content, string $msgId): bool
    {
        InvoiceMessage::logUser($session->id, $msgId, $userId, $userName, $content, 'cancel');
        $session->markCancelled();
        $this->replyToRoom($session->room_id, "已取消开票，有需要随时@我。");
        return true;
    }

    /**
     * 解析并应用用户对字段的修改
     *
     * 支持的修改格式：
     *   公司：xxx      / 公司改成xxx    / 公司名xxx
     *   税号：xxx      / 税号改成xxx
     *   金额：xxx      / 金额改成xxx
     *   票种：普票/专票 / 票种改成专票
     *   自然人：是/否
     *   项目：xxx       / 项目改成xxx
     *
     * @return bool 是否有字段被修改
     */
    private function applyUserModification(InvoiceSession $session, string $content): bool
    {
        $text = preg_replace('/[\r\n：:，,。.\s]+/u', ' ', $content);
        $text = trim($text);
        $lowerText = mb_strtolower($text);
        $modified = false;

        // ─── 公司名称 ─────────────────────────────────────────────
        if (preg_match('/(?:公司|抬头|企业名称|企业|名称)[：:变]*(.+)/ui', $content, $m)) {
            $val = trim($m[1]);
            if ($val !== '' && $val !== $session->company_name) {
                $session->company_name = $val;
                $modified = true;
            }
        }

        // ─── 税号 ────────────────────────────────────────────────
        if (preg_match('/(?:税号)[：:变]*(.+)/ui', $content, $m)) {
            $val = trim($m[1]);
            if ($val !== '' && $val !== $session->tax_no) {
                $session->tax_no = $val;
                $modified = true;
            }
        } elseif (preg_match('/[0-9A-Z]{15,24}/', $text, $m)) {
            // 没有前缀，但有15位以上数字字母 → 当税号处理
            if ($m[0] !== $session->tax_no) {
                $session->tax_no = $m[0];
                $modified = true;
            }
        }
        $session->tax_no = strtoupper($session->tax_no);

        // ─── 金额 ────────────────────────────────────────────────
        if (preg_match('/(?:金额|钱|总额|开票金额)[：:变]*(\d+(?:\.\d{1,2})?)/ui', $content, $m)) {
            $val = round((float)$m[1], 2);
            if ($val > 0 && (string)$val !== (string)$session->amount) {
                $session->amount = $val;
                $items = is_array($session->items) ? $session->items : [];
                if (!empty($items)) {
                    foreach ($items as $i => $item) {
                        $qty = isset($item['qty']) ? (float)$item['qty'] : 1.0;
                        $items[$i]['price']  = (string)round($val / $qty, 2);
                        $items[$i]['amount'] = (string)$val;
                    }
                    $session->items = $items;
                } else {
                    $session->items = [[
                        'name'   => '服务费',
                        'qty'    => '1.0',
                        'unit'   => '项',
                        'price'  => (string)$val,
                        'amount' => (string)$val,
                    ]];
                }
                $modified = true;
            }
        }

        // ─── 票种 ────────────────────────────────────────────────
        foreach (self::INVOICE_TYPE_MAP as $kw => $info) {
            if (mb_strpos($content, $kw) !== false) {
                if ($session->invoice_type !== $info['type']) {
                    $session->invoice_type       = $info['type'];
                    $session->invoice_type_label = $info['label'];
                    $modified = true;
                }
                break;
            }
        }

        // ─── 是否自然人 ───────────────────────────────────────────
        if (preg_match('/(?:自然人|个人|企业|公司|是否为自然人)[：:变]*(.+)/ui', $content, $m)) {
            $val = mb_strtolower(trim($m[1]));
            if (isset(self::NATURAL_PERSON_MAP[$val])) {
                $parsed = self::NATURAL_PERSON_MAP[$val];
                if ((int)$session->is_natural_person !== $parsed) {
                    $session->is_natural_person = $parsed;
                    $modified = true;
                }
            }
        }

        // ─── 项目 ─────────────────────────────────────────────────
        if (preg_match('/(?:项目|服务项目|服务)[：:变]*(.+)/ui', $content, $m)) {
            $name = trim($m[1]);
            if ($name !== '') {
                $items = is_array($session->items) ? $session->items : [];
                if (empty($items)) {
                    $items[] = [
                        'name'   => $name,
                        'qty'    => '1.0',
                        'unit'   => '项',
                        'price'  => (string)($session->amount ?? 0),  
                        'amount' => (string)($session->amount ?? 0),
                    ];
                } else {
                    $items[0]['name'] = $name;
                }
                $session->items = $items;
                $modified = true;
            }
        }

        if ($modified) {
            $session->save();
        }

        return $modified;
    }

    // ─── 辅助方法 ─────────────────────────────────────────────

    /**
     * 判断是否为确认词
     */
    private function isConfirmWord(string $content): bool
    {
        foreach (self::CONFIRM_WORDS as $w) {
            if (mb_strpos($content, mb_strtolower($w)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断是否为取消词
     */
    private function isCancelWord(string $content): bool
    {
        foreach (self::CANCEL_WORDS as $w) {
            if (mb_strpos($content, mb_strtolower($w)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断是否为触发关键字
     */
    private function isTriggerKeyword(string $content): bool
    {
        $content = mb_strtolower($content);
        foreach (self::TRIGGER_KEYWORDS as $keyword) {
            if (mb_strpos($content, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断是否@了机器人
     */
    private function isMentioned(array $message): bool
    {
        $atList = $message['at_list'] ?? [];
        if (is_string($atList)) {
            $atList = json_decode($atList, true) ?? [];
        }
        return !empty($atList);
    }

    /**
     * 统一发送文本到群
     */
    private function replyToRoom(string $roomId, string $content): void
    {
        try {
            $this->juhebot->sendText('R:' . $roomId, $content);
        } catch (\Throwable $e) {
            LogService::error([
                'tag'    => 'InvoiceSession',
                'message' => '发送群消息失败',
                'data'   => [
                    'room_id' => $roomId,
                    'error'  => $e->getMessage(),
                ],
            ]);
        }
    }
}
