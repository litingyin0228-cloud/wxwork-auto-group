<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 标签列表模型（wxwork_label_list）
 */
class LabelList extends Model
{
    protected $name = 'label_list';

    protected $autoWriteTimestamp = false;

    /**
     * 根据 id 判断记录是否存在
     */
    public static function existsById(string $id): bool
    {
        return self::where('id', $id)->find() !== null;
    }

    /**
     * 根据 id 更新记录
     *
     * @param string $id         标签ID（主键）
     * @param array  $updateData 要更新的字段
     * @return int  affected rows
     */
    public static function updateById(string $id, array $updateData): int
    {
        return self::where('id', $id)->update($updateData);
    }

    /**
     * 将 API 返回的单条标签数据映射为数据库字段
     *
     * @param array $item API 返回的原始数据
     * @param int   $syncType  同步类型：1=企业标签 2=个人标签（写入 business_type）
     * @return array
     */
    public static function mapLabelToRow(array $item, int $syncType): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'id'             => (string)($item['id'] ?? $item['label_id'] ?? ''),
            'name'           => $item['name'] ?? $item['label_name'] ?? $item['labelName'] ?? '',
            'data_type'      => (int)($item['data_type'] ?? $item['dataType'] ?? 0),
            'b_deleted'      => (int)($item['b_deleted'] ?? $item['bDeleted'] ?? 0),
            'label_groupid'  => (int)($item['label_groupid'] ?? $item['labelGroupid'] ?? 0),
            'label_type'     => (int)($item['label_type'] ?? $item['labelType'] ?? 0),
            'business_type' => $syncType,
            'order'          => (int)($item['order'] ?? 0),
            'service_groupid'=> (int)($item['service_groupid'] ?? $item['serviceGroupid'] ?? 0),
            'update_at'      => $now,
            'create_at'      => $now,
            'status'         => 1,
        ];
    }

    /**
     * 将 API 返回数据中可能变化的字段提取出来，用于更新
     *
     * @param array $item API 返回的原始数据
     * @return array
     */
    public static function diffLabelUpdate(array $item): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'name'           => $item['name'] ?? $item['label_name'] ?? $item['labelName'] ?? '',
            'data_type'      => (int)($item['data_type'] ?? $item['dataType'] ?? 0),
            'b_deleted'      => (int)($item['b_deleted'] ?? $item['bDeleted'] ?? 0),
            'label_groupid'  => (int)($item['label_groupid'] ?? $item['labelGroupid'] ?? 0),
            'label_type'     => (int)($item['label_type'] ?? $item['labelType'] ?? 0),
            'order'          => (int)($item['order'] ?? 0),
            'service_groupid'=> (int)($item['service_groupid'] ?? $item['serviceGroupid'] ?? 0),
            'update_at'      => $now,
        ];
    }
}
