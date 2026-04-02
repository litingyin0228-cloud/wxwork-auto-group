<?php
declare(strict_types=1);

namespace app\controller;

use app\model\ApplyContactList;
use think\facade\Db;
use think\Request;

/**
 * 联系人列表管理
 */ 
class ContactListController
{
    /**
     * 联系人列表页面
     */
    public function index()
    {
        $file = dirname(__DIR__) . '/view/contact_list.html';
        
        if (is_file($file)) {
            return response()->content(file_get_contents($file))->contentType('text/html');
        }
        return '<h1>页面不存在</h1>';
    }

    /**
     * 获取联系人列表
     * GET /contact/list
     */
    public function list(Request $request)
    {
        $page    = (int)$request->get('page', 1);
        $limit   = (int)$request->get('limit', 20);
        $keyword = trim($request->get('keyword', ''));
        $status  = $request->get('status', '');
        $type    = $request->get('type', '');

        try {
            $query = Db::table('wxwork_apply_contact_list');

            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->whereLike('name', '%' . $keyword . '%')
                      ->whereOr('user_id', 'like', '%' . $keyword . '%')
                      ->whereOr('apply_reason', 'like', '%' . $keyword . '%');
                });
            }

            if ($status !== '') {
                $query->where('status', (int)$status);
            }

            if ($type !== '') {
                $query->where('type', (int)$type);
            }

            $total = $query->count();
            $list  = $query->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            foreach ($list as &$item) {
                $item['status_text'] = ApplyContactList::$statusText[$item['status']] ?? '未知';
                $item['sex_text']    = $item['sex'] == 1 ? '男' : ($item['sex'] == 2 ? '女' : '未知');
                $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', $item['create_time']) : '';
                $item['add_time_text']    = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
                $item['extend_info_remark_time_text'] = $item['extend_info_remark_time'] ? date('Y-m-d H:i:s', $item['extend_info_remark_time']) : '';
            }

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
     * 获取待处理联系人数量
     * GET /contact/pending-count
     */
    public function pendingCount()
    {
        try {
            $count = Db::table('wxwork_apply_contact_list')
                ->where('status', ApplyContactList::STATUS_PENDING)
                ->count();

            return json(['code' => 0, 'msg' => 'ok', 'data' => ['count' => $count]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 同意申请
     * POST /contact/agree
     */
    public function agree(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $row = Db::table('wxwork_apply_contact_list')->where('id', $id)->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => '记录不存在']);
            }

            Db::table('wxwork_apply_contact_list')->where('id', $id)->update([
                'status'     => ApplyContactList::STATUS_AGREED,
                'update_at' => date('Y-m-d H:i:s'),
            ]);

            return json(['code' => 0, 'msg' => '已同意']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 拒绝申请
     * POST /contact/reject
     */
    public function reject(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $row = Db::table('wxwork_apply_contact_list')->where('id', $id)->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => '记录不存在']);
            }

            Db::table('wxwork_apply_contact_list')->where('id', $id)->update([
                'status'     => ApplyContactList::STATUS_REJECTED,
                'update_at' => date('Y-m-d H:i:s'),
            ]);

            return json(['code' => 0, 'msg' => '已拒绝']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 批量同意
     * POST /contact/batch-agree
     */
    public function batchAgree(Request $request)
    {
        $ids = $request->post('ids', '');
        if ($ids === '') {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArr)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::table('wxwork_apply_contact_list')
                ->whereIn('id', $idArr)
                ->update([
                    'status'     => ApplyContactList::STATUS_AGREED,
                    'update_at' => date('Y-m-d H:i:s'),
                ]);

            return json(['code' => 0, 'msg' => '批量同意成功']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 批量拒绝
     * POST /contact/batch-reject
     */
    public function batchReject(Request $request)
    {
        $ids = $request->post('ids', '');
        if ($ids === '') {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (empty($idArr)) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            Db::table('wxwork_apply_contact_list')
                ->whereIn('id', $idArr)
                ->update([
                    'status'     => ApplyContactList::STATUS_REJECTED,
                    'update_at' => date('Y-m-d H:i:s'),
                ]);

            return json(['code' => 0, 'msg' => '批量拒绝成功']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }
}
