<?php
declare(strict_types=1);
use DersRotasi\Pomodoro\TurnIceConfigService;
require dirname(__DIR__).'/vendor/autoload.php';
function turnCheck(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function turnThrows(callable $callback,string $message):void{try{$callback();}catch(RuntimeException $exception){turnCheck($exception->getCode()===503,'TURN yapılandırma hatası 503 dönmeli.');turnCheck(!str_contains($exception->getMessage(),'test-only-secret'),'Secret hata mesajına sızdı.');return;}throw new RuntimeException($message);}
$service=new TurnIceConfigService();$now=1700000000;$secret='test-only-secret';
$urls=['turn:turn.example.test:3478?transport=udp','turn:turn.example.test:3478?transport=tcp','turns:turn.example.test:5349?transport=tcp'];
$config=$service->create('firebase-user',$urls,$secret,$now);
turnCheck($config['expiresAt']===gmdate('c',$now+3600),'TURN expiry ISO-8601 formatında değil.');
turnCheck(count($config['iceServers'])===2&&$config['iceServers'][0]['urls'][0]==='stun:stun.l.google.com:19302','STUN fallback korunmadı.');
$turn=$config['iceServers'][1];turnCheck($turn['urls']===$urls,'Production TURN URLleri korunmadı.');turnCheck(preg_match('/^1700003600:[a-f0-9]{24}$/',$turn['username'])===1,'TURN username expiry formatı hatalı.');
turnCheck(hash_equals(base64_encode(hash_hmac('sha1',$turn['username'],$secret,true)),$turn['credential']),'coturn shared-secret credential hatalı.');
turnThrows(fn()=>$service->create('firebase-user',$urls,'',$now),'Eksik secret endpointi başarısız kılmalı.');
turnThrows(fn()=>$service->create('firebase-user',[],$secret,$now),'Eksik TURN URL endpointi başarısız kılmalı.');
turnCheck(!str_contains((string)json_encode($config),$secret),'Secret response içine sızdı.');
echo "TurnIceConfigTest: OK\n";
