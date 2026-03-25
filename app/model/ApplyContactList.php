<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 好友申请列表模型
 */
class ApplyContactList extends Model
{
    protected $name = 'apply_contact_list';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json = ['at_list', 'raw_data'];

    /**
     * 状态常量
     */
    const STATUS_PENDING = 0;  // 待处理
    const STATUS_AGREED = 1;   // 已同意
    const STATUS_REJECTED = 2;  // 已拒绝
    const STATUS_DELETED = 3;   // 已删除（flag=3时）

    /**
     * 状态文本映射
     */
    public static $statusText = [
        self::STATUS_PENDING  => '待处理',
        self::STATUS_AGREED  => '已同意',
        self::STATUS_REJECTED => '已拒绝',
        self::STATUS_DELETED => '已删除',
    ];

    /**
     * 获取状态文本
     *
     * @param int $status 状态码
     * @return string
     */
    public function getStatusTextAttr($value, $data): string
    {
        $status = $data['status'] ?? self::STATUS_PENDING;
        return self::$statusText[$status] ?? '未知';
    }

    /**
     * 格式化发送时间
     *
     * @param int $value 时间戳
     * @return string
     */
    public function getSendTimeTextAttr($value, $data): string
    {
        $timestamp = $data['send_time'] ?? 0;
        if (empty($timestamp)) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 格式化引用ID（referid）
     *
     * @param string $value
     * @return string
     */
    public function getReferIdAttr($value): string
    {
        return $value ?? '0';
    }

    /**
     * 格式化房间ID（roomid）
     *
     * @param string $value
     * @return string
     */
    public function getRoomIdAttr($value): string
    {
        return $value ?? '0';
    }

    /**
     * 格式化发送标志（send_flag）
     *
     * @param int $value
     * @return int
     */
    public function getSendFlagAttr($value): int
    {
        return (int)($value ?? 1);
    }

    /**
     * 搜索器：按GUID查询
     *
     * @param $query
     * @param string $guid
     * @return mixed
     */
    public function searchGuidAttr($query, string $guid)
    {
        return $query->where('guid', $guid);
    }

    /**
     * 搜索器：按状态查询
     *
     * @param $query
     * @param int $status
     * @return mixed
     */
    public function searchStatusAttr($query, int $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 搜索器：按发送者查询
     *
     * @param $query
     * @param string $sender
     * @return mixed
     */
    public function searchSenderAttr($query, string $sender)
    {
        return $query->where('sender', $sender);
    }

    /**
     * 搜索器：按接收者查询
     *
     * @param $query
     * @param string $receiver
     * @return mixed
     */
    public function searchReceiverAttr($query, string $receiver)
    {
        return $query->where('receiver', $receiver);
    }

    /**
     * 搜索器：按房间ID查询
     *
     * @param $query
     * @param string $roomId
     * @return mixed
     */
    public function searchRoomIdAttr($query, string $roomId)
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * 搜索器：按发送时间范围查询
     *
     * @param $query
     * @param array $range [start_timestamp, end_timestamp]
     * @return mixed
     */
    public function searchSendTimeRangeAttr($query, array $range)
    {
        return $query->whereBetween('send_time', $range);
    }

    /**
     * 获取待处理的申请列表
     *
     * @param string $guid
     * @param int $limit
     * @return array
     */
    public static function getPendingList(string $guid, int $limit = 20): array
    {
        return self::where('guid', $guid)
            ->where('status', self::STATUS_PENDING)
            ->order('send_time', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 标记申请为已处理
     *
     * @param int $id
     * @param int $status
     * @return bool
     */
    public static function markAsProcessed(int $id, int $status): bool
    {
        return self::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    /**
     * 标记申请为已同意
     *
     * @param int $id
     * @return bool
     */
    public static function markAsAgreed(int $id): bool
    {
        return self::markAsProcessed($id, self::STATUS_AGREED);
    }

    /**
     * 标记申请为已拒绝
     *
     * @param int $id
     * @return bool
     */
    public static function markAsRejected(int $id): bool
    {
        return self::markAsProcessed($id, self::STATUS_REJECTED);
    }

    /**
     * 标记申请为已删除
     *
     * @param int $id
     * @return bool
     */
    public static function markAsDeleted(int $id): bool
    {
        return self::markAsProcessed($id, self::STATUS_DELETED);
    }

    /**
     * 批量保存申请数据
     *
     * @param array $list 申请列表
     * @return bool
     */
    public static function saveApplyList(array $list): bool
    {
        if (empty($list)) {
            return true;
        }

        $data = [];
        foreach ($list as $item) {
            $data[] = [
                'seq'            => $item['seq'] ?? '',
                'msg_id'         => $item['id'] ?? '',
                'appinfo'        => $item['appinfo'] ?? '',
                'sender'         => $item['sender'] ?? '',
                'receiver'       => $item['receiver'] ?? '',
                'room_id'        => $item['roomid'] ?? '0',
                'send_time'      => $item['sendtime'] ?? 0,
                'sender_name'    => $item['sender_name'] ?? '',
                'content_type'   => $item['content_type'] ?? 0,
                'refer_id'      => $item['referid'] ?? '0',
                'flag'          => $item['flag'] ?? 0,
                'content'       => $item['content'] ?? '',
                'at_list'       => json_encode($item['at_list'] ?? []),
                'quote_content' => $item['quote_content'] ?? '',
                'quote_appinfo' => $item['quote_appinfo'] ?? '',
                'send_flag'     => $item['send_flag'] ?? 1,
                'msg_type'      => $item['msg_type'] ?? 0,
                'raw_data'      => json_encode($item),
                'status'        => self::STATUS_PENDING,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ];
        }

        return self::insertAll($data) !== false;
    }
}
