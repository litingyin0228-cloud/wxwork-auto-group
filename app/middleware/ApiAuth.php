<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\LogService;
use think\Request;

/**
 * API 简易 Token 鉴权中间件
 *
 * 校验请求头 X-Api-Token 是否与配置中的 API_TOKEN 一致。
 * 若未配置 API_TOKEN（为空），则跳过鉴权（开发环境兼容）。
 */
class ApiAuth
{
    public function handle(Request $request, \Closure $next)
    {
        $token = $request->header('X-Api-Token', '');
        $configuredToken = config('app.api_token', '');

        // 未配置 Token 时跳过校验，兼容本地开发
        if ($configuredToken === '') {
            return $next($request);
        }

        if ($token === '' || !hash_equals($configuredToken, $token)) {
            LogService::warning([
                'tag'    => 'ApiAuth',
                'message' => 'API 鉴权失败',
                'data'   => [
                    'ip'      => $request->ip(),
                    'path'    => $request->baseUrl(),
                    'token'   => $token !== '' ? substr($token, 0, 8) . '...' : '',
                ],
            ]);

            return json([
                'code'    => 401,
                'message' => '未授权，请检查 Token',
            ], 401);
        }

        return $next($request);
    }
}
