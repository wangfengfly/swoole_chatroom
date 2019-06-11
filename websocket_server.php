<?php

set_time_limit(0);
ini_set("memory_limit", "-1");
ini_set('default_socket_timeout', -1);

require_once(__DIR__.'/FileCache.php');
require_once(__DIR__.'/Log.php');

class Server{

    const IP = '10.134.109.235';
    const PORT = 443;

    const CA_PATH = '/usr/local/ca/';

    const REACTOR_NUM = 6;
    const WORKER_NUM = 16;
    const BACKLOG = 128;
    const MAX_REQUEST = 0;

    const ACC_FD_KEY = 'acc_fd_';
    const FD_ACC_KEY = 'fd_acc_';
    const ROOT_DIR_PREFIX = 'ch_';

    private $serv;

    private function clean(){
        $acc_fd = self::ACC_FD_KEY;
        $fd_acc = self::FD_ACC_KEY;
        $ch = self::ROOT_DIR_PREFIX;

        $cmd = "rm -f /dev/shm/$acc_fd* && rm -f /dev/shm/$fd_acc* && rm -rf /dev/shm/$ch*";
        shell_exec($cmd);
    }

    public function run(){

        $this->clean();

        //一定要加第三和第四个参数，开启websocket的wss协议模式, 进程模式一定要是SWOOLE_PROCESS, 因为SWOOLE_BASE不支持IPC，无法进程间通信
        $this->serv = new \swoole_websocket_server(self::IP, self::PORT, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
        //同时支持ws 80端口
        $this->serv->addlistener(self::IP, 8090, SWOOLE_SOCK_TCP);

        //tcp_keepidle是tcp_keepalive底层探测，heartbeat是应用层心跳包探测，heartbeat是为了处理客户端的异常退出。
        //当客户端因为崩溃等异常退出时，通过heartbeat服务器端也可以知道哪些客户端异常退出，从而发送quit_notify广播通知。
        $this->serv->set(array(
            'reactor_num' => self::REACTOR_NUM,
            'worker_num' => self::WORKER_NUM,
            'backlog' => self::BACKLOG,
            'max_request' => self::MAX_REQUEST,
            'open_tcp_keepalive' => true,
            'tcp_keepidle' => 300,
            'tcp_keepinterval' => 60,
            'tcp_keepcount' => 10,
            'ssl_cert_file' => self::CA_PATH.'xxx.com.pem',
            'ssl_key_file' => self::CA_PATH.'xxx.com.key',
            'heartbeat_idle_time' => 180,
            'heartbeat_check_interval' => 60,
        ));



        $this->serv->on('open', [$this, 'open']);
        $this->serv->on('message', [$this, 'message']);
        $this->serv->on('request', [$this, 'request']);
        $this->serv->on('close', [$this, 'close']);

        $this->serv->start();

    }

    public function open(\swoole_websocket_server $server, \swoole_http_request $request){
        Log::getInstance('server')->write('Client: '.$request->fd.' opened.', 'debug');
    }

    public function request(Swoole\Http\Request $request, Swoole\Http\Response $response){
        $get = $request->get;

        if(!$get['action']){
            $response->end("missing required parameters");
        }else if($get['action'] == 'channels'){
            $fc = new FileCache('/dev/shm');
            $chs = $fc->getFiles();
            $data = [];
            foreach($chs as $ch){
                if($ch && strpos($ch, self::ROOT_DIR_PREFIX) !== false) {
                    $accs = $fc->setRootDir('/dev/shm/' . $ch . '/')->getFiles();
                    $data[$ch] = $accs;
                }
            }
            $html = '';
            foreach($data as $ch=>$items){
                $html .= "<h4>$ch</h4>";
                $html .= "<ul>";
                foreach($items as $item){
                    $html .= "<li>$item</li>";
                }
                $html .= "</ul>";
            }
            if(!$html){
                $html = 'no channels.';
            }
            $response->end($html);
        }else if($get['action'] == 'logs'){
            $log = file_get_contents(__DIR__.'/log/server-'.date('YmdH', strtotime('-1 hour')).'.log');
            $log .= file_get_contents(__DIR__.'/log/server-'.date('YmdH').'.log');
            $log = str_replace(PHP_EOL, "<br>", $log);
            if(!$log){
                $log = 'no log data.';
            }
            $response->end($log);
        }
    }

    private function checkParam(&$data, $required=''){
        foreach($required as $i=>$r){
            if(!isset($data[$r])){
                return false;
            }
            if(!trim($data[$r])){
                return false;
            }
        }
        return true;
    }

    private function getAccMaps($fc, $ch_name, $acc_name){
        $accs = $fc->setRootDir('/dev/shm/'.self::ROOT_DIR_PREFIX.$ch_name.'/')->getFiles();
        $data = [];
        foreach($accs as $acc){
            if($acc != $acc_name){
                $acc_map = $fc->setRootDir('/dev/shm/')->get(self::ACC_FD_KEY.$acc);
                if($acc_map) {
                    $acc_map = json_decode($acc_map, 'true');
                    $data[] = $acc_map;
                }
            }
        }
        return $data;
    }

    private function getFds(&$acc_map){
        $fds = [];
        foreach($acc_map as $item){
            $fds[] = $item['fd'];
        }
        return $fds;
    }

    private function broadcast(\swoole_websocket_server $server, $fds, $msg){
        foreach($fds as $fd){
            $server->push($fd, $msg);
        }
    }

    public function message(\swoole_websocket_server $server, \swoole_websocket_frame $frame){
        $data = $frame->data;
        $data = json_decode($data, true);

        if(!isset($data['type'])){
            Log::getInstance('server')->write('missing type.', 'err');
            return;
        }

        if($data['type'] == 'get_accountlist'){
            $required = ['ch_name'];
        } else{
            $required = ['type', 'acc_name', 'ch_name'];
        }

        if(!$this->checkParam($data, $required)){
            Log::getInstance('server')->write('missing required parameters.', 'err');
            return;
        }

        $fc = new FileCache('/dev/shm');
        $type = trim($data['type']);
        $ch_name = trim($data['ch_name']);
        $acc_name = trim($data['acc_name']);
        if($type == 'join_channel'){
            $value = json_encode(['fd' => $frame->fd, 'ch_name' => $ch_name]);
            $res = $this->set($fc, $frame->fd, $acc_name, $value, $ch_name);
            if($res) {
                $msg = ['type'=>'join_channel_ack', 'acc_name'=>$acc_name, 'ch_name'=>$ch_name, 'status'=>'success', 'appkey'=>''];
                $server->push($frame->fd, json_encode($msg));

                $acc_map = $this->getAccMaps($fc, $ch_name, $acc_name);
                $fds = $this->getFds($acc_map);
                $msg = ['type' => 'join_notify', 'acc_name' => $acc_name, 'ch_name' => $ch_name, 'appkey' => ''];
                $this->broadcast($server, $fds, json_encode($msg));
            }else{
                $msg = ['type'=>'join_channel_ack', 'acc_name'=>$acc_name, 'ch_name'=>$ch_name, 'status'=>'fail', 'appkey'=>''];
                $server->push($frame->fd, json_encode($msg));
            }
        }
        else if($type == 'quit_channel'){
            $res = $this->remove($fc, $frame->fd, $acc_name, $ch_name);
            if($res) {
                $acc_map = $this->getAccMaps($fc, $ch_name, $acc_name);
                $fds = $this->getFds($acc_map);
                $msg = ['type' => 'quit_notify', 'acc_name' => $acc_name, 'ch_name' => $ch_name, 'appkey' => ''];
                $this->broadcast($server, $fds, json_encode($msg));

                $msg = ['type'=>'quit_channel_ack', 'acc_name'=>$acc_name, 'ch_name'=>$ch_name, 'status'=>'success', 'appkey'=>''];
                $server->push($frame->fd, json_encode($msg));
            }else{
                $msg = ['type'=>'quit_channel_ack', 'acc_name'=>$acc_name, 'ch_name'=>$ch_name, 'status'=>'fail', 'appkey'=>''];
                $server->push($frame->fd, json_encode($msg));
            }
        }
        else if($type == 'p2p'){
            $receive = trim($data['receive_acc']);
            $acc_map = $fc->setRootDir('/dev/shm/')->get(self::ACC_FD_KEY.$receive);
            $acc_map = json_decode($acc_map, true);
            if($acc_map) {
                $server->push($acc_map['fd'], json_encode($data));
            }
        }
        else if($type == 'broadcast'){
            $acc_map = $this->getAccMaps($fc, $ch_name, $acc_name);
            $fds = $this->getFds($acc_map);
            $this->broadcast($server, $fds, json_encode($data));
        }
        else if($type == 'get_accountlist'){
            $names = $fc->setRootDir('/dev/shm/'.self::ROOT_DIR_PREFIX.$ch_name.'/')->getFiles();
            $msg = ['type'=>'accountlist', 'ch_name'=>$ch_name, 'acc_list'=>$names, 'appkey'=>''];
            $msg = json_encode($msg);
            $server->push($frame->fd, $msg);
        }

    }

    public function close(\swoole_websocket_server $server, $fd){

        $fc = new FileCache('/dev/shm/');
        $map = $fc->get(self::FD_ACC_KEY.$fd);
        if($map) {
            Log::getInstance('server')->write("client fd: $fd closed.", 'debug');
            $map = json_decode($map, true);
            $acc_name = $map['acc_name'];
            $ch_name = $map['ch_name'];
            $msg = ['type' => 'quit_notify', 'acc_name' => $acc_name, 'ch_name' => $ch_name, 'appkey' => ''];
            $acc_map = $this->getAccMaps($fc, $ch_name, $acc_name);
            $fds = $this->getFds($acc_map);

            $this->remove($fc, $fd, $acc_name, $ch_name);

            $this->broadcast($server, $fds, json_encode($msg));
        }
    }

    private function set($fc, $fd, $acc_name, $value, $ch_name){
        $res = $fc->set(self::ACC_FD_KEY.$acc_name, $value);
        $res = $res && $fc->set(self::FD_ACC_KEY.$fd, json_encode(['acc_name' => $acc_name, 'ch_name' => $ch_name]));
        $res = $res && $fc->setRootDir('/dev/shm/'.self::ROOT_DIR_PREFIX.$ch_name.'/')->set($acc_name, 1);
        return $res;
    }

    private function remove($fc, $fd, $acc_name, $ch_name){
        $res = $fc->setRootDir('/dev/shm/')->remove(self::FD_ACC_KEY.$fd);
        //区分断网重连和长时间掉线，如果断网以后立马重连,acc_name不会变，不应该删除ACC_FD_KEY和频道
        $data = $fc->setRootDir('/dev/shm/')->get(self::ACC_FD_KEY.$acc_name);
        $data = json_decode($data, true);
        if($fd == $data['fd']) {
            $res = $res && $fc->setRootDir('/dev/shm/')->remove(self::ACC_FD_KEY . $acc_name);
            $res = $res && $fc->setRootDir('/dev/shm/' . self::ROOT_DIR_PREFIX . $ch_name . '/')->remove($acc_name);
            $fc->removeRootDir();
        }

        return $res;
    }

}

$server = new Server();
$server->run();