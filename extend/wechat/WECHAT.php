<?php

 namespace wechat;
 use think\Session;
 class WECHAT{
    
     private $memcache;
     private $uuid;
     private $special_users = array(
        'newsapp', 'fmessage', 'weibo', 'qqmail', 'fmessage', 'tmessage', 'qmessage', 'qqsync', 'floatbottle', 'lbsapp', 'shakeapp', 'medianote', 'qqfriend', 'readerapp', 'blogapp', 'facebookapp', 'masssendapp', 'meishiapp', 'feedsapp', 'voip', 'blogappweixin', 'weixin', 'brandsessionholder', 'weixinreminder', 'wxid_novlwrv3lqwv11', 'gh_22b87fa7cb3c', 'officialaccounts', 'notification_messages', 'wxid_novlwrv3lqwv11', 'gh_22b87fa7cb3c', 'wxitil', 'userexperience_alarm', 'notification_messages'
    );
 
 
    public function  __construct($uuid = ''){
        if(!empty($uuid)){
            $this->uuid = $uuid;
        }
        $this->memcache = new \Memcache();
        $this->memcache->connect('localhost', 11211) or die ("Could not connect"); 
    }

 

    /**
    * 获取当前时间戳精确到毫秒级
    * @access private
    * @return string
    **/
    private function getMillisecond()
    {
       list($usec, $sec) = explode(" ", microtime());
       return (float)sprintf('%.0f',(floatval($usec)+floatval($sec))*1000);
    }
    
    public function getUuid(){
            $url = 'https://login.weixin.qq.com/jslogin?appid=wx782c26e4c19acffb&redirect_uri=https%3A%2F%2Fwx.qq.com%2Fcgi-bin%2Fmmwebwx-bin%2Fwebwxnewloginpage&fun=new&lang=zh_CN&_='.$this->getMillisecond();
            $str = $this->get($url);

            preg_match('/"(.*?)"/',$str,$match);
            session('uuid',$match[1]);
            return $match[1]; 
    }
    
    /**
    * 通过会话ID获得二维码
    * @access public
    * @return string
    **/
    public function getQrcode($uuid){
            $url = 'https://login.weixin.qq.com/qrcode/'.$uuid.'?t=webwx';
            return $url;
    }
     
    public function getLoginStatus($uuid = ''){
            $url = sprintf("https://login.wx2.qq.com/cgi-bin/mmwebwx-bin/login?uuid=%s&tip=1&_=%s", $uuid, $this->getMillisecond());
            $res = $this->get($url);

            preg_match('/=(.*?);/',$res,$match);
            if($match[1] == 200){
                    //登陆成功
                    preg_match('/redirect_uri="(.*?)";/',$res,$match2);
                    return $match2[1];
            }
            return $match[1];
    }
     
	/**
	* 访问登录地址，获得uin和sid,并且保存cookies
	* @access public
	* @param $url string 登录地址
	* @return array
	**/
	public function getCookies($url){
            $uuid = session('uuid');
            $cookie_jar = Cookie_path.DS.$uuid.".cookie";
    
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 对认证证书来源的检查  
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 从证书中检查SSL加密算法是否存在
            curl_setopt($ch, CURLOPT_HEADER,1);//如果你想把一个头包含在输出中，
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);//将 curl_exec()获取的信息以文件流的形式返回，而不是直接输出。设置为0是直接输出
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);//获取的cookie 保存到指定的 文件路径
            $content=curl_exec($ch);     
            if(curl_errno($ch)){
                    $info = array('status' => 0, 'msg' => 'Curl error: '.curl_error($ch));
                    return $info;//这里是设置个错误信息的反馈
             }    

            if($content==false){
                $info = array('status' => 0, 'msg' => '无法获取cookies');
                    return $info;//这里是设置个错误信息的反馈
            }
 
            //正则匹配出wxuin、wxsid
            preg_match('/wxuin=(.*);/iU',$content,$uin); 
            preg_match('/wxsid=(.*);/iU',$content,$sid);
            preg_match('/webwx_data_ticket=(.*);/iU',$content,$webwx);
            preg_match('/<pass_ticket>(.*?)<\/pass_ticket>/', $content,$pass_ticket);

            //@TODO将wxuin、wxsid、webwx_data_ticket存入cookies，以便获取微信头像----暂无效 
            /*if(preg_match_all('/Set-Cookie:[\s]+([^=]+)=([^;]+)/i', $content,$match)) {
                      foreach ($match[1] as $key => $cookieKey ) {
                        setcookie($cookieKey,$match[2][$key],'36000','','.wx.qq.com');
                      }
                    }*/
            //将wxuin、wxsid、webwx_data_ticket存入session

            //因为微信接口分为wx跟wx2两个域名的接口 通过登录回调url 获取后面接口需要使用的域名
      
            $apiinfo = parse_url($url);
            $apihost = $apiinfo['host']?$apiinfo['host']:'wx.qq.com';
            $data = [];
            $data['pass_ticket']=$pass_ticket[1];
            $data['apihost']=$apihost;
            $data['uin']= @$uin[1];
            $data['sid']= @$sid[1];
      
            $this->memcache->set('LoginInfoBy'.$uuid,$data);
            $wxinfo = array(
                    'uin' => @$uin[1],
                    'sid' => @$sid[1]
                    );
            curl_close($ch);
            return $wxinfo;
	}
    
        #初始化                    
        public function initWebchat($uin = '', $sid = ''){
                $uuid = $this->uuid;
                $cookie_jar = Cookie_path.DS.$uuid.".cookie";
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
               
                $apihost = $udata['apihost'];
                $url = sprintf("https://%s/cgi-bin/mmwebwx-bin/webwxinit?r=%s",$apihost,$this->getMillisecond());
   
                if(!$uin || !$sid){
                          $uin = $udata['uin'];
                          $sid = $udata['sid'];
                }
                
                $DeviceID =  'e'.substr(mt_rand().mt_rand(), 1, 15);
                
                $data['BaseRequest'] = array(
                        'Uin' => $uin,
                        'Sid' => $sid,
                        'Skey' => '',
                        'DeviceID' => $DeviceID
                );
          
            
                $user = $this->_post($url,$data,true,false,$cookie_jar);
                
                
                $res = json_encode($user);
                $GroupList = [];
                if(isset($res['ContactList'])){
                    $ContactList  = $res['ContactList'];
                    foreach($ContactList as $v){
                        if(strpos($v['UserName'] ,'@@') !==false){
                            $GroupList[$v['UserName']] = $v;
                        }
                    }
                }
                
                $this->memcache->set('GroupListBy'.$uuid,$GroupList);
                $udata['username'] = $user['User']['UserName'];
                $udata['nickname'] = $user['User']['NickName'];
                $udata['DeviceID'] = $DeviceID;
                $udata['initinfo'] = $user;
                $this->memcache->set('LoginInfoBy'.$uuid,$udata);
 
                return $res;
        }
        
        
 
	public function  getAvatar($uri = ''){
                $uuid = $this->uuid;
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $apihost = $udata['apihost'];
	        $cookie = Cookie_path.DS.$uuid.".cookie";
		$url = "https://".$apihost.$uri;
 
		$res = $this->get($url, $cookie);
                return $res;
	}
        
	/**
	* 获取全部联系人
	* @access public
	* @return mixed
	**/
	public function getContact(){
                $uuid = $this->uuid;
		$cookie_jar = Cookie_path.DS.$uuid.".cookie";
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $apihost = $udata['apihost'];
		$url = sprintf("https://%s/cgi-bin/mmwebwx-bin/webwxgetcontact?lang=zh_CN&r=%s&seq=0", $apihost,$this->getMillisecond());

		$res = $this->_post($url, '{}',false,false,$cookie_jar);
                $res = json_decode($res,1);
                $GroupList = $this->memcache->get('GroupListBy'.$uuid);
                $MemberList = $res['MemberList'];
                
                $contact = array();
                if(!empty($MemberList)){
                    foreach ($MemberList as $k=>$v) {
                        if (($v['VerifyFlag'] & 8) != 0) {  // 公众号/服务号
                            unset($MemberList[$k]);
                        } elseif (in_array($v['UserName'], $this->special_users)) {   // 特殊账号
                            unset($MemberList[$k]); 
                        } elseif ($v['UserName'] == $udata['username']) {  // 自己
                            unset($MemberList[$k]);
                        }elseif (strpos($v['UserName'], '@@') !== false) { // 群聊
                            unset($MemberList[$k]);
                            $GroupList[$v['UserName']] = $v;
                        }
                            
                    }
                }
                            
                $this->memcache->set('GroupListBy'.$uuid,$GroupList);
                $this->memcache->set('ContactListBy'.$uuid,$MemberList);
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $this->webwxbatchgetcontact($GroupList);
                $res = [
                    'GroupList'=> $GroupList,
                    'MemberList' => $MemberList
                ];
                
                
                $res = json_encode($res);
                
		return $res;
	}
        
        
	public function webwxbatchgetcontact($GroupList){//获取多个群的信息
                $uuid = $this->uuid;
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $uin = $udata['uin'];
                $sid = $udata['sid'];
                $apihost = $udata['apihost'];
                $pass_ticket = $udata['pass_ticket'];
                $DeviceID = $udata['DeviceID'] ;
                $url = sprintf('https://%s/cgi-bin/mmwebwx-bin/webwxbatchgetcontact?type=ex&r=%s&pass_ticket=%s',$apihost,time(),$pass_ticket);
                
                $List = [];
                foreach ($GroupList as $g) {
                        $List[] = ["UserName"=> $g['UserName'], "EncryChatRoomId"=>""];
                }
                
                $BaseRequest = array(
                         'Uin' => $uin,
                         'Sid' => $sid,
                         'Skey' => '',
                         'DeviceID' => $DeviceID
                         );
                $params = [
                    'BaseRequest'=> $BaseRequest,
                    "Count"=> count($GroupList),
                    "List"=> $List
                ];
                $dic = $this->_post($url, $params);
                            
                $ContactList = $dic['ContactList'];
                $ContactCount = $dic['Count'];
                $GroupMemeberList = [];
                foreach($ContactList as $key=>$Contact){
                    $MemberList = $Contact['MemberList'];
                    foreach($MemberList as $member)
                        $GroupMemeberList[] = $member;
                }
                
                $this->memcache->set('GroupMemeberListBy'.$uuid,$GroupMemeberList);#群成员
            
               return true;
	}
        
        
        
        
 	/**
	* 发送消息
	* @access public
	* @param $toUsername string 
	* @return mixed
	**/
	public function sendMessage( $toUsername = '', $content = ''){
                $uuid = $this->uuid;
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $apihost = $udata['apihost'];
                $uin = $udata['uin'];
                $sid = $udata['sid'];
		$cookie_jar = Cookie_path.DS.$uuid.".cookie";
  
		$url = sprintf("https://%s/cgi-bin/mmwebwx-bin/webwxsendmsg?sid=%s&r=%s",$apihost,$sid,$this->getMillisecond());
		$DeviceID = $udata['DeviceID'] ;
		$data['BaseRequest'] = array(
			'Uin' => $uin,
			'Sid' => $sid,
			'Skey' => "",
			'DeviceID' => $DeviceID
			);
		$data['Msg'] = array(
			'ClientMsgId' => $this->getMillisecond(),
			'Content' => $content,
			'FromUserName' => $udata['username'],
			'LocalID' => $this->getMillisecond(),
			'ToUserName' => $toUsername,
			'Type' => 1
			);
		$data['Scene'] = 0;
                            
		$res = $this->_post($url, $data,true,false,$cookie_jar);
		return $res;
	}
        
        
        private function getGroupName($id){
            $name = '未知群';
            $uuid = $this->uuid;
            $GroupList =  $this->memcache->get('GroupListBy'.$uuid);
            
            if(isset($GroupList[$id]))
                $name = $GroupList[$id]['NickName'];
            
            if($name == '未知群'){
                  $getGroupList = $this->getNameById($id);
                  $GroupList[$id] = $getGroupList[0];
                  $MemberList = $getGroupList[0]['MemberList'];
                  $name = $getGroupList[0]['NickName'];
                  $GroupMemeberList = $this->memcache->get('GroupMemeberListBy'.$uuid);
                  foreach ($MemberList as $member){
                       $GroupMemeberList[] = $member;
                  }
                  $this->memcache->set('GroupListBy'.$uuid,$GroupList);
                  $this->memcache->set('GroupMemeberListBy'.$uuid,$GroupMemeberList);
                                       
            }
            return $name;                                      
        }
        
        private function getgroupicon($id){
            $uuid = $this->uuid;
            $GroupList =  $this->memcache->get('GroupListBy'.$uuid);
            return isset($GroupList[$id])?$GroupList[$id]['HeadImgUrl']:'';
        }
                        
        public function getUserRemarkName($id){
             $name = substr($id,0,2) == '@@' ? '未知群':'陌生人';
             $uuid = $this->uuid;
             $udata = $this->memcache->get('LoginInfoBy'.$uuid);
             if($id == $udata['username']){
                 return $udata['nickname'];
             }
             
             if(substr($id,0,2) == '@@'){
                 $name = $this->getGroupName($id);
             }else{
                 $ContactList = $this->memcache->get('ContactListBy'.$uuid);
                 foreach($ContactList as $member){
                    if ($member['UserName'] == $id){
                       $name =  $member['RemarkName']?$member['RemarkName']:$member['NickName'];
                    }
                 }
                 $GroupMemeberList = $this->memcache->get('GroupMemeberListBy'.$uuid);
                 foreach($GroupMemeberList as $member){
                     if($member['UserName'] == $id){
                         $name = $member['DisplayName'] ? $member['DisplayName'] : $member['NickName'];
                     }
                 }
             }
             return $name;                      
        }

        public function wxsync(){
                $uuid = $this->uuid;
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                            
                $uin = $udata['uin'];
                $sid = $udata['sid'];
                
                $apihost = $udata['apihost'];
                $synckey = $udata['initinfo']['SyncKey'];
              
		$cookie_jar = Cookie_path.DS.$uuid.".cookie";
   
		$url = sprintf("https://%s/cgi-bin/mmwebwx-bin/webwxsync?sid=%s",$apihost, $sid);
            
		$DeviceID = $udata['DeviceID'] ;
		$data['BaseRequest'] = [
			'Uin' => $uin,
			'Sid' => $sid,
			'Skey' => '',
			'DeviceID' => $DeviceID
			];
 
               
		$data['SyncKey'] = $synckey;
              
		$data['rr'] = time();
          
		$res = $this->_post($url,$data,true,false,$cookie_jar);
                            
                $msgcontent = [];
                if($res['AddMsgCount'] && $res['BaseResponse']['Ret']==0){
                    $udata['initinfo']['SyncKey'] = $res['SyncKey'];
                    $this->memcache->set('LoginInfoBy'.$uuid,$udata);#更新sysnckey
                    $AddMsgList = $res['AddMsgList'];
                    
                    
                    
                    if(count($AddMsgList) > 0){
                        foreach($AddMsgList as $k=>$msg){
                                   $content = $msg['Content'];
                                   $FromUserName =$msg['FromUserName'];
                                   $msgid = $msg['MsgId'];
                                   $ToUserName = $msg['ToUserName'];
                                   $MsgType = $msg['MsgType'];
                                   $fromNikeName  = $this->getUserRemarkName($FromUserName);
                                   $toNickName    = $this->getUserRemarkName($ToUserName);
                                   
                                   $msgcontent = [
                                       'FromUserName'=> $FromUserName,
                                       'ToUserName' => $ToUserName,
                                       'fromNikeName' => $fromNikeName,
                                       'toNickName' => $toNickName
                                   ];
                                   
                                    if((substr( $FromUserName, 0, 2 ) == '@@' && stripos( $content, ':<br/>') !== false)){
                                        list($people, $content) = explode( ':<br/>', $content );
                                        $peoplenickname = $this->getUserRemarkName($people);
                                        $msgcontent['peoplenickname'] = $peoplenickname;
                                        $msgcontent['people'] = $people;
                                        $msgcontent['icon'] = $this->getgroupicon($FromUserName);
                                    }else if(substr( $ToUserName, 0, 2 ) == '@@'){
                                        $msgcontent['icon'] = $this->getgroupicon($ToUserName);
                                    }
                        
                                   $content = $this->handlemsg($msgid,$MsgType,$content);
                                   $msgcontent['Content'] = $content;
                        
                               }
                    }
                }
		return $msgcontent;
	}
        
        private function handlemsg($msgid,$MsgType,$content){
                        
                    switch ($MsgType){
                        case 1://文本消息
                            return $content;
                            break;
                        case 3;
                            $this->getimage($msgid);//保存消息图片到本地
                            return sprintf("<img height='100px' width='100px' src='upload/%s.jpg'/>",$msgid);
                            break;
                        case 34://语音消息
                             $this->getvoice_download($msgid);
                             return  sprintf('<audio src="upload/mp3/%s.mp3" controls="controls"></audio>',$msgid);
                             break;
                        case 43://视频
                            $this->webwxgetvoice_download($msgid);
                            return sprintf('<video src="upload/mp4/%s.mp4" width="320" height="200" controls preload></video>',$msgid);
                            break;
                         case 47: // 动画表情
                            preg_match('/cdnurl\s*=\s*"(.+?)"/',$content,$match);//自定义的表情
                            if(isset($match[1])){
                                return  "<img height='50px' width='50px' src='$match[1]'/>";
                            }
                            break;
                        case 10002://撤回一条消息
                            return ''; 
                            break;
                        default:
                            return '';
                            break;
                 }
        }

                        

        public function getNameById($groupusername){
             $uuid = $this->uuid;
             $udata = $this->memcache->get('LoginInfoBy'.$uuid);
             $uin = $udata['uin'];
             $sid = $udata['sid'];
             $apihost = $udata['apihost'];
             $pass_ticket = $udata['pass_ticket'];
             $DeviceID = $udata['DeviceID'] ;
             $cookie_jar = Cookie_path.DS.$uuid.".cookie";
             $url = sprintf('https://%s/cgi-bin/mmwebwx-bin/webwxbatchgetcontact?type=ex&r=%s&pass_ticket=%s',$apihost,time(),$pass_ticket);
             $BaseRequest = array(
                    'Uin' => $uin,
                    'Sid' => $sid,
                    'Skey' => '',
                    'DeviceID' => $DeviceID
                    );
             $params = [
                 'BaseRequest' => $BaseRequest,
                 "Count" => 1,
                 "List" => [["UserName" => $groupusername, "EncryChatRoomId" => ""]]
             ];
             $dic = $this->_post($url, $params,true,FALSE,$cookie_jar);
          
             return $dic['ContactList'];
        }
        
     
        //上传媒体文件
        public function uploadMedia($uuid,$ToUserName,$file_name){
           
            if (!is_file($file_name)) {
                return false;
            }
            $udata = $this->memcache->get('LoginInfoBy'.$uuid);
            $apihost = $udata['apihost'];
            $url = 'https://file.'.$apihost.'/cgi-bin/mmwebwx-bin/webwxuploadmedia?f=json';
   
          
            $cookie_jar = Cookie_path.DS.$uuid.".cookie";
            $media_count = 1;
                            
            # MIME格式
            # mime_type = application/pdf, image/jpeg, image/png, etc.
            if(function_exists('mime_content_type')){
                $mime_type = mime_content_type($file_name);
            }else{
                $mime_type = 'image';
            }
            
            $media_type = explode('/', $mime_type)[0] == 'image' ? 'pic' : 'doc';
            $fTime = filemtime($file_name);
            $lastModifieDate = gmdate('D M d Y H:i:s TO', $fTime) . ' (CST)'; //'Thu Mar 17 2016 00:55:10 GMT+0800 (CST)';
            # 文件大小
            $file_size = filesize($file_name);
            $pass_ticket = $udata['pass_ticket'];
            $client_media_id = (time() * 1000) . mt_rand(10000, 99999);
            $webwx_data_ticket = '';
            
            $fp = fopen($cookie_jar, 'r');
            while ($line = fgets($fp)) {
                if (strpos($line, 'webwx_data_ticket') !== false) {
                    $arr = explode("\t", trim($line));
                    //var_dump($arr);
                    $webwx_data_ticket = $arr[6];
                    break;
                }
            }
            fclose($fp);
  
            if ($webwx_data_ticket == '')
                return "None Fuck Cookie";
     
	    $uin = $udata['uin'];
	    $sid = $udata['sid'];
	    $DeviceID = $udata['DeviceID'] ;
            $BaseRequest = array(
			'Uin' => $uin,
			'Sid' => $sid,
			'Skey' => "",
			'DeviceID' => $DeviceID
	     );
            
            $uploadmediarequest = self::json_encode([
                        "BaseRequest" => $BaseRequest,
                        "ClientMediaId" => $client_media_id,
                        "TotalLen" => $file_size,
                        "StartPos" => 0,
                        "DataLen" => $file_size,
                        "MediaType" => 4,
                        "UploadType" => 2,
                        "FromUserName" => $udata['username'],
                        "ToUserName" => $ToUserName,
                        "FileMd5" => md5_file($file_name)
            ]); 
            
        $multipart_encoder = [
            'id' => 'WU_FILE_' . $media_count,
            'name' => $file_name,
            'type' => $mime_type,
            'lastModifieDate' => $lastModifieDate,
            'size' => $file_size,
            'mediatype' => $media_type,
            'uploadmediarequest' => $uploadmediarequest,
            'webwx_data_ticket' => $webwx_data_ticket,
            'pass_ticket' => $pass_ticket,
            'filename' => '@' . $file_name
        ];
     
         $response_json = json_decode($this->_post($url, $multipart_encoder, false, true), true);
           if ($response_json['BaseResponse']['Ret'] == 0)
            return $response_json;
            return null;
        }
        
        
        //发送图片消息
        public function webwxsendmsgimg($uuid,$ToUserName, $media_id){
            $udata = $this->memcache->get('LoginInfoBy'.$uuid);
            $apihost = $udata['apihost'];
            $pass_ticket = $udata['pass_ticket'];
            $DeviceID = $udata['DeviceID'] ;
	    $uin = $udata['uin'];
	    $sid = $udata['sid'];
            $url = sprintf('https://%s/cgi-bin/mmwebwx-bin/webwxsendmsgimg?fun=async&f=json&lang=zh_CN&pass_ticket=%s',$apihost,$pass_ticket);
            $clientMsgId = (time() * 1000) . substr(uniqid(), 0, 5);
            $BaseRequest = array(
                    'Uin' => $uin,
                    'Sid' => $sid,
                    'Skey' => "",
                    'DeviceID' => $DeviceID
            );
            $data = [
                "BaseRequest" => $BaseRequest,
                "Msg" => [
                    "Type" => 3,
                    "MediaId" => $media_id,
                    "FromUserName" => $udata['username'],
                    "ToUserName" => $ToUserName,
                    "LocalID" => $clientMsgId,
                    "ClientMsgId" => $clientMsgId
                ]
            ];
            $dic = $this->_post($url, $data);
            return $dic['BaseResponse']['Ret'] == 0;
              
        }
                            
        #发送文件
        public function webwxsendappmsg($uuid,$username,$mediaId,$file){
            $udata = $this->memcache->get('LoginInfoBy'.$uuid);
            $apihost = $udata['apihost'];
            $pass_ticket = $udata['pass_ticket'];
            $DeviceID = $udata['DeviceID'] ;
	    $uin = $udata['uin'];
	    $sid = $udata['sid'];
            $url = sprintf('https://%s/cgi-bin/mmwebwx-bin/webwxsendappmsg?fun=async&f=json',$apihost);
            $BaseRequest = array(
                    'Uin' => $uin,
                    'Sid' => $sid,
                    'Skey' => "",
                    'DeviceID' => $DeviceID
            );

            $filearr =  explode('.', $file);
            $filearr = end($filearr);
            $data = [
                'BaseRequest'=> $BaseRequest,
                'Msg'        => [
                    'Type'        => 6,
                    'Content'     => sprintf("<appmsg appid='wxeb7ec651dd0aefa9' sdkver=''><title>%s</title><des></des><action></action><type>6</type><content></content><url></url><lowurl></lowurl><appattach><totallen>%s</totallen><attachid>%s</attachid><fileext>%s</fileext></appattach><extinfo></extinfo></appmsg>", basename($file), filesize($file), $mediaId, $filearr),
                    'FromUserName'=> $udata['username'],
                    'ToUserName'  => $username,
                    'LocalID'     => time() * 1e4,
                    'ClientMsgId' => time() * 1e4,
                ],
            ];
            $cookie_jar = Cookie_path.DS.$uuid.".cookie";                
            $dic = $this->_post($url, $data,true,false,$cookie_jar);
            return $dic['BaseResponse']['Ret'] == 0;
        }
        
                            
        
        //根据消息ID保存图片下来
	public function  getimage( $msgid = ''){
                $uuid = $this->uuid;
	        $cookie_jar = Cookie_path.DS.$uuid.".cookie";
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $apihost = $udata['apihost'];
		$url = "https://{$apihost}/cgi-bin/mmwebwx-bin/webwxgetmsgimg?&MsgID={$msgid}&skey=&type=big";
		$res = $this->get($url, $cookie_jar);
                $filename = 'upload'.DS.$msgid.'.jpg';
                $fp= @fopen($filename,"a");
                fwrite($fp,$res); //写入文件  
                fclose($fp);
	}
        
        
        //根据消息id下载语音消息
        public function getvoice_download($msgid = ''){
            $uuid = $this->uuid;
            $cookie_jar = Cookie_path.DS.$uuid.".cookie";
            $udata = $this->memcache->get('LoginInfoBy'.$uuid);
            $apihost = $udata['apihost'];
            $url = sprintf('https://%s/cgi-bin/mmwebwx-bin/webwxgetvoice?msgid=%s&skey=',$apihost,$msgid);
            $res = $this->get($url, $cookie_jar);
            $savepath = 'upload'.DS.'mp3'.DS.$msgid.'.mp3';
             
            self::saveTo($savepath, $res);
        }
        
        //根据消息id保存消息视频
        public function webwxgetvoice_download($msgid){
                $uuid = $this->uuid;
                $cookie_jar = Cookie_path.DS.$uuid.".cookie";
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
                $apihost = $udata['apihost'];
                
                $apihost = $udata['apihost'];
                $url = sprintf('https://%s/cgi-bin/mmwebwx-bin/webwxgetvideo?msgid=%s&skey=',$apihost, $msgid);
                $header = array(
                      'Range:bytes=0-'
                );
                $res = $this->get($url, $cookie_jar,$header);
                $savepath = 'upload'.DS.'mp4'.DS.$msgid.'.mp4';
                self::saveTo($savepath, $res);
        }
        
        
        public static function saveTo($file,$data){
            $path = dirname($file);
            if(!is_dir($path)){
                mkdir($path,0755,true);
            }
            file_put_contents($file, $data);
        }
        
        
        /**保持与服务器的信息同步
         *window.synccheck={retcode:”0”,selector:”0”}
         *如果retcode中的值不为0，则说明与服务器的通信有问题了，但具体问题我就无法预测了，selector中的值表示客户端需要作出的处理，目前已经知道当为6的时候表示有消息来了，就需要去访问另一个接口获得新的消息。
         */
        public function synccheck(){
                $uuid = $this->uuid;
                $udata = $this->memcache->get('LoginInfoBy'.$uuid);
             
                $uin = $udata['uin'];
                $sid = $udata['sid'];
                
                $apihost = $udata['apihost'];
                $synckey = $udata['initinfo']['SyncKey'];
                $cookie_jar = Cookie_path.DS.$uuid.".cookie";

                $DeviceID = $udata['DeviceID'] ;
                $params = array(
                    'r' => time(),
                    'sid' => $sid,
                    'uin' => $uin,
                    'skey' => '',
                    'devicedid' => $DeviceID,
                    'synckey' => $synckey,
                    '_' => time()
                );
                $url = 'https://webpush.'.$apihost.'/cgi-bin/mmwebwx-bin/synccheck?'.http_build_query($params);

                $data = $this->get($url,$cookie_jar);
 
                return $data;
        }
                            
        
    /**
     * 发起GET请求
     *
     * @access public
     * @param string $url
     * @return string
     */
    function get($url = '', $cookie = '',$header='')
    {
      $ch = curl_init(); 
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 对认证证书来源的检查  
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 从证书中检查SSL加密算法是否存在 
      if($cookie){
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
            curl_setopt ($ch, CURLOPT_REFERER,'https://wx.qq.com');
       }
        if($header){
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }


      curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //将curl_exec()获取的信息以文件流的形式返回，而不是直接输出。
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
      $output = curl_exec($ch);
      curl_close($ch);
      return $output;
     }
     
        
    private function _post($url, $param, $jsonfmt = true, $post_file = false,$cookie = '') {

        $oCurl = curl_init();
        if (stripos($url, "https://") !== FALSE) {
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($oCurl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($oCurl, CURLOPT_SSLVERSION, 1); //CURL_SSLVERSION_TLSv1
        }
        if (PHP_VERSION_ID >= 50500 && class_exists('\CURLFile')) {
            $is_curlFile = true;
        } else {
            $is_curlFile = false;
            if (defined('CURLOPT_SAFE_UPLOAD')) {
                curl_setopt($oCurl, CURLOPT_SAFE_UPLOAD, false);
            }
        }
        $header = [
            'User-Agent: ' . $_SERVER['HTTP_USER_AGENT']
        ];
        if ($jsonfmt) {
            $param = self::json_encode($param);
            $header[] = 'Content-Type: application/json; charset=UTF-8';
            //var_dump($param);
        }
        if (is_string($param)) {
            $strPOST = $param;
        } elseif ($post_file) {
            if ($is_curlFile) {
                foreach ($param as $key => $val) {
                    if (substr($val, 0, 1) == '@') {
                        $param[$key] = new \CURLFile(realpath(substr($val, 1)));
                    }
                }
            }
            $strPOST = $param;
        } else {
            $aPOST = array();
            foreach ($param as $key => $val) {
                $aPOST[] = $key . "=" . urlencode($val);
            }
            $strPOST = implode("&", $aPOST);
        }

        curl_setopt($oCurl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($oCurl, CURLOPT_URL, $url);
        curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($oCurl, CURLOPT_POST, true);
        curl_setopt($oCurl, CURLOPT_POSTFIELDS, $strPOST);
        if($cookie){
            curl_setopt($oCurl, CURLOPT_COOKIEFILE, $cookie);
            curl_setopt ($oCurl, CURLOPT_REFERER,'https://wx.qq.com');
        }
        $sContent = curl_exec($oCurl);
        $aStatus = curl_getinfo($oCurl);
        curl_close($oCurl);
        if (intval($aStatus["http_code"]) == 200) {
            if ($jsonfmt)
                return json_decode($sContent, true);
            return $sContent;
        }else {
            return false;
        }
    }

    public static function json_encode( $json ) {
        return json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }       
    
 }