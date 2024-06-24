<?php
$server = new WebSocketServer("0.0.0.0", 9211);
$server->run();

/**
 * 웹소켓서버
 * Class WebSocketServer
 */
class WebSocketServer
{
    private $host, $port, $server, $masterId;
    private $clients, $write, $except;
    private $sockets;
    
    public function __construct($host, $port)
    {
        error_reporting(E_ALL);
        set_time_limit(0);
        ob_implicit_flush();
        
        $this->host = $host ? $host : "0.0.0.0";
        $this->port = $port;
        $this->init($host, $port, 20);
    }
    
    function init($host, $port, $listenCount = 20)
    {
        $this->server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("socket_create() failed");
        socket_set_option($this->server, SOL_SOCKET, SO_REUSEADDR, 1) or die("socket_option() failed");
        socket_bind($this->server, $host, $port) or die("socket_bind() failed");
        socket_listen($this->server, $listenCount) or die("socket_listen() failed");
        $this->console("Server Started : " . date('Y-m-d H:i:s') . "\n");
        $this->console("Listening on   : " . $host . " port " . $port . "\n\n");
        $this->masterId = uniqid();
        $this->sockets[$this->masterId] = $this->server;
    }
    
    private function connect($socket)
    {
        $clientId = uniqid();
        $client = new ClientSocket($clientId, $socket);
        $this->clients[$clientId] = $client;
        $this->sockets[$clientId] = $socket;
        $this->console("Client " . $clientId . " : CONNECTED!\n\n");
    }
    
    private function disconnect($clientId)
    {
        if (!empty($this->clients[$clientId])) {
            socket_close($this->sockets[$clientId]);
            unset($this->clients[$clientId]);
            unset($this->sockets[$clientId]);
            $this->console("Client " . $clientId . " : DISCONNECTED!\n\n");
        }
    }
    
    private function send($client, $msg)
    {
        $msg = json_encode(array(
            'callback_id' => uniqid(),
            'event'       => 'notifyCallback',
            'is_success'  => true,
            'data'        => array('test' => 1),
            'error_msg'   => null,
        ));
        $this->console("send Data : " . print_r($msg, true) . "\n");
        $data = $this->hybi10Encode($msg);
        $this->console("encode Data...\n");
        socket_write($client->socket, $data, strlen($data));
    }
    
    private function handshake($client, $buffer)
    {
        $this->console("Requesting handshake...\n");
        $this->console($buffer);
        list($resource, $host, $u, $c, $key, $protocol, $version, $origin, $data) = $this->getHeaders($buffer);
        $this->console("\nHandshaking...\n");
        
        $acceptkey = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        $upgrade = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $acceptkey\r\n";
        socket_write($client->socket, $upgrade, strlen($upgrade));
        $client->handshake = true;
        $this->console($upgrade);
        $this->console("\nDone handshaking...\n");
        return true;
    }
    
    public function run()
    {
        while (true) {
            $changed = $this->sockets;
            socket_select($changed, $this->write, $this->except, NULL);
            foreach ($changed AS $idx => $socket) {
                if ($socket == $this->server) {
                    $client = socket_accept($this->server);
                    if ($client < 0) {
                        $this->console("socket_accept() failed\n");
                        continue;
                    } else {
                        $this->connect($client);
                    }
                } else {
                    $bytes = @socket_recv($socket, $buffer, 2048, 0);
                    if ($bytes == 0) {
                        $this->disconnect($idx);
                    } else {
                        $client = $this->getClient($idx);
                        if (!$client->handshake) {
                            $this->handshake($client, $buffer);
                        } else {
                            $this->send($client, $buffer);
                        }
                    }
                }
            }
        }
    }
    
    private function getHeaders($req)
    {
        $r = $h = $u = $c = $key = $protocol = $version = $o = $data = null;
        if (preg_match("/GET (.*) HTTP/", $req, $match)) {
            $r = $match[1];
        }
        if (preg_match("/Host: (.*)\r\n/", $req, $match)) {
            $h = $match[1];
        }
        if (preg_match("/Upgrade: (.*)\r\n/", $req, $match)) {
            $u = $match[1];
        }
        if (preg_match("/Connection: (.*)\r\n/", $req, $match)) {
            $c = $match[1];
        }
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $req, $match)) {
            $key = $match[1];
        }
        if (preg_match("/Sec-WebSocket-Protocol: (.*)\r\n/", $req, $match)) {
            $protocol = $match[1];
        }
        if (preg_match("/Sec-WebSocket-Version: (.*)\r\n/", $req, $match)) {
            $version = $match[1];
        }
        if (preg_match("/Origin: (.*)\r\n/", $req, $match)) {
            $o = $match[1];
        }
        if (preg_match("/\r\n(.*?)\$/", $req, $match)) {
            $data = $match[1];
        }
        return array(
            $r,
            $h,
            $u,
            $c,
            $key,
            $protocol,
            $version,
            $o,
            $data
        );
    }
    
    private function getClient($clientId)
    {
        return $this->clients[$clientId];
    }
    
    private function hybi10Decode($data)
    {
        $bytes = $data;
        $dataLength = '';
        $mask = '';
        $coded_data = '';
        $decodedData = '';
        $secondByte = sprintf('%08b', ord($bytes[1]));
        $masked = ($secondByte[0] == '1') ? true : false;
        $dataLength = ($masked === true) ? ord($bytes[1]) & 127 : ord($bytes[1]);
        
        if ($masked === true) {
            if ($dataLength === 126) {
                $mask = substr($bytes, 4, 4);
                $coded_data = substr($bytes, 8);
            } elseif ($dataLength === 127) {
                $mask = substr($bytes, 10, 4);
                $coded_data = substr($bytes, 14);
            } else {
                $mask = substr($bytes, 2, 4);
                $coded_data = substr($bytes, 6);
            }
            for ($i = 0; $i < strlen($coded_data); $i++) $decodedData .= $coded_data[$i] ^ $mask[$i % 4];
        } else {
            if ($dataLength === 126) $decodedData = substr($bytes, 4); elseif ($dataLength === 127) $decodedData = substr($bytes, 10);
            else
                $decodedData = substr($bytes, 2);
        }
        
        return $decodedData;
    }
    
    private function hybi10Encode($payload, $type = 'text', $masked = true)
    {
        $frameHead = array();
        $frame = '';
        $payloadLength = strlen($payload);
        
        switch ($type) {
            case 'text' :
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            
            case 'close' :
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            
            case 'ping' :
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            
            case 'pong' :
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }
        
        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 255 : 127;
            for ($i = 0; $i < 8; $i++) $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            
            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                $this->close();
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else
            $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
        
        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) $frameHead[$i] = chr($frameHead[$i]);
        
        if ($masked === true) {
            // generate a random mask:
            $mask = array();
            for ($i = 0; $i < 4; $i++) $mask[$i] = chr(rand(0, 255));
            
            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);
        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        
        return $frame;
    }
    
    private function wrap($msg = "")
    {
        return chr(0) . $msg . chr(255);
    }
    
    private function unwrap($msg = "")
    {
        return substr($msg, 1, strlen($msg) - 2);
    }
    
    private function console($msg = "")
    {
        print_r($msg);
    }
}

/**
 * 클라이언트 소켓정보
 * Class ClientSocket
 */
class ClientSocket
{
    public $id;
    public $socket;
    public $handshake;
    
    public function __construct($id, $socket)
    {
        $this->id = $id;
        $this->socket = $socket;
    }
}

?>
