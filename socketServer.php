<?php
/**
 * Created by PhpStorm.
 * User: 25754
 * Date: 2019/4/23
 * Time: 14:13
 */

class socketServer
{

    const LISTEN_SOCKET_NUM = 9;
    const LOG_PATH = "./log/";
    private $_ip = "127.0.0.1";
    private $_port = 1238;
    private $_socketPool = array();
    private $_master = null;

    public function __construct()
    {
        $this->initSocket();
    }

    private function initSocket()
    {
        try {
            //创建socket套接字
            $this->_master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            // 设置IP和端口重用,在重启服务器后能重新使用此端口;
            socket_set_option($this->_master, SOL_SOCKET, SO_REUSEADDR, 1);
            //绑定地址与端口
            socket_bind($this->_master, $this->_ip, $this->_port);
            //listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
            socket_listen($this->_master, self::LISTEN_SOCKET_NUM);
        } catch (Exception $e) {
            $this->debug(array("code: " . $e->getCode() . ", message: " . $e->getMessage()));
        }
        //将socket保存到socket池中
        $this->_socketPool[0] = array('resource' => $this->_master);
        $pid = getmypid();
        $this->debug(array("server: {$this->_master} started,pid: {$pid}"));
        while (true) {
            try {
                $this->run();
            } catch (Exception $e) {
                $this->debug(array("code: " . $e->getCode() . ", message: " . $e->getMessage()));
            }
        }
    }

    private function run()
    {
        $write = $except = NULL;
        $sockets = array_column($this->_socketPool, 'resource');
        $read_num = socket_select($sockets, $write, $except, NULL);
        if (false === $read_num) {
            $this->debug(array('socket_select_error', $err_code = socket_last_error(), socket_strerror($err_code)));
            return;
        }
        foreach ($sockets as $socket) {
            if ($socket == $this->_master) {
                $client = socket_accept($this->_master);
                if ($client === false) {
                    $this->debug(['socket_accept_error', $err_code = socket_last_error(), socket_strerror($err_code)]);
                    continue;
                }
                //连接
                $this->connection($client);
            } else {
                //接受数据
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                if ($bytes == 0) {
                    $recv_msg = $this->disconnection($socket);
                } else {
                    if ($this->_socketPool[(int)$socket]['handShake'] == false) {
                        $this->handShake($socket, $buffer);
                        continue;
                    } else {
                        $recv_msg = $this->parse($buffer);
                    }
                }
                $msg = $this->doEvents($socket, $recv_msg);
                echo($msg);
                socket_getpeername ( $socket  , $address ,$port );
                $this->debug(array(
                    'send_success',
                    json_encode($recv_msg),
                    $address,
                    $port
                ));
                $this->broadcast($msg);
            }
        }
    }

    /**
     * 数据广播
     * @param $data
     */
    private function broadcast($data)
    {
        foreach ($this->_socketPool as $socket) {
            if ($socket['resource'] == $this->_master) {
                continue;
            }
            socket_write($socket['resource'], $data, strlen($data));
        }
    }

    /**
     * 业务处理
     * @param $socket
     * @param $recv_msg
     * @return string
     */
    private function doEvents($socket, $recv_msg)
    {
        $msg_type = $recv_msg['type'];
        $msg_content = $recv_msg['msg'];
        $response = [];
        switch ($msg_type) {
            case 'login':
                $this->_socketPool[(int)$socket]['userInfo'] = array("username" => $msg_content, 'headerimg' => $recv_msg['headerimg'], "login_time" => date("h:i"));
                // 取得最新的名字记录
                $user_list = array_column($this->_socketPool, 'userInfo');
                $response['type'] = 'login';
                $response['msg'] = $msg_content;
                $response['user_list'] = $user_list;
                break;
            case 'logout':
                $user_list = array_column($this->_socketPool, 'userInfo');
                $response['type'] = 'logout';
                $response['user_list'] = $user_list;
                if ($nowUserSocket = @$this->_socketPool[(int)$socket]) {
                    $response['msg'] = $nowUserSocket['userInfo']['username'];
                }else{
                    $response['msg'] = '';
                }
                break;
            case 'user':
                $userInfo = $this->_socketPool[(int)$socket]['userInfo'];
                $response['type'] = 'user';
                $response['from'] = $userInfo['username'];
                $response['msg'] = $msg_content;
                $response['headerimg'] = $userInfo['headerimg'];
                break;
        }

        return $this->frame(json_encode($response));
    }

    /**
     * socket握手
     * @param $socket
     * @param $buffer
     * @return bool
     */
    public function handShake($socket, $buffer)
    {
        $acceptKey = $this->encry($buffer);
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: " . $acceptKey . "\r\n\r\n";

        // 写入socket
        socket_write($socket, $upgrade, strlen($upgrade));
        // 标记握手已经成功，下次接受数据采用数据帧格式
        $this->_socketPool[(int)$socket]['handShake'] = true;
        socket_getpeername ( $socket  , $address ,$port );
        $this->debug(array(
            'hand_shake_success',
            $socket,
            $address,
            $port
        ));
        //发送消息通知客户端握手成功
        $msg = array('type' => 'handShake', 'msg' => '握手成功');
        $msg = $this->frame(json_encode($msg));
        socket_write($socket, $msg, strlen($msg));
        return true;
    }

    /**
     * 帧数据封装
     * @param $msg
     * @return string
     */
    private function frame($msg)
    {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }
        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;
        $data = implode('', $frame);
        return pack("H*", $data);
    }

    /**
     * 接受数据解析
     * @param $buffer
     * @return mixed
     */
    private function parse($buffer)
    {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return json_decode($decoded, true);
    }

    // 提取 Sec-WebSocket-Key 信息
    private function getKey($req)
    {
        $key = null;
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $req, $match)) {
            $key = $match[1];
        }
        return $key;
    }

    //加密 Sec-WebSocket-Key
    private function encry($req)
    {
        $key = $this->getKey($req);
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * 连接socket
     * @param $client
     */
    public function connection($client)
    {
        socket_getpeername ( $client  , $address ,$port );
        $info = array(
            'resource' => $client,
            'userInfo' => '',
            'handShake' => false,
            'ip' => $address,
            'port' => $port,
        );
        $this->_socketPool[(int)$client] = $info;
        $this->debug(array_merge(['socket_connect'], $info));
    }

    /**
     * 断开连接
     * @param $socket
     * @return array
     */
    public function disconnection($socket)
    {
        $recv_msg = array(
            'type' => 'logout',
            'msg' => @$this->_socketPool[(int)$socket]['username'],
        );
        unset($this->_socketPool[(int)$socket]);
        return $recv_msg;
    }

    /**
     * 日志
     * @param array $info
     */
    private function debug(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);
        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}

new socketServer();