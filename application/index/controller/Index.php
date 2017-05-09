<?php
namespace app\index\controller;
use think\Controller;
use wechat\WECHAT;
use think\Request;
use think\Session;
class Index extends Controller
{
    private $wechat;
    public function _initialize() {
         parent::_initialize();
         $uuid = Request::instance()->request('uuid');
         $this->wechat = new WECHAT($uuid);
    }


    public function index()
    {
       return $this->fetch('index');
    }
    
    public function qrcode(){
           
        $uuid = $this->wechat->getUuid();
        $url = $this->wechat->getQrcode($uuid);
 
        return $this->fetch('',['url'=>$url,'uuid'=>$uuid]);
    }
    //轮询判断登录状态
    public function status(){
        $uuid = Request::instance()->get('uuid');
        $res = $this->wechat->getLoginStatus($uuid);
         if($res == 201){
           //已扫描，待确认
           $data = array('status' => 1);
         }elseif (substr_count($res, 'http')) {
           //确认成功
           $data = array('status' => 2);
         }else{
           //待扫描
           $data = array('status' => 0);
         }
         $data['msg'] = $res;
         exit(json_encode($data));
    }
    //登录成功回调
    public function cookies(){
        $url = Request::instance()->post('url');
        $wxinfo = $this->wechat->getCookies($url);
        $wxinfo['status'] = 1;

        exit(json_encode($wxinfo));
    }
    
    public function init(){
        $ret = json_decode($this->wechat->initWebchat(),true);
        exit(json_encode($ret));
       
    }
    
    public function avatar(){
        $uri = Request::instance()->get('uri');
        $res = $this->wechat->getAvatar($uri);
        header('Content-Type: image/jpeg');
        exit($res);
    }
    
    public function users(){
         $users = $this->wechat->getContact();
         exit($users);
    }
    
    public function sync(){
          $message = $this->wechat->wxsync();
          exit(json_encode($message));
 
    }
    
    public function send(){
            $toUsername = Request::instance()->request('toUsername');
            $content = Request::instance()->request('content');
            $res = $this->wechat->sendMessage($toUsername,$content);
            exit(json_encode($res));
    }
    
    public function sendimage(){//发送图片
        $file =  request()->file('uploadfile');
           #移动到框架应用根目录/public/uploads/ 目录下
        $info = $file->move(ROOT_PATH . 'public' . DS . 'upload');
     
        if($info){
            $uuid = Request::instance()->request('uuid');
            $ToUserName = Request::instance()->request('toUsername');
            if(!$uuid || !$ToUserName){
                return;
            }
            $savename = $info->getSaveName();
            $filename = ROOT_PATH.DS. 'public' . DS . 'upload'.DS.$savename;
            $ret = $this->wechat->uploadMedia($uuid, $ToUserName, $filename);#上传素材
            if($ret['MediaId']){
                $mime_type = mime_content_type($filename);
                $mime_type = explode('/', $mime_type)[0];
                if($mime_type == 'image'){
                    if($this->wechat->webwxsendmsgimg($uuid,$ToUserName, $ret['MediaId'])){
                        exit(json_encode(array('image'=>'upload'.DS.$savename,'status'=>1)));
                    }else{
                        exit(json_encode(array('image'=>'','status'=>0)));
                    } 
                }else{
                    #发送文件
                    if($this->wechat->webwxsendappmsg($uuid,$ToUserName, $ret['MediaId'], $filename)){
                        exit(json_encode(array('status'=>1)));
                    }else{
                        exit(json_encode(array('status'=>0)));
                    }
                }
            }
        }else{
            echo $file->getError();
        }
    }
 
    
    public function synccheck(){
        $message = $this->wechat->synccheck();
        exit($message);
    }
 
    public function test(){
       
    }
    
 
}
