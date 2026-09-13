<?php
declare(strict_types=1);
use DersRotasi\AI\ImageContentModerator;
use DersRotasi\Services\PomodoroChatImageStorage;
use DersRotasi\Services\PomodoroChatObjectKey;
use DersRotasi\Services\PomodoroChatObjectStorage;
require dirname(__DIR__).'/vendor/autoload.php';
function imageCheck(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function imageReject(callable $call,int $code):void{try{$call();}catch(RuntimeException $e){imageCheck($e->getCode()===$code,'Yanlış hata kodu.');return;}throw new RuntimeException('Görsel reddedilmedi.');}
$root=sys_get_temp_dir().'/dersrotasi-chat-image-'.bin2hex(random_bytes(6));mkdir($root,0750,true);
$allow=new class implements ImageContentModerator{public function inspectImage(string $mimeType,string $bytes):array{return ['flagged'=>false,'categories'=>[]];}};
$flag=new class implements ImageContentModerator{public function inspectImage(string $mimeType,string $bytes):array{return ['flagged'=>true,'categories'=>['sexual']];}};
$fail=new class implements ImageContentModerator{public function inspectImage(string $mimeType,string $bytes):array{throw new RuntimeException('provider detail');}};
$objects=new class implements PomodoroChatObjectStorage{public array $items=[];public int $puts=0;public function put(string $key,string $sourcePath,string $mime):void{$this->puts++;$this->items[$key]=file_get_contents($sourcePath);}public function get(string $key):string{if(!isset($this->items[$key]))throw new RuntimeException('missing',404);return $this->items[$key];}public function delete(string $key):void{unset($this->items[$key]);}};
$verify=static fn(string $path):bool=>is_file($path);$encode=static function(string $path):string{$target=tempnam(sys_get_temp_dir(),'chat-image-test-');copy($path,$target);return $target;};$service=new PomodoroChatImageStorage($root,$allow,$objects,$verify,$encode);
$png=$root.'/question.jpg';file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$stored=$service->store(['error'=>UPLOAD_ERR_OK,'size'=>filesize($png),'tmp_name'=>$png,'name'=>'fake.jpg'],42);imageCheck($stored['mime']==='image/jpeg'&&preg_match('#^pomodoro/42/[a-f0-9]{48}\.jpg$#',$stored['path'])===1,'Güvenli object key üretilmedi.');
$svg=$root.'/bad.svg';file_put_contents($svg,'<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>');imageReject(fn()=>$service->store(['error'=>0,'size'=>filesize($svg),'tmp_name'=>$svg],42),422);
$text=$root.'/fake.png';file_put_contents($text,'not an image');imageReject(fn()=>$service->store(['error'=>0,'size'=>filesize($text),'tmp_name'=>$text],42),422);imageReject(fn()=>$service->store(['error'=>0,'size'=>5_242_881,'tmp_name'=>$text],42),422);
$before=$objects->puts;imageReject(fn()=>(new PomodoroChatImageStorage($root,$flag,$objects,$verify,$encode))->store(['error'=>0,'size'=>filesize($png),'tmp_name'=>$png],42),422);imageCheck($objects->puts===$before,'Moderation reddinde upload yapıldı.');
imageReject(fn()=>(new PomodoroChatImageStorage($root,$fail,$objects,$verify,$encode))->store(['error'=>0,'size'=>filesize($png),'tmp_name'=>$png],42),503);imageCheck($objects->puts===$before,'Moderation hatasında upload yapıldı.');
imageReject(fn()=>PomodoroChatObjectKey::assert('../../outside.jpg'),422);imageReject(fn()=>PomodoroChatObjectKey::assert('pomodoro/42/../../x.jpg'),422);
$service->discard('../../outside.jpg');imageCheck(isset($objects->items[$stored['path']]),'Path traversal discard tarafından kullanıldı.');$service->discard($stored['path']);imageCheck(!isset($objects->items[$stored['path']]),'Orphan temizlenmedi.');
echo "PomodoroChatImageSecurityTest: OK\n";
