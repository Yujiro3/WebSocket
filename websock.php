#!/usr/bin/env php
<?php
$bevs = $users = array();

/**
 * �C�x���g�x�[�X�̐���
 */
$base = new EventBase();

/**
 * �N���C�A���g�ڑ��̐���
 */
$listener = new EventListener(
    $base,
    function ($listener, $fd, $address, $base) use (&$bevs, &$users) {
        $key        = $address[0].':'.$address[1];
        $bevs[$key] = new EventBufferEvent($base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);
        $user       = &$users[$key];

        $bevs[$key]->setCallbacks(
            /**
             * �N���C�A���g�Ǎ��R�[���o�b�N�֐�
             *
             * @param EventBufferEvent $dev 
             * @param string           $address 
             */
            function ($bev, $address) use (&$bevs, &$user) {
                static $handshake;
                
                if (empty($handshake)) {
                    /**
                     * �n���h�V�F�C�N�̊m��
                     */
                    $buff = $bev->read(4096);
                    if (preg_match('/Sec-WebSocket-Key: ([^\s]+)\r\n/', $buff, $matches)) {
                        $key = $matches[1];
                    }

                    /* �F�؃L�[���� */
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
             * �C�x���g�R�[���o�b�N�֐�
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
 * �G���[�R�[���o�b�N�ݒ�
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
 * ���[�v�X�^�[�g
 */
$base->dispatch();


/**
 * ���b�Z�[�W�̃f�R�[�h
 *
 * @link http://d.hatena.ne.jp/uuwan/20130121/p1
 * @param string $frame
 * @return string
 */
function decode($frame) {
    /* �f�[�^�{�̂̃T�C�Y���ނ��擾 */
    $length = ord($frame[1]) & 127;

    /* �f�[�^�{�̂ƃ}�X�N�̎擾 */        
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
    
    /* �}�X�N�̉��� */
    $length = strlen($payload);
    $string   = '';
    for ($pos=0; $pos<$length; $pos++) {
        $string .= $payload[$pos] ^ $masks[$pos % 4];
    }
    
    return $string;
}

/**
 * ���b�Z�[�W�̃G���R�[�h
 *
 * @link http://d.hatena.ne.jp/uuwan/20130201/p1
 * @param string $string
 * @return string
 */
function encode($message) {
    $ftoo    = 0x81; // 1000 0001 �擪8bit
    $mask    = 0x00; // *000 0000 �}�X�N�t���O
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
