<?php
namespace app\controller;

use app\BaseController;
use app\model\ApplyContactList;
use app\model\GroupChatLog;
use app\model\LabelList;
use app\service\JuhebotService;
use app\service\LogService;
use app\service\MessageCallbackService;
use app\service\WxWorkService;
use Fukuball\Jieba\Finalseg;
use Fukuball\Jieba\Jieba;
use think\App;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Event;
use think\Request;

class Api extends BaseController
{
    public function testJuheApi(Request $request){
        $cacheKey      = 'processed_room_ids';
        $totalKey      = 'processed_room_total';
        $processedIds = Cache::get($cacheKey) ?? [];
        $totalCount    = (int)Cache::get($totalKey, 0);

        // 每次最多处理 30 个群
        $limit     = rand(10, 20);
        $dbRoomIds = Db::table("wxwork_contact_room")
            ->whereRaw($processedIds ? "room_id NOT IN ('" . implode("','", $processedIds) . "')" : "1=1")
            ->limit($limit)
            ->column("room_id");

        if (empty($dbRoomIds)) {
            return $this->success([
                'total_processed' => $totalCount,
                'processed'       => 0,
                'new_processed'   => 0,
            ], '所有群已处理完成，无需重复操作');
        }

        $juhebot      = new JuhebotService();
        $newProcessed = 0;
        $failedList   = [];

        foreach ($dbRoomIds as $roomId) {
            try {
                $juhebot->modifyRoomAdminFlag($roomId, true, true);

                // 每处理完成一个，立即写入缓存，防止重复操作
                $processedIds[] = $roomId;
                Cache::set($cacheKey, $processedIds, 0);
                Cache::set($totalKey, ++$totalCount, 0);
                $newProcessed++;

                LogService::info([
                    'tag'     => 'RoomAdminFlag',
                    'message' => '群管理标志设置成功',
                    'data'    => ['room_id' => $roomId, 'total' => $totalCount],
                ]);
            } catch (\Throwable $e) {
                $failedList[] = $roomId;
                LogService::warning([
                    'tag'     => 'RoomAdminFlag',
                    'message' => '群管理标志设置失败，已跳过',
                    'data'    => ['room_id' => $roomId, 'error' => $e->getMessage()],
                ]);
            }
        }

        return $this->success([
            'total_processed' => $totalCount,
            'new_processed'  => $newProcessed,
            'failed'          => $failedList,
        ], '群管理标志批量修改完成');
    }

    /**
     * 同步标签列表
     *
     * GET /api/syncLabel?sync_type=2&seq=&limit=100&max=0
     */
    public function syncLabel(Request $request)
    {
        $syncType = (int)$request->get('sync_type', 2);
        if (!in_array($syncType, [1, 2], true)) {
            return $this->error('sync_type 必须是 1（企业标签）或 2（个人标签）');
        }

        $seq   = (string)$request->get('seq', '');
        $limit = (int)$request->get('limit', 100);
        $max   = (int)$request->get('max', 0);

        if ($limit <= 0) {
            $limit = 100;
        }

        $juhebot = new JuhebotService();
        $totalInserted = 0;
        $totalUpdated  = 0;
        $totalHandled  = 0;
        $lastSeq       = '';

        while (true) {
            if ($max > 0 && $totalHandled >= $max) {
                break;
            }

            $res = $juhebot->syncLabelList($seq, $syncType);

            if (empty($res) || empty($res['data'])) {
                break;
            }

            $labelList = $res['data']['label_list'] ?? [];
            $lastSeq   = (string)($res['data']['last_seq'] ?? '');

            if (empty($labelList)) {
                break;
            }

            $totalHandled += count($labelList);

            foreach ($labelList as $item) {
                $id = (string)($item['id'] ?? $item['label_id'] ?? '');
                if ($id === '') {
                    continue;
                }

                if (LabelList::existsById($id)) {
                    try {
                        LabelList::updateById($id, LabelList::diffLabelUpdate($item));
                        $totalUpdated++;
                    } catch (\Throwable $e) {
                        LogService::warning([
                            'tag'     => 'ApiSyncLabel',
                            'message' => '更新标签失败，已忽略',
                            'data'    => ['id' => $id, 'error' => $e->getMessage()],
                        ]);
                    }
                } else {
                    try {
                        LabelList::insert(LabelList::mapLabelToRow($item, $syncType));
                        $totalInserted++;
                    } catch (\Throwable $e) {
                        LogService::warning([
                            'tag'     => 'ApiSyncLabel',
                            'message' => '写入标签失败，已忽略',
                            'data'    => ['id' => $id, 'error' => $e->getMessage()],
                        ]);
                    }
                }
            }

            $seq = $lastSeq;
        }

        return $this->success([
            'sync_type'      => $syncType,
            'total_handled'  => $totalHandled,
            'total_inserted' => $totalInserted,
            'total_updated'   => $totalUpdated,
            'last_seq'        => $lastSeq,
        ], '标签同步完成');
    }
}
