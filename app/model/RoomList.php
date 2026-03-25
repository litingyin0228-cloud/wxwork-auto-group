<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群列表模型
 */
class RoomList extends Model
{
    protected $name = 'room_list';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_at';
    protected $updateTime = 'update_at';

    /**
     * 格式化创建时间戳
     *
     * @param int $value
     * @param array $data
     * @return string
     */
    public function getCreatetsTextAttr($value, $data): string
    {
        $timestamp = $data['createts'] ?? 0;
        if (empty($timestamp)) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 格式化更新时间戳
     *
     * @param int $value
     * @param array $data
     * @return string
     */
    public function getUpdatetsTextAttr($value, $data): string
    {
        $timestamp = $data['updatets'] ?? 0;
        if (empty($timestamp)) {
            return '';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 格式化移交时间
     *
     * @param int $value
     * @return string
     */
    public function getShiftTimeTextAttr($value): string
    {
        if (empty($value) || $value == 0) {
            return '';
        }
        return date('Y-m-d H:i:s', $value);
    }

    /**
     * 格式化原群主VID
     *
     * @param string $value
     * @return string
     */
    public function getOldOwnerVidAttr($value): string
    {
        return $value ?? '0';
    }

    /**
     * 格式化标志位
     *
     * @param int $value
     * @return int
     */
    public function getFlagAttr($value): int
    {
        return (int)($value ?? 0);
    }

    /**
     * 格式化成员数量
     *
     * @param int $value
     * @return int
     */
    public function getMemberCountAttr($value): int
    {
        return (int)($value ?? 0);
    }

    /**
     * 搜索器：按群ID查询
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
     * 搜索器：按群主VID查询
     *
     * @param $query
     * @param string $ownerVid
     * @return mixed
     */
    public function searchOwnerVidAttr($query, string $ownerVid)
    {
        return $query->where('owner_vid', $ownerVid);
    }

    /**
     * 搜索器：按群名称模糊查询
     *
     * @param $query
     * @param string $roomName
     * @return mixed
     */
    public function searchRoomNameAttr($query, string $roomName)
    {
        return $query->whereLike('roomname', '%' . $roomName . '%');
    }

    /**
     * 搜索器：按成员数量范围查询
     *
     * @param $query
     * @param array $range [min, max]
     * @return mixed
     */
    public function searchMemberCountRangeAttr($query, array $range)
    {
        return $query->whereBetween('member_count', $range);
    }

    /**
     * 搜索器：按标志位查询
     *
     * @param $query
     * @param int $flag
     * @return mixed
     */
    public function searchFlagAttr($query, int $flag)
    {
        return $query->where('flag', $flag);
    }

    /**
     * 搜索器：按原群主VID查询
     *
     * @param $query
     * @param string $oldOwnerVid
     * @return mixed
     */
    public function searchOldOwnerVidAttr($query, string $oldOwnerVid)
    {
        return $query->where('old_owner_vid', $oldOwnerVid);
    }

    /**
     * 搜索器：按创建时间范围查询
     *
     * @param $query
     * @param array $range [start_date, end_date]
     * @return mixed
     */
    public function searchCreateAtRangeAttr($query, array $range)
    {
        return $query->whereBetweenTime('create_at', $range[0], $range[1]);
    }

    /**
     * 搜索器：按更新时间范围查询
     *
     * @param $query
     * @param array $range [start_date, end_date]
     * @return mixed
     */
    public function searchUpdateAtRangeAttr($query, array $range)
    {
        return $query->whereBetweenTime('update_at', $range[0], $range[1]);
    }

    /**
     * 获取指定群主的群列表
     *
     * @param string $ownerVid
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public static function getByOwner(string $ownerVid, int $page = 1, int $pageSize = 20): array
    {
        return self::where('owner_vid', $ownerVid)
            ->order('create_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
    }

    /**
     * 根据群ID获取群信息
     *
     * @param string $roomId
     * @return array|null
     */
    public static function getByRoomId(string $roomId): ?array
    {
        $room = self::where('room_id', $roomId)->find();
        return $room ? $room->toArray() : null;
    }

    /**
     * 检查群是否存在
     *
     * @param string $roomId
     * @return bool
     */
    public static function roomExists(string $roomId): bool
    {
        return self::where('room_id', $roomId)->count() > 0;
    }

    /**
     * 获取群成员数量
     *
     * @param string $roomId
     * @return int
     */
    public static function getMemberCount(string $roomId): int
    {
        $room = self::where('room_id', $roomId)->field('member_count')->find();
        return $room ? (int)$room['member_count'] : 0;
    }

    /**
     * 保存或更新群信息
     *
     * @param array $data
     * @return int
     */
    public static function saveOrUpdate(array $data): int
    {
        $roomId = $data['room_id'] ?? '';
        if (empty($roomId)) {
            throw new \InvalidArgumentException('room_id 不能为空');
        }

        $existing = self::where('room_id', $roomId)->find();

        $saveData = [
            'room_id'      => $roomId,
            'owner_vid'    => $data['owner_vid'] ?? '',
            'createts'     => $data['createts'] ?? time(),
            'updatets'     => $data['updatets'] ?? time(),
            'member_count' => $data['member_count'] ?? 0,
            'flag'         => $data['flag'] ?? 0,
            'roomname'     => $data['roomname'] ?? '',
            'roomurl'      => $data['roomurl'] ?? '',
            'infoticket'   => $data['infoticket'] ?? '',
            'shift_time'   => $data['shift_time'] ?? 0,
            'old_owner_vid' => $data['old_owner_vid'] ?? '0',
            'create_at'    => $data['create_at'] ?? date('Y-m-d H:i:s'),
            'update_at'    => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            // 更新
            $existing->save($saveData);
            return $existing['id'];
        } else {
            // 新增
            return self::insertGetId($saveData);
        }
    }

    /**
     * 批量保存群列表
     *
     * @param array $list
     * @return bool
     */
    public static function saveBatch(array $list): bool
    {
        if (empty($list)) {
            return true;
        }

        $data = [];
        foreach ($list as $item) {
            $data[] = [
                'room_id'      => $item['room_id'] ?? '',
                'owner_vid'    => $item['owner_vid'] ?? '',
                'createts'     => $item['createts'] ?? time(),
                'updatets'     => $item['updatets'] ?? time(),
                'member_count' => $item['member_count'] ?? 0,
                'flag'         => $item['flag'] ?? 0,
                'roomname'     => $item['roomname'] ?? '',
                'roomurl'      => $item['roomurl'] ?? '',
                'infoticket'   => $item['infoticket'] ?? '',
                'shift_time'   => $item['shift_time'] ?? 0,
                'old_owner_vid' => $item['old_owner_vid'] ?? '0',
                'create_at'    => $item['create_at'] ?? date('Y-m-d H:i:s'),
                'update_at'    => $item['update_at'] ?? date('Y-m-d H:i:s'),
            ];
        }

        return self::insertAll($data) !== false;
    }

    /**
     * 更新群成员数量
     *
     * @param string $roomId
     * @param int $memberCount
     * @return bool
     */
    public static function updateMemberCount(string $roomId, int $memberCount): bool
    {
        return self::where('room_id', $roomId)->update([
            'member_count' => $memberCount,
            'update_at'   => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    /**
     * 更新群名称
     *
     * @param string $roomId
     * @param string $roomName
     * @return bool
     */
    public static function updateRoomName(string $roomId, string $roomName): bool
    {
        return self::where('room_id', $roomId)->update([
            'roomname' => $roomName,
            'update_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    /**
     * 获取移交过的群列表
     *
     * @param string $ownerVid
     * @return array
     */
    public static function getShiftedRooms(string $ownerVid): array
    {
        return self::where('owner_vid', $ownerVid)
            ->where('shift_time', '>', 0)
            ->order('shift_time', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 获取需要同步的群列表（更新时间超过指定秒数）
     *
     * @param int $seconds
     * @param int $limit
     * @return array
     */
    public static function getNeedSyncRooms(int $seconds = 3600, int $limit = 100): array
    {
        $timestamp = time() - $seconds;
        return self::where('updatets', '<', $timestamp)
            ->order('updatets', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 统计群主拥有的群数量
     *
     * @param string $ownerVid
     * @return int
     */
    public static function countByOwner(string $ownerVid): int
    {
        return self::where('owner_vid', $ownerVid)->count();
    }
}
