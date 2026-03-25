<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * Juhebot 消息回调模型
 */
class JuhebotMessageCallback extends Model
{
    protected $name = 'juhebot_message_callback';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $json = ['at_list', 'raw_data'];

    /**
     * 格式化发送时间
     *
     * @param int $value
     * @param array $data
     * @return string
     */
    public function getSendTimeTextAttr($value, $data): string
    {
        $timestamp = $data['sendtime'] ?? 0;
        if (empty($timestamp)) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 格式化房间ID
     *
     * @param string $value
     * @return string
     */
    public function getRoomidAttr($value): string
    {
        return $value ?? '0';
    }

    /**
     * 格式化引用ID
     *
     * @param string $value
     * @return string
     */
    public function getReferidAttr($value): string
    {
        return $value ?? '0';
    }

    /**
     * 格式化发送标志
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
     * 搜索器：按通知类型查询
     *
     * @param $query
     * @param int $notifyType
     * @return mixed
     */
    public function searchNotifyTypeAttr($query, int $notifyType)
    {
        return $query->where('notify_type', $notifyType);
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
     * @param string $roomid
     * @return mixed
     */
    public function searchRoomidAttr($query, string $roomid)
    {
        return $query->where('roomid', $roomid);
    }

    /**
     * 搜索器：按发送时间范围查询
     *
     * @param $query
     * @param array $range [start_timestamp, end_timestamp]
     * @return mixed
     */
    public function searchSendtimeRangeAttr($query, array $range)
    {
        return $query->whereBetween('sendtime', $range);
    }

    /**
     * 搜索器：按是否已处理查询
     *
     * @param $query
     * @param bool $isProcessed
     * @return mixed
     */
    public function searchIsProcessedAttr($query, bool $isProcessed)
    {
        return $query->where('is_processed', $isProcessed ? 1 : 0);
    }

    /**
     * 搜索器：按内容类型查询
     *
     * @param $query
     * @param int $contentType
     * @return mixed
     */
    public function searchContentTypeAttr($query, int $contentType)
    {
        return $query->where('content_type', $contentType);
    }

    /**
     * 搜索器：按消息类型查询
     *
     * @param $query
     * @param int $msgType
     * @return mixed
     */
    public function searchMsgTypeAttr($query, int $msgType)
    {
        return $query->where('msg_type', $msgType);
    }

    /**
     * 搜索器：按消息内容模糊查询
     *
     * @param $query
     * @param string $content
     * @return mixed
     */
    public function searchContentAttr($query, string $content)
    {
        return $query->whereLike('content', '%' . $content . '%');
    }

    /**
     * 获取待处理的消息
     *
     * @param int $limit
     * @return array
     */
    public static function getPendingMessages(int $limit = 100): array
    {
        return self::where('is_processed', 0)
            ->order('sendtime', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 获取指定GUID的待处理消息
     *
     * @param string $guid
     * @param int $limit
     * @return array
     */
    public static function getPendingMessagesByGuid(string $guid, int $limit = 100): array
    {
        return self::where('guid', $guid)
            ->where('is_processed', 0)
            ->order('sendtime', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 标记消息为已处理
     *
     * @param int $id
     * @return bool
     */
    public static function markAsProcessed(int $id): bool
    {
        return self::where('id', $id)->update([
            'is_processed' => 1,
            'processed_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    /**
     * 批量标记消息为已处理
     *
     * @param array $ids
     * @return int
     */
    public static function markBatchProcessed(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        return self::whereIn('id', $ids)->update([
            'is_processed' => 1,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 保存回调消息
     *
     * @param array $callbackData
     * @return bool
     */
    public static function saveCallback(array $callbackData): bool
    {
        $params = $callbackData['data'] ?? [];
        $guid = $callbackData['guid'] ?? '';
        $notifyType = $callbackData['notify_type'] ?? 0;

        $data = [
            'guid'          => $guid,
            'notify_type'   => $notifyType,
            'seq'           => $params['seq'] ?? '',
            'msg_id'        => $params['id'] ?? '',
            'appinfo'       => $params['appinfo'] ?? '',
            'sender'        => $params['sender'] ?? '',
            'receiver'      => $params['receiver'] ?? '',
            'roomid'        => $params['roomid'] ?? '0',
            'sendtime'      => $params['sendtime'] ?? 0,
            'sender_name'    => $params['sender_name'] ?? '',
            'content_type'   => $params['content_type'] ?? 0,
            'referid'       => $params['referid'] ?? '0',
            'flag'          => $params['flag'] ?? 0,
            'content'       => $params['content'] ?? '',
            'at_list'       => json_encode($params['at_list'] ?? []),
            'quote_content' => $params['quote_content'] ?? '',
            'quote_appinfo' => $params['quote_appinfo'] ?? '',
            'send_flag'     => $params['send_flag'] ?? 1,
            'msg_type'      => $params['msg_type'] ?? 0,
            'raw_data'      => json_encode($params),
            'is_processed'  => 0,
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        return self::insert($data) !== false;
    }

    /**
     * 统计待处理消息数量
     *
     * @param string $guid
     * @return int
     */
    public static function countPendingMessages(string $guid = ''): int
    {
        $query = self::where('is_processed', 0);
        if (!empty($guid)) {
            $query->where('guid', $guid);
        }
        return $query->count();
    }
}
