<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 用户标签操作日志模型（wxwork_label_log）
 *
 * 去重逻辑：打标签前先查 is_current=1 且 org_id 相同 的记录，
 * 若已存在且 vip_end_date 未变化，则跳过本次操作。
 */
class LabelLog extends Model
{
    protected $name = 'label_log';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    const CURRENT_NO  = 0; // 已失效
    const CURRENT_YES = 1; // 当前有效

    /**
     * 判断某用户的某 org_id + label_id 是否已有当前有效标签记录
     * （按单个 label_id 去重，多标签时互不影响）
     *
     * @param string $userId
     * @param string $orgId
     * @param string $labelId
     * @return bool
     */
    public static function hasCurrentLabel(string $userId, string $orgId, string $labelId): bool
    {
        return self::where('user_id', $userId)
            ->where('org_id', $orgId)
            ->where('label_id', $labelId)
            ->where('is_current', self::CURRENT_YES)
            ->where('is_success', 1)
            ->find() !== null;
    }

    /**
     * 获取某用户某 org_id 的当前有效记录
     *
     * @param string $userId
     * @param string $orgId
     * @return static|null
     */
    public static function getCurrentRecord(string $userId, string $orgId)
    {
        return self::where('user_id', $userId)
            ->where('org_id', $orgId)
            ->where('is_current', self::CURRENT_YES)
            ->where('is_success', 1)
            ->find();
    }

    /**
     * 将旧的有效记录标记为失效
     *
     * @param string $userId
     * @param string $orgId
     * @return int 影响行数
     */
    public static function expireOld(string $userId, string $orgId): int
    {
        return self::where('user_id', $userId)
            ->where('org_id', $orgId)
            ->where('is_current', self::CURRENT_YES)
            ->update(['is_current' => self::CURRENT_NO]);
    }

    /**
     * 记录一条标签操作（成功）
     *
     * @param string     $userId
     * @param string     $orgId
     * @param string     $labelId       本次写入的标签ID
     * @param string     $labelGroupid
     * @param int        $businessType
     * @param string|null $vipEndDate
     * @param int|null   $applyId
     * @param array      $allLabelIds   本次全部标签ID列表（用于批量记录）
     * @return static
     */
    public static function logSuccess(
        string $userId,
        string $orgId,
        string $labelId,
        string $labelGroupid,
        int $businessType = 1,
        ?string $vipEndDate = null,
        ?int $applyId = null,
        array $allLabelIds = []
    ) {
        self::expireOld($userId, $orgId);

        $labelIds = !empty($allLabelIds) ? json_encode($allLabelIds, JSON_UNESCAPED_UNICODE) : $labelId;

        return self::create([
            'user_id'       => $userId,
            'org_id'        => $orgId,
            'label_id'      => $labelId,
            'label_groupid' => $labelGroupid,
            'business_type' => $businessType,
            'vip_end_date'  => $vipEndDate,
            'apply_id'      => $applyId,
            'is_current'    => self::CURRENT_YES,
            'is_success'    => 1,
            'label_ids'     => $labelIds,
        ]);
    }

    /**
     * 记录一条标签操作（失败）
     *
     * @param string     $userId
     * @param string     $orgId
     * @param string     $labelId
     * @param string     $labelGroupid
     * @param int        $businessType
     * @param string     $errorMsg
     * @param string|null $vipEndDate
     * @param int|null   $applyId
     * @param array      $allLabelIds   本次全部标签ID列表
     * @param int        $retryCount    重试次数
     * @return static
     */
    public static function logFail(
        string $userId,
        string $orgId,
        string $labelId,
        string $labelGroupid,
        int $businessType,
        string $errorMsg,
        ?string $vipEndDate = null,
        ?int $applyId = null,
        array $allLabelIds = [],
        int $retryCount = 0
    ) {
        $labelIds = !empty($allLabelIds) ? json_encode($allLabelIds, JSON_UNESCAPED_UNICODE) : $labelId;

        return self::create([
            'user_id'       => $userId,
            'org_id'        => $orgId,
            'label_id'      => $labelId,
            'label_groupid' => $labelGroupid,
            'business_type' => $businessType,
            'vip_end_date'  => $vipEndDate,
            'apply_id'      => $applyId,
            'is_current'    => self::CURRENT_YES,
            'is_success'    => 0,
            'retry_count'   => $retryCount,
            'error_msg'     => $errorMsg,
            'label_ids'     => $labelIds,
        ]);
    }

    /**
     * 判断某用户某 org_id 是否已达到最大重试次数（3次）
     *
     * @param string $userId
     * @param string $orgId  
     * @param int    $maxRetries 最大重试次数，默认3
     * @return bool
     */
    public static function hasExceededMaxRetries(string $userId, string $orgId, int $maxRetries = 3): bool
    {
        $failCount = self::where('user_id', $userId)
            ->where('org_id', $orgId)
            ->where('is_current', self::CURRENT_YES)
            ->where('is_success', 0)
            ->count();

        return $failCount >= $maxRetries;
    }

    /**
     * 获取某用户某 org_id 当前失败记录的累计重试次数
     *
     * @param string $userId
     * @param string $orgId
     * @return int
     */
    public static function getRetryCount(string $userId, string $orgId): int
    {
        $total = self::where('user_id', $userId)
            ->where('org_id', $orgId)
            ->where('is_current', self::CURRENT_YES)
            ->where('is_success', 0)
            ->sum('retry_count');

        return (int)$total;
    }
}
