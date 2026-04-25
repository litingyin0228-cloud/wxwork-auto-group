<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 开票会话模型 
 *
 * 对应表：wxwork_invoice_session
 *
 * @property int         $id
 * @property string      $room_id
 * @property string      $user_id
 * @property string|null $org_id
 * @property string|null $org_name
 * @property string|null $company_name       发票抬头（企业名称）
 * @property int         $is_natural_person  是否自然人（0=否 1=是）
 * @property string|null $user_mobile
 * @property string|null $invoice_type       发票类型（electronic/paper）
 * @property string|null $invoice_type_label 票种类型文字（普票/专票）
 * @property string|null $title_type         抬头类型（personal/enterprise）
 * @property string|null $tax_no             税号
 * @property string|null $tax_addr           税局地址（专票）
 * @property string|null $tax_phone          税局电话（专票）
 * @property string|null $tax_bank           税局开户行（专票）
 * @property string|null $tax_account        税局账号（专票）
 * @property string|null $next_action        队列下一步动作（对应 ACTION_* 常量）
 * @property int         $retry_count        已重试次数
 * @property string|null $tax_rate 
 * @property float|null  $amount             开票金额
 * @property array|null $items              项目明细（JSON）
 * @property int         $status             会话状态
 * @property array|null $step_data
 * @property string|null $invoice_file
 * @property string|null $error_msg
 * @property int         $step               当前步骤
 * @property int         $confirm_round      当前确认轮次（>=1）
 * @property string|null $latest_msg_id
 * @property string|null $expires_at
 * @property string|null $completed_at
 * @property string      $created_at
 * @property string      $updated_at
 */
class InvoiceSession extends Model
{
    protected $name = 'invoice_session';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json = ['step_data', 'items'];
    protected $casts = [
        'step_data' => 'array',
        'items'     => 'array',
    ];

    // ─── 会话状态常量 ────────────────────────────────────────────
    const STATUS_PARSING       = 0;  // 信息解析中（队列Job正在识别）
    const STATUS_AWAIT_CONFIRM = 1;  // 等待用户确认
    const STATUS_PROCESSING    = 2;  // 开票处理中
    const STATUS_COMPLETED     = 3;  // 已完成
    const STATUS_CANCELLED     = 4;  // 已取消
    const STATUS_ERROR        = 99; // 异常

    public static $statusText = [
        self::STATUS_PARSING       => '信息解析中',
        self::STATUS_AWAIT_CONFIRM => '等待确认',
        self::STATUS_PROCESSING    => '开票处理中',
        self::STATUS_COMPLETED     => '已完成',
        self::STATUS_CANCELLED     => '已取消',
        self::STATUS_ERROR         => '异常',
    ];

    // ─── 步骤常量 ────────────────────────────────────────────────
    const STEP_RECEIVE   = 1;
    const STEP_CONFIRM   = 2;
    const STEP_INVOICING = 3;
    const STEP_SEND_FILE = 4;

    // ─── 发票类型 ────────────────────────────────────────────────
    const INVOICE_TYPE_ELECTRONIC = 'electronic';
    const INVOICE_TYPE_PAPER     = 'paper';

    public static $invoiceTypeText = [
        self::INVOICE_TYPE_ELECTRONIC => '电子发票',
        self::INVOICE_TYPE_PAPER      => '纸质发票',
    ];

    const LABEL_NORMAL  = '普票';
    const LABEL_SPECIAL = '专票';

    // ─── 队列下一步动作常量 ──────────────────────────────────────
    const ACTION_SEND_ACKNOWLEDGING = 'send_ack';
    const ACTION_PARSE_AND_CONFIRM  = 'parse_confirm';
    const ACTION_RECEIVE_CONFIRM    = 'receive_confirm';
    const ACTION_SUBMIT_INVOICE     = 'submit_invoice';
    const ACTION_WAIT_RESULT        = 'wait_result';
    const ACTION_NOTIFY_RESULT      = 'notify_result';

    public function messages()
    {
        return $this->hasMany(InvoiceMessage::class, 'session_id');
    }

    public function getStatusTextAttr(): string
    {
        return self::$statusText[$this->status] ?? '未知';
    }

    public function getInvoiceTypeTextAttr(): string
    {
        return self::$invoiceTypeText[$this->invoice_type] ?? $this->invoice_type ?? '';
    }

