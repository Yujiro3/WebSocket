PHPのWebSocketサンプル
======================
libevent2を利用したWebSocket

利用方法
------

### libevent2モジュールのインストール ###
    
    $ wget http://jaist.dl.sourceforge.net/project/levent/libevent/libevent-2.0/libevent-2.0.21-stable.tar.gz
    $ tar xzvf libevent-2.0.21-stable.tar.gz
    $ cd libevent-2.0.21-stable
    $ ./configure
    $ make
    $ sudo make install

### php eventモジュールのインストール ###
    
    $ wget http://pecl.php.net/get/event-1.7.2.tgz
    $ tar xzvf event-1.7.2.tgz
    $ cd event-1.7.2
    $ phpize
    $ ./configure
    $ make
    $ sudo -s
    # make install
    # cd /etc/php5/mods-available
    # echo extension=event.so > event.ini
    # cd /etc/php5/conf.d
    # ln -s ../mods-available/event.ini ./20-event.ini
    
### WebSocketサーバの起動 ###
    
    $ chmod a+x ./websock.php
    $ ./websock.php
    
    
### ws待ち受けポート ###

    $ netstat -antu
     
    Proto Recv-Q Send-Q Local Address           Foreign Address         State
    tcp        0      0 0.0.0.0:8888            0.0.0.0:*               LISTEN
    
    
    

ライセンス
----------
Copyright &copy; 2013 Yujiro Takahashi  
Licensed under the [MIT License][MIT].  
Distributed under the [MIT License][MIT].  

[MIT]: http://www.opensource.org/licenses/mit-license.php
