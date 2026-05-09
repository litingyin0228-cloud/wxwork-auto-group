<?php
declare(strict_types=1);

namespace app\job;

use app\model\InvoiceSession;
use app\model\InvoiceMessage;
use app\service\JuhebotService;
use app\service\LogService;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;

/**
 * 开票队列任务
 *
 * 队列驱动对话流程：用户发送结构化信息 → Bot确认摘要 → 用户确认 → 开票。
 * 通过 session.next_action 持久化状态，保证流程不中断、数据不窜。
 *
 * 流程：
 *   用户发送 → [收到，请稍等] → [解析信息 + 发确认摘要] → [等待用户确认]
 *   用户回复确认 → [提交开票申请] → [等待结果] → [通知用户]
 */
class InvoiceJob
{
    private JuhebotService $juhebot;

    private const ACTION_METHODS = [
        InvoiceSession::ACTION_SEND_ACKNOWLEDGING  => 'queueActionSendAck',
        InvoiceSession::ACTION_PARSE_AND_CONFIRM   => 'queueActionParseConfirm',
        InvoiceSession::ACTION_SUBMIT_INVOICE       => 'queueActionSubmitInvoice',
        InvoiceSession::ACTION_WAIT_RESULT          => 'queueActionWaitResult',
        InvoiceSession::ACTION_NOTIFY_RESULT        => 'queueActionNotifyResult',
    ];

    private const USER_TIMEOUT_SECONDS = 600; // 10分钟无用户响应自动取消

    public function __construct()
    {
        $this->juhebot = new JuhebotService();
    }

