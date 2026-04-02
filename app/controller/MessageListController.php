<?php
declare(strict_types=1);

namespace app\controller;
 
use app\model\JuhebotMessageCallback;
use think\facade\Db;
use think\Request;

/**
 * 消息列表管理
 */
class MessageListController
{
    /**
     * 消息列表页面
     */
    public function index()
    {
        $file = dirname(__DIR__) . '/view/message_list.html';
        
        if (is_file($file)) {
            return response()->content(file_get_contents($file))->contentType('text/html');
        }
        return '<h1>页面不存在</h1>';
    }

    /**
     * 获取消息列表
     * GET /message/list
     */
    public function list(Request $request)
    {
        $page          = (int)$request->get('page', 1);
        $limit         = (int)$request->get('limit', 20);
        $keyword       = trim($request->get('keyword', ''));
        $guid          = trim($request->get('guid', ''));
        $roomId        = trim($request->get('room_id', ''));
        $sender        = trim($request->get('sender', ''));
        $isProcessed   = $request->get('is_processed', '');
        $contentType   = $request->get('content_type', '');

        try {
            $query = Db::table('wxwork_juhebot_message_callback');

            if ($keyword !== '') {
                $query->whereLike('content', '%' . $keyword . '%');
            }

            if ($guid !== '') {
                $query->where('guid', $guid);
            }

            if ($roomId !== '') {
                $query->where('room_id', $roomId);
            }

            if ($sender !== '') {
                $query->where('sender', $sender);
            }

            if ($isProcessed !== '') {
                $query->where('is_processed', (int)$isProcessed);
            }

            if ($contentType !== '') {
                $query->where('content_type', (int)$contentType);
            }

            $total = $query->count();
            $list  = $query->order('id', 'desc')
                ->page($page, $limit)
                ->select()
                ->toArray();

            foreach ($list as &$item) {
                $item['send_time_text']    = $item['send_time'] ? date('Y-m-d H:i:s', $item['send_time']) : '';
                $item['created_at_text']   = $item['created_at'] ?? '';
                $item['processed_at_text'] = $item['processed_at'] ? date('Y-m-d H:i:s', strtotime($item['processed_at'])) : '';
                $item['is_processed_text'] = $item['is_processed'] ? '已处理' : '未处理';
                $item['content_type_text'] = $this->getContentTypeText($item['content_type']);
                $item['at_list']           = is_string($item['at_list']) ? json_decode($item['at_list'], true) : ($item['at_list'] ?? []);
                $item['at_list_text']      = !empty($item['at_list']) ? implode(', ', $item['at_list']) : '';
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
     * 获取消息详情
     * GET /message/detail
     */
    public function detail(Request $request)
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $row = Db::table('wxwork_juhebot_message_callback')->where('id', $id)->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => '记录不存在']);
            }

            $row['send_time_text']    = $row['send_time'] ? date('Y-m-d H:i:s', $row['send_time']) : '';
            $row['created_at_text']   = $row['created_at'] ?? '';
            $row['processed_at_text'] = $row['processed_at'] ? date('Y-m-d H:i:s', strtotime($row['processed_at'])) : '';
            $row['is_processed_text'] = $row['is_processed'] ? '已处理' : '未处理';
            $row['content_type_text'] = $this->getContentTypeText($row['content_type']);
            $row['at_list']           = is_string($row['at_list']) ? json_decode($row['at_list'], true) : ($row['at_list'] ?? []);
            $row['raw_data']          = is_string($row['raw_data']) ? json_decode($row['raw_data'], true) : ($row['raw_data'] ?? []);

            return json(['code' => 0, 'msg' => 'ok', 'data' => $row]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 标记消息为已处理
     * POST /message/mark-processed
     */
    public function markProcessed(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {
            $row = Db::table('wxwork_juhebot_message_callback')->where('id', $id)->find();
            if (!$row) {
                return json(['code' => 404, 'msg' => '记录不存在']);
            }

            Db::table('wxwork_juhebot_message_callback')->where('id', $id)->update([
                'is_processed' => 1,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            return json(['code' => 0, 'msg' => '已标记为已处理']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 批量标记为已处理
     * POST /message/batch-mark-processed
     */
    public function batchMarkProcessed(Request $request)
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
            Db::table('wxwork_juhebot_message_callback')
                ->whereIn('id', $idArr)
                ->update([
                    'is_processed' => 1,
                    'processed_at' => date('Y-m-d H:i:s'),
                ]);

            return json(['code' => 0, 'msg' => '批量标记成功']);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取内容类型文本
     */
    private function getContentTypeText(int $type): string
    {
        $map = [
            0  => '文本消息',
            1  => '图片消息',
            3  => '语音消息',
            5  => '链接',
            6  => '小程序',
            7  => '文件',
            14 => '群@消息',
            43 => '视频',
            47 => '表情',
        ];
        return $map[$type] ?? '未知(' . $type . ')';
    }
}
