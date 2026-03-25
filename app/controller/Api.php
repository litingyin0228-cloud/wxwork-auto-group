<?php
namespace app\controller;

use app\BaseController;
use app\service\JuhebotService;
use app\service\LogService;
use think\App;
use think\facade\Db;

class Api extends BaseController
{
    /**
     * 聚合聊天服务
     */
    private JuhebotService $juheService;

    /**
     * 日志服务
     */
    private LogService $logService;

    public function __construct(App $app)
    {
        $this->juheService = new JuhebotService();
        $this->logService = new LogService();
        parent::__construct($app);
    }

    /**
     * 获取群列表
     * /room/get_room_list
     */
    public function getRoomList()
    {
        $roomList = $this->juheService->getRoomList(0, 20);
        return $this->success($roomList,'获取群列表成功');
    }

    public function index()
    {
        return $this->success(['data'=>'success'],'操作成功');
    }

}
