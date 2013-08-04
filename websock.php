#!/usr/bin/env php
<?php
$bevs = $users = array();

/**
 * イベントベースの生成
 */
$base = new EventBase();

/**
 * クライアント接続の生成
 */
$listener = new EventListener(
    $base,
    function ($listener, $fd, $address, $base) use (&$bevs, &$users) {
        $key        = $address[0].':'.$address[1];
        $bevs[$key] = new EventBufferEvent($base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);
        $user       = &$users[$key];

        $bevs[$key]->setCallbacks(
            /**
             * クライアント読込コールバック関数
             *
             * @param EventBufferEvent $dev 
             * @param string           $address 
             */
            function ($bev, $address) use (&$bevs, &$user) {
                static $handshake;
                
                if (empty($handshake)) {
                    /**
                     * ハンドシェイクの確立
                     */
                    $buff = $bev->read(4096);
                    if (preg_match('/Sec-WebSocket-Key: ([^\s]+)\r\n/', $buff, $matches)) {
                        $key = $matches[1];
                    }

                    /* 認証キー生成 */
                    $accept = $key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
                    $accept = base64_encode(sha1($accept, true));
            
                    $header = "HTTP/1.1 101 Switching Protocols\r\n".
                              "Upgrade: WebSocket\r\n".
                              "Connection: Upgrade\r\n".
                              "Sec-WebSocket-Accept: {$accept}\r\n".
                              "\r\n";
                    $bev->write($header);
                    $handshake = true;
                } else {
                    $frame = $bev->read(4096);
                    $msg   = json_decode(decode($frame), true);
                    $user  = $msg['user'];

                    foreach ($bevs as $key => &$cev) {
                        if ($key != $address) {
                            $cev->write(encode(json_encode($msg)));
                        }
                    }
                }
            },
            NULL,
            /**
             * イベントコールバック関数
             *
             * @param EventBufferEvent $dev 
             * @param integer          $events 
             * @param string           $address 
             */
            function ($bev, $events, $address) use (&$bevs, &$user) {
                if ($events & \EventBufferEvent::ERROR) {
                    throw new \Exception('Error from bufferevent');
                }
                if ($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR)) {
                    $bev->free();
                    $bevs[$address] = null;
                    unset($bevs[$address]);
                    foreach ($bevs as $key => &$cev) {
                        $msg = json_encode(array(
                            'type' => 'out',
                            'user' => $user
                        ));
                        $cev->write(encode($msg));
                    }

                }
            },
            $key
        );

        if (!$bevs[$key]->enable(\Event::READ)) {
            throw new \Exception('Failed to enable READ');
        }
    }, 
    $base,
    EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
    -1,
    '0.0.0.0:8888'
);

/**
 * エラーコールバック設定
 */
$listener->setErrorCallback(function ($listener, $base) {
    fprintf(
        STDERR, 
        "Got an error %d (%s) on the listener. "
        ."Shutting down.\n",
        EventUtil::getLastSocketErrno(),
        EventUtil::getLastSocketError()
    );
    $base->exit(NULL);
});

/**
 * ループスタート
 */
$base->dispatch();


/**
 * メッセージのデコード
 *
 * @link http://d.hatena.ne.jp/uuwan/20130121/p1
 * @param string $frame
 * @return string
 */
function decode($frame) {
    /* データ本体のサイズ分類を取得 */
    $length = ord($frame[1]) & 127;

    /* データ本体とマスクの取得 */        
    if ($length == 126) {               // medium message
        $masks   = substr($frame, 4, 4);
        $payload = substr($frame, 8);
    } elseif ($length == 127) {         // large message
        $masks   = substr($frame, 10, 4);
        $payload = substr($frame, 14);
    } else {                            // small message
        $masks   = substr($frame, 2, 4);
        $payload = substr($frame, 6);
    }
    
    /* マスクの解除 */
    $length = strlen($payload);
    $string   = '';
    for ($pos=0; $pos<$length; $pos++) {
        $string .= $payload[$pos] ^ $masks[$pos % 4];
    }
    
    return $string;
}

/**
 * メッセージのエンコード
 *
 * @link http://d.hatena.ne.jp/uuwan/20130201/p1
 * @param string $string
 * @return string
 */
function encode($message) {
    $ftoo    = 0x81; // 1000 0001 先頭8bit
    $mask    = 0x00; // *000 0000 マスクフラグ
    $length  = strlen($message);
    $payload = '';

    if ($length < 126) {
        $mplen = $mask | $length;
    } elseif ($length <= 65536) {
        $mplen = $mask | 126;

        $octet = $length;
        while ($octet) {
            $payload = chr(0xFF & $octet) . $payload;
            $octet   = $octet >> 8;
        }

        while (strlen($payload) < 2) {
            $payload = chr(0) . $payload;
        }
    } else {
        $mplen = $mask | 127;

        $octet = $length;
        while ($octet) {
            $payload = chr(0xFF & $octet) . $payload;
            $octet   = $octet >> 8;
        }

        while (strlen($payload) < 8) {
            $payload = chr(0) . $payload;
        }
    }

    return chr($ftoo) . chr($mplen) . $payload . $message;
}
