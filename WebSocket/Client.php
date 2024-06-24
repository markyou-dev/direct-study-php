<?php

/* ----------------------------------------------------------------------------*\
  Websockets using hybi10 frame encoding:
  0                   1                   2                   3
  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
  +-+-+-+-+-------+-+-------------+-------------------------------+
  |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
  |I|S|S|S|  (4)  |A|     (7)     |             (16/63)           |
  |N|V|V|V|       |S|             |   (if payload len==126/127)   |
  | |1|2|3|       |K|             |                               |
  +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
  |     Extended payload length continued, if payload len == 127  |
  + - - - - - - - - - - - - - - - +-------------------------------+
  |                               |Masking-key, if MASK set to 1  |
  +-------------------------------+-------------------------------+
  | Masking-key (continued)       |          Payload Data         |
  +-------------------------------- - - - - - - - - - - - - - - - +
  :                     Payload Data continued ...                :
  + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
  |                     Payload Data continued ...                |
  +---------------------------------------------------------------+
  See: https://tools.ietf.org/rfc/rfc6455.txt
  or:  http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#section-4.2
  \*---------------------------------------------------------------------------- */

class WebSocket
{
    protected $connection;
    private $params = array(
        'host'    => "127.0.0.1",
        'port'    => 80,
        'timeout' => 5,
        'path'    => '/',
        'context' => null,
    );
    
    public function __construct($params)
    {
        foreach ($params as $key => $value) {
            if (isset($value)) $this->params[$key] = $value;
        }
        $header = $this->getHeader();
        $address = "tcp://" . $params['host'] . ':' . $params['port'];
        $flags = STREAM_CLIENT_CONNECT;
        $ctx = isset($context) && $context ? $context : stream_context_create();
        
        // socket init
        $sock = @\stream_socket_client($address, $errno, $errstr, 10, $flags, $ctx);
        stream_set_timeout($sock, $params['timeout']); // set timeout
        
        if ($sock === false) {
            $this->printError("Unable to connect to websocket server: $errstr ($errno)");
        }
        
        if (ftell($sock) === 0) {
            // Request upgrade to websocket
            $rc = fwrite($sock, $header);
            if (!$rc) {
                $this->printError("Unable to send upgrade header to websocket server: $errstr ($errno)");
            }
            
            // Read response into an assotiative array of headers. Fails if upgrade failes.
            $response_header = fread($sock, 1024);
            
            // status code 101 indicates that the WebSocket handshake has completed.
            if (stripos($response_header, ' 101 ') === false || stripos($response_header, 'Sec-WebSocket-Accept: ') === false) {
                $this->printError("Server did not accept to upgrade connection to websocket." . $response_header . E_USER_ERROR);
            }
        }
        
        $this->connection = $sock;
    }
    
    public function getHeader()
    {
        $key = base64_encode(openssl_random_pseudo_bytes(16));
        if (isset($_SERVER['HTTP_USER_AGENT'])) $userAgent = $_SERVER['HTTP_USER_AGENT'];  // user agent
        if (isset($_SERVER['REMOTE_ADDR'])) $origin = $_SERVER['REMOTE_ADDR'];  // origin
        
        $header = "GET " . $this->params['path'] . " HTTP/1.1\r\n";
        $header .= "Host: " . $this->params['host'] . "\r\n";
        $header .= "Cache-Control: no-cache\r\n";
        $header .= "Pragma: no-cache\r\n";
        $header .= "Upgrade: WebSocket\r\n";
        $header .= "Connection: Upgrade\r\n";
        $header .= "Sec-WebSocket-Key: $key\r\n";
        $header .= "Sec-WebSocket-Version: 13\r\n";
        
        if (isset($userAgent) && $userAgent) $header .= "User-Agent: $userAgent\r\n";
        if (isset($origin) && $origin) $header .= "Origin: " . $origin . "\r\n";
        
        $header .= "\r\n";
        
        return $header;
    }
    
    public function send($data)
    {
        $frame = $this->hybi10Encode($data);
        fwrite($this->connection, $frame);
    }
    
    public function receive()
    {
        $payload = $this->receiveFragment();
        return $payload;
    }
    
    public function read($len)
    {
        $data = '';
        $socket = $this->connection;
        
        while (($dataLen = strlen($data)) < $len) {
            $buff = fread($socket, $len - $dataLen);
            $data .= $buff;
        }
        
        return $data;
    }
    
    protected function receiveFragment()
    {
        $data = $this->read(2);
        $payloadLength = $this->getPayloadLength($data);
        $payload = $this->getPayloadData($data, $payloadLength);
        return $payload;
    }
    
    protected static function sprintB($string)
    {
        $return = '';
        $strLen = strlen($string);
        for ($i = 0; $i < $strLen; $i++) {
            $return .= sprintf('%08b', ord($string[$i]));
        }
        
        return $return;
    }
    
    private function getPayloadLength($data)
    {
        $payloadLength = (int)ord($data[1]) & 127; // Bits 1-7 in byte 1
        if ($payloadLength > 125) {
            if ($payloadLength === 126) {
                $data = $this->read(2); // 126: Payload is a 16-bit unsigned int
            } else {
                $data = $this->read(8); // 127: Payload is a 64-bit unsigned int
            }
            $payloadLength = bindec($this->sprintB($data));
        }
        return $payloadLength;
    }
    
    private function getPayloadData($data, $payloadLength)
    {
        // Masking?
        $mask = (bool)(ord($data[1]) >> 7);  // Bit 0 in byte 1
        $payload = '';
        $maskingKey = '';
        
        // Get masking key.
        if ($mask) {
            $maskingKey = $this->read(4);
        }
        
        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payloadLength > 0) {
            $data = $this->read($payloadLength);
            
            if ($mask) {
                // Unmask payload.
                for ($i = 0; $i < $payloadLength; $i++) {
                    $payload .= ($data[$i] ^ $maskingKey[$i % 4]);
                }
            } else {
                $payload = $data;
            }
        }
        
        return $payload;
    }
    
    public function close()
    {
        if ($this->connection) {
            fclose($this->connection);
            $this->connection = null;
        }
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
    
    private function printError($error_msg)
    {
        die($error_msg);
    }
    
}
