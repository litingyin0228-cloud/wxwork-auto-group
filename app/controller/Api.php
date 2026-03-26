<?php
namespace app\controller;

use app\BaseController;
use app\model\ApplyContactList;
use app\service\JuhebotService;
use app\service\LogService;
use think\App;
use think\facade\Cache;
use think\facade\Db;

class Api extends BaseController
{
    /**
     * 聚合聊天服务
     */

    private static ?JuhebotService $juhebotService = null;
    /**
     * 获取 JuhebotService 单例
     *
     * @return JuhebotService
     */
    private function getJuhebot(): JuhebotService
    {
        if (self::$juhebotService === null) {
            self::$juhebotService = new JuhebotService();
        }
        return self::$juhebotService;
    }

    /**
     * 日志服务
     */
    private LogService $logService;

    public function __construct(App $app)
    {
        $this->logService = new LogService();
        parent::__construct($app);
    }

    public function testJuheApi(){
        
        $res = $this->syncApplyContact();
        return $this->success(["insertCount"=>$res],'同步申请人列表成功');
    }

    protected function syncApplyContact()
    {
        $seq = Cache::get('last_apply_seq');
        // 同步好友申请
        $contactList = $this->getJuhebot()->syncApplyContact($seq);
        
        LogService::info([
            'tag'     => 'AutoGroup',
            'message' => '同步好友申请',
            'data'    => $contactList,
        ]);
        if (empty($contactList['data']['contact_list'])) {
            return;
        }
        $insertCount = 0;
        
        Cache::set('last_apply_seq', $contactList['data']['last_seq']); // 缓存SEQ
        foreach ($contactList['data']['contact_list'] as $contact) {
            if (ApplyContactList::where("user_id",$contact['user_id'])->count() > 0) {
                continue;
            }
            // 处理每个联系人
            $insertData = $contact;
            $insertData['extend_info_desc'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark'] = $contact['extend_info']['desc'];
            $insertData['extend_info_company_remark'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark_time'] = $contact['extend_info']['desc'];
            $insertData['extend_info_remark_url'] = $contact['extend_info']['desc'];
            ApplyContactList::create($contact);
            $insertCount++;
        }
        return $insertCount;
    }
}