    /**
     * 队列入口
     *
     * @param Job   $job  任务实例
     * @param array $data ['session_id' => int]
     */
    public function fire(Job $job, array $data): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);

        if ($sessionId <= 0) {
            $this->logError('无效的 session_id', $data);
            $job->delete();
            return;
        }

        $session = InvoiceSession::find($sessionId);

        if ($session === null) {
            $this->logError('会话不存在', ['session_id' => $sessionId]);
            $job->delete();
            return;
        }

        if ($session->next_action === null || $session->next_action === '') {
            $this->logInfo('会话 next_action 为空，流程已结束', [
                'session_id' => $sessionId,
                'status'    => $session->status,
            ]);
            $job->delete();
            return;
        }

        $action = $session->next_action;

        if (!$this->checkUserTimeout($session)) {
            $this->logInfo('用户超时未回复，自动取消会话', [
                'session_id' => $sessionId,
                'next_action' => $action,
            ]);
            $this->sendText($session, "您超过10分钟未回复，开票请求已自动取消，有需要请重新发起。");
            $session->markCancelled();
            $job->delete();
            return;
        }

        $method = self::ACTION_METHODS[$action] ?? null;

        if ($method === null || !method_exists($this, $method)) {
            $this->logError('未知的 next_action', [
                'session_id'  => $sessionId,
                'next_action' => $action,
            ]);
            $session->markError("未知流程步骤：{$action}");
            $job->delete();
            return;
        }

        try {
            $this->{$method}($session, $job);
        } catch (\Throwable $e) {
            $this->handleException($session, $e, $job);
        }
    }

    /**
     * 检测是否已认证
     *
     * @param InvoiceSession $session
     * @return bool
     */
    private function checkCompanyCertified(InvoiceSession $session): bool {
        $org_id = $session->org_id;
        if ($org_id === null || $org_id === '') {
            return false;
        }
        $org = Db::table('tax_org')->where('tax_id', $org_id)->find();
        if ($org === null) {
            return false;
        }
        return $org['is_auth'] && $org['is_person_auth'];
    }

    /**
     * 检查是否VIP
     *
     * @param InvoiceSession $session
     * @return bool
     */
    private function checkCompanyVip(InvoiceSession $session): bool {
        $org_id = $session->org_id;
        if ($org_id === null || $org_id === '') {
            return false;
        }
        $org_vip = Db::table('tax_org_invoice_vip')->where('tax_id', $org_id)
        ->where("expired_time", ">", date("Y-m-d H:i:s"))
        ->find();
        if (empty($org_vip)) {
            return false;
        }
        return true;
    }

    /**
     * 检查用户是否超时未回复
     *
     * @param InvoiceSession $session
     * @return bool true=未超时（继续执行），false=已超时（需取消）
     */
    private function checkUserTimeout(InvoiceSession $session): bool
    {
        $lastMsgId = $session->latest_msg_id;
        if ($lastMsgId === null || $lastMsgId === '') {
            return true;
        }

        $lastMsg = InvoiceMessage::where('msg_id', $lastMsgId)->find();
        if ($lastMsg === null) {
            return true;
        }

        $lastTime = is_string($lastMsg->created_at) ? strtotime($lastMsg->created_at) : $lastMsg->created_at;
        if ($lastTime === false) {
            return true;
        }

        return (time() - $lastTime) < self::USER_TIMEOUT_SECONDS;
    }

    // ─── 对话步骤处理方法（由 next_action 驱动） ─────────────────────

    /**
     * 步骤 1：发送"收到，请稍等" 消息
     *
     * 由 InvoiceSessionService 在接收到用户开票请求后触发
     */
    protected function queueActionSendAck(InvoiceSession $session, Job $job): void
    {
        $this->logInfo('==> 步骤1：发送收到确认：'.$session->next_action, ['session_id' => $session->id]);
        // 需要检查当前企业是否已认证、是否已开通开票VIP如果检测不通过就结束流程
        $isCertified = $this->checkCompanyCertified($session);
        $isVip = $this->checkCompanyVip($session);
        if (!$isCertified || !$isVip) {            
            $session->markCancelled();
            //温馨提示：
    // 您的企业【贵州老瓦匠科技服务有限责任公司】暂未认证或未开通AI一键开票。建议您先完成认证并开通VIP，即可正常使用开票功能。
            $this->sendText($session, "您的企业【{$session->org_name}】未认证或未开通开票VIP，无法进行开票。");
            $job->delete();
            return;
        }
        $this->sendText($session, "收到{$session->org_name}！这就为您安排开票，我先确认下您的开票信息，您可持续补充，请稍等~");
        
        $session->next_action = InvoiceSession::ACTION_PARSE_AND_CONFIRM;
        $session->save();

        $this->dispatch($session->id, InvoiceSession::ACTION_PARSE_AND_CONFIRM);
 
        $job->delete();
    }

    /**
     * 步骤 2：解析用户输入，构建并发送确认摘要
     *
     * 由 queueActionSendAck 执行后触发（直接 dispatch）
     */
    protected function queueActionParseConfirm(InvoiceSession $session, Job $job): void
    {
        $stepData = is_array($session->step_data) ? $session->step_data : (array)$session->step_data;
        $rawContent = $stepData['raw_content'] ?? '';

        // 若已有企业名称，则说明是从步骤3退回重填的，继续补充而非覆盖
        $isRetry = !empty($session->company_name);

        $this->logInfo('==> 步骤2：解析信息并发送确认摘要', [
            'session_id' => $session->id,
            'is_retry'  => $isRetry,
        ]);

        $parsed = $this->parseInvoiceContent($rawContent, $session);

        // 退回重填时，合并新解析内容（不覆盖已有值） 
        $session->markAwaitConfirm($parsed, $isRetry);

        $summary = $session->buildConfirmSummary();
        $this->sendText($session, $summary);

        $this->logInfo('确认摘要已发送，进入等待用户确认状态', [
            'session_id' => $session->id,
        ]);

        // receive_confirm 是用户在群里回复"确认"，由 HTTP 接口的 routeByAction 驱动，不走队列
        $session->next_action = InvoiceSession::ACTION_RECEIVE_CONFIRM;
        $session->save();

        $job->delete();
    }

    /**
     * 步骤 3：提交开票申请
     *
     * 由 InvoiceSessionService 在用户回复"确认"后触发
     */
    protected function queueActionSubmitInvoice(InvoiceSession $session, Job $job): void
    {
        $this->logInfo('==> 步骤3：提交开票申请', ['session_id' => $session->id]);
        if ($session->company_name === '' || $session->tax_no === '' || $session->amount <= 0 || $session->items == "[]" || $session->items === "{}" || $session->items === null) {
            $this->sendText($session, "开票信息不完整，请补充完整后重新确认开票。");

            $session->next_action = InvoiceSession::ACTION_PARSE_AND_CONFIRM;
            $session->save();
            $this->dispatch($session->id, InvoiceSession::ACTION_PARSE_AND_CONFIRM);
            $job->delete(); 

            return;
        }
        $this->sendText($session, "好的，正在为您开票中，请稍等~");

        $session->markProcessing();

        try {
            $invoiceResult = $this->submitToInvoiceApi($session);

            if ($invoiceResult['success'] ?? false) {
                $session->step_data = array_merge(
                    is_array($session->step_data) ? $session->step_data : [],
                    [
                        'invoice_id'  => $invoiceResult['invoice_id'] ?? '',
                        'submit_time' => time(),
                    ]
                );
                $session->save();

                $this->dispatch($session->id, InvoiceSession::ACTION_WAIT_RESULT, [
                    'invoice_id' => $invoiceResult['invoice_id'] ?? '',
                ]);
            } else {
                throw new \RuntimeException($invoiceResult['message'] ?? '开票接口返回异常');
            }
        } catch (\Throwable $e) {
            $session->markError($e->getMessage());
            $session->markCancelled();
            $this->notifyError($session, $e->getMessage());
        }

        $job->delete();
    }

    /**
     * 步骤 4：等待开票结果（轮询）
     */
    protected function queueActionWaitResult(InvoiceSession $session, Job $job): void
    {
        $this->logInfo('==> 步骤4：等待开票结果', ['session_id' => $session->id]);

        $stepData = is_array($session->step_data) ? $session->step_data : (array)$session->step_data;
        $invoiceId = $stepData['invoice_id'] ?? '';

        try {
            $result = $this->queryInvoiceResult($invoiceId);

            if ($result['done'] ?? false) {
                $session->step_data = array_merge(
                    $stepData,
                    ['invoice_result' => $result]
                );
                $session->save();

                $this->dispatch($session->id, InvoiceSession::ACTION_NOTIFY_RESULT, [
                    'invoice_id' => $invoiceId,
                ]);
            } else {
                $session->retry_count = (int)$session->retry_count + 1;
                $session->save();

                if ($session->retry_count >= 10) {
                    throw new \RuntimeException('开票查询超时，请稍后重试');
                }

                $this->dispatch($session->id, InvoiceSession::ACTION_WAIT_RESULT, [
                    'invoice_id' => $invoiceId,
                ], 10);
            }
        } catch (\Throwable $e) {
            $session->markError($e->getMessage());
            $this->notifyError($session, $e->getMessage());
        }

        $job->delete();
    }

    /**
     * 步骤 5：通知用户开票结果
     */
    protected function queueActionNotifyResult(InvoiceSession $session, Job $job): void
    {
        $this->logInfo('==> 步骤5：通知开票结果', ['session_id' => $session->id]);

        $stepData = is_array($session->step_data) ? $session->step_data : (array)$session->step_data;
        $result = $stepData['invoice_result'] ?? [];

        if ($result['success'] ?? false) {
            $invoiceFile = $result['file_url'] ?? 'https://qxy-oss-invoice.oss-cn-beijing.aliyuncs.com/einv/2026/01/b39ea/89388/9f497/b92ce/12278/f38a389/26522000000080275096.pdf';
            $invoiceNo   = $result['invoice_no'] ?? '';

            $session->markCompleted($invoiceFile);

            $this->sendText($session, "发票已经开好，发票号码：{$invoiceNo}");
            if ($invoiceFile !== '') {
                $this->sendInvoiceFile($session, $invoiceFile);
            }
        } else {
            $msg = $result['message'] ?? '开票失败，请稍后重试或联系客服';
            $session->markError($msg);
            $this->notifyError($session, $msg);
        }

        $job->delete();
    }

    // ─── 对外投递入口 ────────────────────────────────────────────

    /**
     * 驱动会话向前一步
     *
     * 由 InvoiceSessionService 调用，读取 session.next_action 决定下一步
     *
     * @param int $sessionId 会话ID
     */
    public static function drive(int $sessionId): void
    {
        Queue::push(static::class, [
            'session_id' => $sessionId,
        ], 'invoice');
    }

    // ─── 信息解析 ───────────────────────────────────────────────

    /**
     * 从用户原始消息中解析开票信息
     *
     * 支持格式：
     *   开票\n公司：xxx\n税号：xxx\n项目：xxx\n金额：xxx
     *   开票 公司：xxx 税号：xxx 项目：xxx 金额：xxx
     *
     * @param string         $rawContent 原始消息内容
     * @param InvoiceSession $session    当前会话
     * @return array 解析后的开票数据
     */
    protected function parseInvoiceContent(string $rawContent, InvoiceSession $session): array
    {
        $data = [
            'company_name'      => '',
            'is_natural_person'=> 0,
            'tax_no'           => '',
            'invoice_type'     => InvoiceSession::INVOICE_TYPE_ELECTRONIC,
            'invoice_type_label'=> InvoiceSession::LABEL_NORMAL,
            'amount'           => 0.0,
            'items'            => [],
            'raw_content'      => $rawContent,
        ];

        // 编码归一化：GBK/GB2312 → UTF-8 
        // $rawContent = $this->normalizeEncoding($rawContent);

        // 归一化：换行、空格统一处理
        $text = trim(preg_replace('/[\r\n]+/', ' ', $rawContent));
        $text = str_replace(["\r", "\n", '：', ':', '，', ',', '。', '.', '、', '；', ';'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = mb_strtolower($text);

        // ─── 提取公司名称 ─────────────────────────────────────────
        // 支持：公司：xxx / 抬头：xxx / 企业名称：xxx / 企业：xxx
        if (preg_match('/(?:公司|抬头|企业名称|企业|名称)[：:\s]*([^\s\d]{2,50})/u', $text, $m)) {
            $data['company_name'] = trim($m[1]);
        }  elseif (preg_match('/(?:公司|抬头|企业名称|企业|名称) ?([^\s\d]{2,50})/u', $text, $m)) {
            $data['company_name'] = trim($m[1]);
        }

        // ─── 提取是否为自然人 ─────────────────────────────────────
        // 支持：自然人：是/否  个人：是/否  自然人：是  个人：是
        if (preg_match('/(?:自然人|个人)[：:\s]*(是|否|yes|no|1|0)/', $text, $m)) {
            $val = mb_strtolower($m[1]);
            $data['is_natural_person'] = in_array($val, ['是', 'yes', '1']) ? 1 : 0;
        }

        // ─── 提取税号 ─────────────────────────────────────────────
        // 15/17/18/20位数字字母组合
        if (preg_match('/(?:税号|营业执照编号|编号|营业执照)[：:\s]*([0-9a-z]{15,24})/i', $text, $m)) {
            $data['tax_no'] = $m[1];
        } elseif (preg_match('/(?:税号|营业执照编号|编号|营业执照) ?([0-9a-z]{15,24})/i', $text, $m)) {
            $data['tax_no'] = $m[1];
        } elseif (preg_match('/[0-9a-z]{15,24}/i', $text, $m)) {
            // 没有前缀时，如果文本中有18位码，也当作税号
            $data['tax_no'] = $m[0];
        }
        $data['tax_no'] = strtoupper($data['tax_no']);

        // ─── 提取票种类型 ─────────────────────────────────────────
        // 支持：普票 / 专票 / 纸质 / 电子
        if (preg_match('/(?:票种|类型|普通|专用|电子|纸质|普票|专票)[：:\s]*(普票|专票|普通|专用|电子|纸质)/', $text, $m)) {
            $type = mb_strtolower($m[1]);
            if (in_array($type, ['专票', '专用'])) {
                $data['invoice_type'] = InvoiceSession::INVOICE_TYPE_PAPER;
                $data['invoice_type_label'] = InvoiceSession::LABEL_SPECIAL;
            } else {
                $data['invoice_type'] = InvoiceSession::INVOICE_TYPE_ELECTRONIC;
                $data['invoice_type_label'] = InvoiceSession::LABEL_NORMAL;
            }
        }

        // ─── 提取金额 ─────────────────────────────────────────────
        // 支持：金额：xxx / 钱：xxx / xxx元 / xxx圆
        $amountFound = false;
        if (preg_match('/(?:金额|开票金额|总金额)[：:\s]*(\d+(?:\.\d{1,2})?)/', $text, $m)) {
            $data['amount'] = round((float)$m[1], 2);
            $amountFound = true;
        } elseif (preg_match('/(\d+(?:\.\d{1,2})?)\s*(?:元|圆|¥|\$)/u', $text, $m)) {
            $data['amount'] = round((float)$m[1], 2);
            $amountFound = true;
        } elseif (preg_match('/(?:金额|开票金额|总金额) ?(\d+(?:\.\d{1,2})?)/', $text, $m)) {
            $data['amount'] = round((float)$m[1], 2);
            $amountFound = true;
        }

        // 支持：项目：xxx / 项目 xxx / 服务项目：xxx
        // 允许关键词后跟冒号+空格或纯空格
        if (preg_match_all(
            '/(?:项目|服务项目|服务名称|服务)[：:\s]*([^\n]{1,50})/ui',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            $idx = 0;
            foreach ($matches as $m) {
                $name = trim($m[1]);
                if ($name === '' || is_numeric($name)) {
                    continue;
                }
                $amount = $amountFound ? $data['amount'] : 0.0;
                $data['items'][] = [
                    'name'   => $name,
                    'qty'    => '1.0',
                    'unit'   => '项',
                    'price'  => (string)$amount,
                    'amount' => (string)$amount,
                ];
                $idx++;
            }
        }

        // 如果提取到了项目但没有金额，尝试用项目后的数字作为金额
        if (empty($data['items']) && preg_match('/(?:项目|服务项目|服务名称|服务)[：:\s]*([^\s]{1,50})[\s]+(\d+(?:\.\d{1,2})?)/u', $text, $m)) {
            $data['amount'] = round((float)$m[2], 2);
            $data['items'][] = [
                'name'   => trim($m[1]),
                'qty'    => '1.0',
                'unit'   => '项',
                'price'  => $m[2],
                'amount' => $m[2],
            ];
        }

        // 如果没有任何项目，但有金额，则用金额创建默认项目
        if (empty($data['items']) && $data['amount'] > 0) {
            $data['items'][] = [
                'name'   => '服务费',
                'qty'    => '1.0',
                'unit'   => '项',
                'price'  => (string)$data['amount'],
                'amount' => (string)$data['amount'],
            ];
        }

        // // ─── 用企业信息兜底 ───────────────────────────────────────
        // if ($session->org_name !== '' && $data['company_name'] === '') {
        //     $data['company_name'] = $session->org_name;
        // }

        $this->logInfo('parseInvoiceContent 解析结果', [
            'session_id' => $session->id,
            'raw_content' => mb_strlen($rawContent) > 200
                ? mb_substr($rawContent, 0, 200) . '...(truncated)'
                : $rawContent,
            'parsed' => $data,
        ]);
        return $data;
    }

    // ─── 内部辅助方法 ───────────────────────────────────────────

    /**
     * 投递下一个 Job
     */
    protected function dispatch(int $sessionId, string $nextAction, array $extraData = [], int $delay = 0): void
    {
        $data = array_merge($extraData, ['session_id' => $sessionId]);
        $session = InvoiceSession::find($sessionId);
        if ($session !== null) {
            $session->next_action = $nextAction;
            $saved = $session->save();
            if (!$saved) {
                $this->logError('dispatch: session 保存失败', ['session_id' => $sessionId, 'next_action' => $nextAction]);
                throw new \RuntimeException('session 保存失败，dispatch 中止');
            }
        }
        $result = $delay > 0 ? Queue::later($delay, static::class, $data, 'invoice') : Queue::push(static::class, $data, 'invoice');
        if ($result === false || $result === null) {
            $this->logError('dispatch: Queue 返回 false/null', ['session_id' => $sessionId, 'next_action' => $nextAction]);
            throw new \RuntimeException('队列投递失败，dispatch 中止');
        }
        $this->logInfo('dispatch 成功', ['session_id' => $sessionId, 'next_action' => $nextAction]);
    }

    /**
     * 发送文本消息到群
     */
    protected function sendText(InvoiceSession $session, string $content): void
    {
        try {
            $this->juhebot->sendText('R:' . $session->room_id, $content);
        } catch (\Throwable $e) {
            $this->logError('发送消息失败', [
                'session_id' => $session->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 发送发票文件到群
     */
    protected function sendInvoiceFile(InvoiceSession $session, string $invoiceFile): void
    {
        $roomId   = 'R:' . $session->room_id;
        $fileName = ($session->invoice_type === InvoiceSession::INVOICE_TYPE_ELECTRONIC)
            ? '电子发票.pdf'
            : '纸质发票.pdf';
        $this->logInfo('文件参数',[
            'roomId' => $roomId,
            'fileName' => $fileName
        ]);
        try {
            $cdnInfo   = $this->juhebot->getCdnInfo();
            $this->logInfo('invoiceFile',[
                'invoiceFile' => $invoiceFile
            ]);
            $uploadRes = $this->juhebot->c2cUploadForUrl($cdnInfo, $invoiceFile, 5);
            $this->logInfo('上传结果',[
                'uploadRes' => $uploadRes
            ]);
            $data      = $uploadRes['data'] ?? [];
           
            $this->juhebot->sendFile(
                $roomId,
                $data['file_id'] ?? '',
                $data['file_size'] ?? 0,
                $fileName,
                $data['aes_key'] ?? '',
                $data['file_md5'] ?? ''
            );
            $this->logInfo('发票文件已发送', ['session_id' => $session->id]);
        } catch (\Throwable $e) {
            $this->logError('发送发票文件失败', [
                'session_id' => $session->id,
                'error'     => $e->getMessage(),
            ]);
            $this->juhebot->sendText($roomId, "发票已开具，但文件发送失败，请联系客服索取。");
        }
    }

    /**
     * 通知错误信息到群
     */
    protected function notifyError(InvoiceSession $session, string $errorMsg): void
    {
        try {
            $this->juhebot->sendText(
                'R:' . $session->room_id,
                "开票处理出现异常：{$errorMsg}\n请登录小程序查看开票状态。"
            );
            $this->juhebot->sendWeApp('R:' . $session->room_id);
        } catch (\Throwable $e) {
            $this->logError('发送错误通知失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 调用开票接口
     *
     * TODO: 替换为真实开票 API
     *
     * @param InvoiceSession $session
     * @return array ['success' => bool, 'invoice_id' => string, 'message' => string]
     */
    protected function submitToInvoiceApi(InvoiceSession $session): array
    {
        $invoiceApiUrl = "https://shenbao.guiyangyuanqu.cn/api/open/invoice/flow-by-org-id";

        if (empty($invoiceApiUrl)) {
            $this->logError('INVOICE_API_URL 未配置');
            return [
                'success' => false,
                'invoice_id' => '',
                'message' => '开票接口未配置',
            ];
        }

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->post($invoiceApiUrl, [
                'json' => [
                    'session_id' => $session->id,
                    'phone'=>"18685193758"//TODO 手机号
                ],
            ]);

            $body = json_decode((string)$response->getBody(), true);

            $this->logInfo('开票接口响应', [
                'session_id' => $session->id,
                'response' => $body,
            ]);

            if (isset($body['code']) && $body['code'] == 200) {
                return [
                    'success' => true,
                    'invoice_id' => $body['data']['id'] ?? '',
                    'message' => $body['message'] ?? '开票申请已提交',
                ];
            }

            return [
                'success' => false,
                'invoice_id' => '',
                'message' => $body['message'] ?? '开票申请提交失败',
            ];
        } catch (\Exception $e) {
            $this->logError('开票接口调用异常', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'invoice_id' => '',
                'message' => '开票接口调用失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 查询开票结果
     *
     * TODO: 替换为真实结果查询
     *
     * @param int $invoiceId
     * @return array ['done' => bool, 'success' => bool, 'file_url' => string, 'invoice_no' => string, 'message' => string]
     */
    protected function queryInvoiceResult(int $invoiceId): array
    {
        // TODO: 调用真实结果查询接口
        // $invoiceApi = app(InvoiceApiService::class);
        // return $invoiceApi->query($invoiceId);
        $invoiceInfo = Db::name('tax_qdfp_invoce_result')->where('id', $invoiceId)->find();

        $this->logInfo('模拟查询开票结果', ['invoiceInfo' => $invoiceInfo]);

        return [
            'done'       => true,
            'success'    => true,
            'invoice_no' => $invoiceInfo['fphm'] ?? '',
            'file_url'   => $invoiceInfo['pdf_url'] ?? '',
            'message'    => '开票成功',
        ];
    }
 
    /**
     * 统一日志
     */
    protected function logInfo(string $message, array $data = []): void
    {
        LogService::info(['tag' => 'InvoiceJob', 'msg' => $message, 'data' => $data], 'job');
    }

    protected function logError(string $message, array $data = []): void
    {
        LogService::error(['tag' => 'InvoiceJob', 'msg' => $message, 'data' => $data], 'job');
    }

    /**
     * 异常统一处理
     */
    protected function handleException(InvoiceSession $session, \Throwable $e, Job $job): void
    {
        $this->logError('Job 执行异常', [
            'session_id' => $session->id,
            'error'     => $e->getMessage(),
            'trace'     => substr($e->getTraceAsString(), 0, 500),
        ]);

        $this->notifyError($session, $e->getMessage());

        if ($job->attempts() < 3) {
            $job->release(30);
        } else {
            $session->markError($e->getMessage());
            $job->delete();
        }
    }

    /**
     * 任务最终失败回调
     */
    public function failed(array $data): void
    {
        $sessionId = (int)($data['session_id'] ?? 0);

        $this->logError('开票任务最终失败（已达最大重试次数）', $data);

        if ($sessionId > 0) {
            $session = InvoiceSession::find($sessionId);
            if ($session !== null) {
                $session->markError('开票任务失败，已超过最大重试次数');
                $this->notifyError($session, '开票失败，请联系客服处理。');
            }
        }
    }

    /**
     * 编码归一化：GBK/GB2312 → UTF-8
     */
    protected function normalizeEncoding(string $str): string
    {
        $str = trim($str);
        if ($str === '') {
            return $str;
        }

        if (mb_check_encoding($str, 'UTF-8') && !preg_match('/[\x80-\xBF]/', $str)) {
            return $str;
        }

        $converted = @iconv('GBK', 'UTF-8//IGNORE', $str);
        if ($converted !== false && $converted !== $str) {
            return $converted;
        }

        $converted = @iconv('GB18030', 'UTF-8//IGNORE', $str);
        if ($converted !== false) {
            return $converted;
        }

        return $str;
    }
}
