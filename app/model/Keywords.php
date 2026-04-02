<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 群列表模型
 */
class Keywords extends Model
{
    protected $name = 'keywords';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_at';
    protected $updateTime = 'update_at';
}