    /**
     * 根据 room_id 查找激活中的会话（未完成且未过期）
     */
    public static function findActiveByRoom(string $roomId): ?self
    {
        return self::where('room_id', $roomId)
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_ERROR])
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->whereOr('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->order('id', 'desc')
            ->find();
    }

    /**
     * 创建新会话
     */
    public static function createSession(array $data): self
    {
        $model = new self();
        $model->room_id     = $data['room_id'];
        $model->user_id     = $data['user_id'];
        $model->org_id      = $data['org_id'] ?? null;
        $model->org_name    = $data['org_name'] ?? null;
        $model->user_mobile = $data['user_mobile'] ?? null;
        $model->status      = self::STATUS_PARSING;
        $model->step          = self::STEP_RECEIVE;
        $model->confirm_round = 1;
        $model->step_data     = [];
        $model->next_action = self::ACTION_SEND_ACKNOWLEDGING;
        $model->expires_at  = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $model->save();
        return $model;
    }

    /**
     * 标记解析完成，进入等待确认状态
     */
    public function markAwaitConfirm(array $parsedData, bool $merge = false): bool
    {
        if ($merge) {
            // 补充流程：只覆盖非空字段，保留已有值
            if (!empty($parsedData['company_name'])) {
                $this->company_name = $parsedData['company_name'];
            }
            if (isset($parsedData['is_natural_person'])) {
                $this->is_natural_person = $parsedData['is_natural_person'];
            }
            if (!empty($parsedData['tax_no'])) {
                $this->tax_no = $parsedData['tax_no'];
            }
            if (!empty($parsedData['invoice_type'])) {
                $this->invoice_type = $parsedData['invoice_type'];
            }
            if (!empty($parsedData['invoice_type_label'])) {
                $this->invoice_type_label = $parsedData['invoice_type_label'];
            }
            if ((float)($parsedData['amount'] ?? 0) > 0) {
                $this->amount = (float)$parsedData['amount'];
            }
            if (!empty($parsedData['items'])) {
                $this->items = $parsedData['items'];
            }
            if (!empty($parsedData['tax_addr'])) {
                $this->tax_addr = $parsedData['tax_addr']; 
            }
            if (!empty($parsedData['tax_phone'])) {
                $this->tax_phone = $parsedData['tax_phone'];
            }
            if (!empty($parsedData['tax_bank'])) {
                $this->tax_bank = $parsedData['tax_bank'];
            }
            if (!empty($parsedData['tax_account'])) {
                $this->tax_account = $parsedData['tax_account'];
            }
        } else {
            // 常规首次解析：全量赋值
            $this->company_name       = $parsedData['company_name'] ?? null;
            $this->is_natural_person  = $parsedData['is_natural_person'] ?? 0;
            $this->tax_no             = $parsedData['tax_no'] ?? null;
            $this->invoice_type       = $parsedData['invoice_type'] ?? self::INVOICE_TYPE_ELECTRONIC;
            $this->invoice_type_label = $parsedData['invoice_type_label'] ?? self::LABEL_NORMAL;
            $this->amount             = $parsedData['amount'] ?? 0.0;
            $this->items              = $parsedData['items'] ?? [];
            $this->tax_addr           = $parsedData['tax_addr'] ?? null;
            $this->tax_phone          = $parsedData['tax_phone'] ?? null;
            $this->tax_bank           = $parsedData['tax_bank'] ?? null;
            $this->tax_account        = $parsedData['tax_account'] ?? null;
        }

        $this->status        = self::STATUS_AWAIT_CONFIRM;
        $this->step          = self::STEP_CONFIRM;
        $this->next_action   = self::ACTION_RECEIVE_CONFIRM;
        $this->confirm_round = 1;
        $this->step_data     = $parsedData;

        return $this->save();
    }

    public function markProcessing(): bool
    {
        $this->status      = self::STATUS_PROCESSING;
        $this->step        = self::STEP_INVOICING;
        $this->next_action = self::ACTION_WAIT_RESULT;
        return $this->save();
    }

    public function markCompleted(string $invoiceFile = ''): bool
    {
        $this->status       = self::STATUS_COMPLETED;
        $this->invoice_file = $invoiceFile;
        $this->step         = self::STEP_SEND_FILE;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->expires_at   = null;
        $this->next_action  = null;
        return $this->save();
    }

    public function markError(string $errorMsg): bool
    {
        $this->status     = self::STATUS_ERROR;
        $this->error_msg  = $errorMsg;
        $this->expires_at = null;
        $this->next_action = null;
        return $this->save();
    }

    public function markCancelled(): bool
    {
        $this->status      = self::STATUS_CANCELLED;
        $this->expires_at  = null;
        $this->next_action = null;
        return $this->save();
    }

    public function updateLatestMsgId(string $msgId): bool
    {
        $this->latest_msg_id = $msgId;
        return $this->save();
    }

    public function isMsgProcessed(string $msgId): bool
    {
        return $this->latest_msg_id === $msgId;
    }

    /**
     * 生成确认摘要文本
     */
    public function buildConfirmSummary(): string
    {
        $typeLabel = $this->invoice_type_label ?? ($this->invoice_type === self::INVOICE_TYPE_PAPER ? self::LABEL_SPECIAL : self::LABEL_NORMAL);
        $personText = (int)$this->is_natural_person === 1 ? '是' : '否';

        $lines = [
            "公司名称：" . ($this->company_name ?: '（未填写）'),
            "是否为自然人：" . $personText,
            "税号：" . ($this->tax_no ?: '（未填写）'),
            "票种类型：" . $typeLabel."(数电)",
            "服务项目：",
        ];

        $items = is_array($this->items) ? $this->items : [];
        if (!empty($items)) {
            foreach ($items as $i => $item) {
                $idx = $i + 1;
                $lines[] = "项目{$idx}：";
                $lines[] = "- 服务项目：" . ($item['name'] ?? '');
                $lines[] = "- 数量：" . ($item['qty'] ?? '1.0');
                $lines[] = "- 单位：" . ($item['unit'] ?? '项');
                $lines[] = "- 单价：" . number_format((float)($item['price'] ?? '0.0'), 2, '.', '');
                $lines[] = "开票金额：" . number_format((float)($item['amount'] ?? '0.0'), 2, '.', '');
            }
        } else {
            $lines[] = "开票金额：" . number_format((float)($this->amount ?? '0.0'), 2, '.', '');
        }

        $round = (int)($this->confirm_round ?? 1);
        $this->save();
        return "好的，请确认我收集到的信息是否准确（第 {$round} 轮确认）：\n" . implode("\n", $lines)
             . "\n\n如信息无误，请告知我为您开票；如有问题，请告诉我需要修改的内容。（比如：金额：1000，服务：软件咨询）";
    }
}
