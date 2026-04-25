<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\InvoiceSessionService;
use app\service\LogService;
use think\Request;

/**
 * 开票会话接口
 */
class InvoiceController extends BaseController
{
    private InvoiceSessionService $invoiceService;

    public function __construct()
    {
        $this->invoiceService = new InvoiceSessionService();
    }

    /**
     * 接收群消息回调，触发开票流程
     *
     * POST /invoice/message
     * Body: 同 wxworkMsgCallback 的消息格式
     */
    public function message(Request $request)
    {
        try {
            // $params = json_decode(file_get_contents('php://input'), true);
            $params = $request->param();
            if (empty($params)) {
                return json(['code' => 400, 'message' => '参数为空'], 400);
            }

            // 构造标准消息格式（兼容不同回调格式）
            $message = [
                'msg_id'       => time(),
                'room_id'      => $params['roomid'] ?? $params['room_id'] ?? '',
                'sender'       => $params['sender'] ?? '',
                'sender_name'  => $params['sender_name'] ?? '',
                'content'      => $params['content'] ?? '',
                'at_list'      => $params['at_list'] ?? [],
                'notify_type'  => $params['notify_type'] ?? 11010,
            ];
            $handled = $this->invoiceService->handleMessage($message);

            return json([
                'code'    => 0,
                'message' => $handled ? '消息已处理' : '消息未触发开票',
            ]);
        } catch (\Throwable $e) {
            LogService::error([
                'tag'    => 'InvoiceController',
                'message' => '处理开票消息异常',
                'data'   => [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
            return json(['code' => 500, 'message' => '服务器内部错误'], 500);
        }
    }

    /**
     * 查询会话状态
     *
     * GET /invoice/status?room_id=xxx
     */
    public function status(Request $request)
    {
        $roomId = $request->param('room_id', '');

        if ($roomId === '') {
            return $this->error('room_id 不能为空');
        }

        $session = \app\model\InvoiceSession::findActiveByRoom($roomId);

        if ($session === null) {
            return $this->success([
                'has_session' => false,
                'room_id'    => $roomId,
            ], '无激活中的会话');
        }

        return $this->success([
            'has_session'  => true,
            'session_id'   => $session->id,
            'status'       => $session->status,
            'status_text'  => $session->status_text,
            'step'         => $session->step,
            'invoice_type' => $session->invoice_type,
            'amount'       => $session->amount,
            'created_at'   => $session->created_at,
        ]);
    }

    /**
     * 强制取消会话
     *
     * POST /invoice/cancel
     */
    public function cancel(Request $request)
    {
        $roomId = $request->param('room_id', '');

        if ($roomId === '') {
            return $this->error('room_id 不能为空'); 
        }

        $session = \app\model\InvoiceSession::findActiveByRoom($roomId);

        if ($session === null) {
            return $this->error('无激活中的会话');
        }

        $session->markCancelled();

        return $this->success(['session_id' => $session->id], '会话已取消');
    }
}
