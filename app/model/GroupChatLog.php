<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 自动建群日志模型
 */
class GroupChatLog extends Model
{
    protected $name = 'group_chat_log';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'created_at';
    protected $updateTime = false;

    /**
     * 状态常量
     */
    const STATUS_SUCCESS = 1;  // 成功
    const STATUS_FAILED = 0;   // 失败

    /**
     * 状态文本映射
     */
    public static $statusText = [
        self::STATUS_SUCCESS => '成功',
        self::STATUS_FAILED => '失败',
    ];

    /**
     * 获取状态文本
     *
     * @param int $value
     * @param array $data
     * @return string
     */
    public function getStatusTextAttr($value, $data): string
    {
        $status = $data['status'] ?? self::STATUS_FAILED;
        return self::$statusText[$status] ?? '未知';
    }

    /**
     * 格式化错误信息
     *
     * @param string $value
     * @return string
     */
    public function getErrorMsgAttr($value): string
    {
        return $value ?? '';
    }

    /**
     * 搜索器：按外部用户ID查询
     *
     * @param $query
     * @param string $externalUserid
     * @return mixed
     */
    public function searchExternalUseridAttr($query, string $externalUserid)
    {
        return $query->where('external_userid', $externalUserid);
    }

    /**
     * 搜索器：按员工用户ID查询
     *
     * @param $query
     * @param string $staffUserid
     * @return mixed
     */
    public function searchStaffUseridAttr($query, string $staffUserid)
    {
        return $query->where('staff_userid', $staffUserid);
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
     * 搜索器：按客户名称查询
     *
     * @param $query
     * @param string $customerName
     * @return mixed
     */
    public function searchCustomerNameAttr($query, string $customerName)
    {
        return $query->whereLike('customer_name', '%' . $customerName . '%');
    }

    /**
     * 搜索器：按群聊ID查询
     *
     * @param $query
     * @param string $chatId
     * @return mixed
     */
    public function searchChatIdAttr($query, string $chatId)
    {
        return $query->where('chat_id', $chatId);
    }

    /**
     * 搜索器：按创建时间范围查询
     *
     * @param $query
     * @param array $range [start_date, end_date]
     * @return mixed
     */
    public function searchCreatedAtRangeAttr($query, array $range)
    {
        return $query->whereBetweenTime('created_at', $range[0], $range[1]);
    }

    /**
     * 获取待处理的建群记录
     *
     * @return array
     */
    public static function getPendingRecords(): array
    {
        return self::where('is_process', 0)
            ->order('created_at', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 标记为已处理
     *
     * @param int $id
     * @return bool
     */
    public static function markAsProcessed(int $id): bool
    {
        return self::where('id', $id)->update(['is_process' => 1]) > 0;
    }
}
