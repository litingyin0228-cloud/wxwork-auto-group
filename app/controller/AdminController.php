<?php
declare(strict_types=1);

namespace app\controller;

use think\facade\Db;
use think\Request;

/**
 * 管理后台：查看自动建群日志
 * 路由：GET /admin/group-logs
 */
class AdminController
{
    public function groupLogs(Request $request)
    {
        $page  = (int)$request->get('page', 1);
        $limit = 20;

        try {
            $total = Db::table('wxwork_group_chat_log')->count();
            $list  = Db::table('wxwork_group_chat_log')
                ->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            return json([
                'code'  => 0,
                'msg'   => 'ok',
                'data'  => [
                    'total' => $total,
                    'page'  => $page,
                    'limit' => $limit,
                    'list'  => $list,
                ],
            ]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 健康检查接口
     * GET /admin/health
     */
    public function health()
    {
        return json([
            'status' => 'ok',
            'time'   => date('Y-m-d H:i:s'),
        ]);
    }
}
