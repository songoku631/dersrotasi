<?php
declare(strict_types=1);
use DersRotasi\Pomodoro\TurnIceConfigService;
require dirname(__DIR__).'/vendor/autoload.php';
function turnCheck(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$service=new TurnIceConfigService();$now=1700000000;$secret='test-only-secret';
$config=$service->create('firebase-user',['turn:turn.example.test:3478?transport=udp','turn:turn.example.test:3478?transport=tcp'],$secret,$now);
turnCheck($config['relay_available']===true&&$config['expires_at']===$now+3600,'TURN config üretilmedi.');
$turn=$config['ice_servers'][1];turnCheck(preg_match('/^1700003600:[a-f0-9]{24}$/',$turn['username'])===1,'TURN username expiry formatı hatalı.');
turnCheck(hash_equals(base64_encode(hash_hmac('sha1',$turn['username'],$secret,true)),$turn['credential']),'coturn shared-secret credential hatalı.');
$missing=$service->create('firebase-user',['turn:turn.example.test:3478'],'',$now);turnCheck($missing['relay_available']===false&&count($missing['ice_servers'])===1&&$missing['expires_at']===null,'Eksik secret güvenli STUN fallback yapmadı.');
turnCheck(!str_contains(var_export($config,true),$secret),'Secret response içine sızdı.');
echo "TurnIceConfigTest: OK\n";
