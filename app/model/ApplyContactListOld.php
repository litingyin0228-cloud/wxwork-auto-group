<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 历史好友申请列表模型（wxwork_apply_contact_list_old）
 */
class ApplyContactListOld extends Model
{
    protected $name = 'apply_contact_list_old';

    protected $autoWriteTimestamp = false;

    protected $createTime = 'create_at';
    protected $updateTime = 'update_at';
 
    /**
     * 根据 user_id 判断记录是否存在
     */
    public static function existsByUserId(string $userId): bool
    {
        return self::where('user_id', $userId)->find() !== null;
    }

    /**
     * 将 syncContact 返回的单条数据映射为数据库字段
     */
    public static function mapContactToRow(array $item, string $now): array
    {
        $extendInfo = $item['extend_info'] ?? [];

        return [
            'seq'                      => (string)($item['seq'] ?? ''),
            'user_id'                  => (string)($item['user_id'] ?? ''),
            'name'                    => $item['name'] ?? '',
            'sex'                     => (int)($item['sex'] ?? 0),
            'avatar'                  => $item['avatar'] ?? '',
            'corp_id'                 => $item['corp_id'] ?? '',
            'unionid'                 => $item['unionid'] ?? '',
            'type'                    => (int)($item['type'] ?? 0),
            'flag'                    => (int)($item['flag'] ?? 0),
            'create_time'             => (int)($item['create_time'] ?? 0),
            'update_time'             => (int)($item['update_time'] ?? 0),
            'corp_name'               => $item['corp_name'] ?? '',
            'corp_short_name'         => $item['corp_short_name'] ?? '',
            'source_type'             => $item['source_type'] ?? '',
            'source_user_id'          => $item['source_user_id'] ?? '',
            'source_room_id'          => $item['source_room_id'] ?? '',
            'apply_reason'            => $item['apply_reason'] ?? '',
            'add_time'                => (int)($item['add_time'] ?? 0),
            'extend_info_desc'        => $extendInfo['desc'] ?? '',
            'extend_info_remark'      => $extendInfo['remark'] ?? '',
            'extend_info_company_remark' => $extendInfo['company_remark'] ?? '',
            'extend_info_remark_time' => (int)($extendInfo['remark_time'] ?? 0),
            'extend_info_remark_url'  => $extendInfo['remark_url'] ?? '',
            'create_at'               => $now,
            'update_at'               => $now,
        ];
    }
}
