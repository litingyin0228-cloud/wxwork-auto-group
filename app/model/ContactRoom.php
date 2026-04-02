<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群列表模型
 */
class ContactRoom extends Model
{
    protected $name = 'contact_room';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_at';
    protected $updateTime = 'update_at';
}