<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AutoGroupService;
use app\service\WxWorkCrypto;
use app\service\WxWorkService;
use think\facade\Log;
use think\Request;
use think\Response;

/**
 * 企业微信回调控制器
 *
 * 路由：
 *   GET  /wxwork/callback  —— 企业微信 URL 接入验证
 *   POST /wxwork/callback  —— 接收事件推送
 */
class WxWorkCallbackController
{
    private WxWorkCrypto     $crypto;
    private AutoGroupService $autoGroup;

    public function __construct()
    {
        $cfg = config('wxwork');

        $this->crypto = new WxWorkCrypto(
            $cfg['callback_token'],
            $cfg['callback_aes_key'],
            $cfg['corp_id']
        );

        $wxWorkService   = new WxWorkService();
        $this->autoGroup = new AutoGroupService($wxWorkService);
    }

    /**
     * URL 接入验证（GET 请求）
     */
    public function verify(Request $request): Response
    {
        try {
            // 兼容两种参数名：msg_signature 或 signature
            $msgSig   = $request->get('msg_signature', $request->get('signature', ''));
            $ts       = $request->get('timestamp', '');
            $nonce    = $request->get('nonce', '');
            $echoStr  = $request->get('echostr', '');

            Log::info('[Callback] URL 验证参数', [
                'msg_signature' => $msgSig,
                'timestamp'     => $ts,
                'nonce'         => $nonce,
                'echostr'       => $echoStr,
                'all_params'    => $request->get(),
            ]);

            $plainText = $this->crypto->verifyUrl($msgSig, $ts, $nonce, $echoStr);

            return response($plainText, 200, ['Content-Type' => 'text/plain']);
        } catch (\Throwable $e) {
            Log::error('[Callback] URL 验证失败: ' . $e->getMessage());
            return response('error', 403, ['Content-Type' => 'text/plain']);
        }
    }

    /**
     * 接收企业微信事件推送（POST 请求）
     */
    public function receive(Request $request): Response
    {
        $msgSig  = $request->get('msg_signature', '');
        $ts      = $request->get('timestamp', '');
        $nonce   = $request->get('nonce', '');
        $rawBody = $request->getContent();

        // 先响应企业微信（避免超时重试）
        // 业务逻辑在响应之前同步执行（也可改为异步队列）
        try {
            $event = $this->crypto->decryptMessage($msgSig, $ts, $nonce, $rawBody);
            $this->dispatchEvent($event);
        } catch (\Throwable $e) {
            Log::error('[Callback] 事件处理异常: ' . $e->getMessage(), [
                'body' => $rawBody,
            ]);
        }

        return response('', 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * 事件分发：根据 Event + ChangeType 路由到对应处理器
     */
    private function dispatchEvent(array $event): void
    {
        $eventType  = $event['Event']      ?? '';
        $changeType = $event['ChangeType'] ?? '';

        Log::info('[Callback] 收到事件', [
            'event'       => $eventType,
            'change_type' => $changeType,
        ]);

        // 新增企业客户事件
        if ($eventType === 'change_external_contact' && $changeType === 'add_external_contact') {
            $result = $this->autoGroup->handleNewCustomer($event);
            Log::info('[Callback] 建群结果', $result);
            return;
        }

        // 可在此扩展更多事件处理
        // if ($eventType === 'change_external_contact' && $changeType === 'del_external_contact') { ... }
    }
}
