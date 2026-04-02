<?php
namespace app\controller;

use app\BaseController;
use app\model\ApplyContactList;
use app\model\GroupChatLog;
use app\service\JuhebotService;
use app\service\LogService;
use app\service\MessageCallbackService;
use app\service\WxWorkService;
use Fukuball\Jieba\Finalseg;
use Fukuball\Jieba\Jieba;
use think\App;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Event;
use think\Request;

class Api extends BaseController
{
    public function testJuheApi(Request $request){
        // $this->getJuhebot()->sendWeApp("R:10893760625613145", ,"一键零申报","","欢迎使用一键零申报","/pages/index/index","https://shenbao.guiyangyuanqu.cn/uploads/images/logo.jpg",0,"");
        // $content = $request->get('content');
        // ini_set('memory_limit', '1024M');
        // Jieba::init();
        // Finalseg::init();
        // $res = Jieba::cut($content);

        $service = new WxWorkService();
        $res = $service->addContactWay("");
        return $this->success(["res"=>$res],'添加联系人方式成功');

        // $seq = Cache::set("last_contact_seq", "17999357");

        $orgName = Db::table("tax_org")->where("tax_id", 171)->value("name");
        // dd($orgName);

        // $applies = ApplyContactList::getPendingList(10);

        return $this->success(["res"=>$orgName],'处理好友申请成功');
        // Event::trigger('RoomCreated', [
        //     'guid'        => "0e950e07-0b01-3019-8269-31cee91ee6bf",
        //     'notify_type' => 2131,
        //     'data'        => [],
        // ]);
        // return $this->success(["seq"=>Cache::get("last_contact_seq")],'获取申请联系人序号成功');
        // $service = new MessageCallbackService();
        // $res = $service->processApplyContacts(env('JUHEBOT.GUID', ''), 10);
        // return $this->success(["res"=>$res],'处理好友申请成功');

        // echo GroupChatLog::where("customer_name", "干干")->where("mobile", "!=", "")->limit(1)->value("mobile");

        // echo Db::table("tax_members")->where("phone", "13765089454")->value("org_id");
        
        // return $this->success(["content"=>$content, "list"=>$res],'分词成功');
        // $res = $this->syncApplyContact();
        // return $this->success(["insertCount"=>$res],'同步申请人列表成功');
    }
}
