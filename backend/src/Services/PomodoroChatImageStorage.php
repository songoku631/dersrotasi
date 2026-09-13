<?php
declare(strict_types=1);
namespace DersRotasi\Services;

use DersRotasi\AI\ImageContentModerator;
use RuntimeException;
use Throwable;

final class PomodoroChatImageStorage
{
    public const REJECTION_MESSAGE = 'Bu görsel sohbet içinde paylaşılamıyor.';
    private const MAX_BYTES = 5_242_880;
    private const MAX_WIDTH = 4096;
    private const MAX_HEIGHT = 4096;
    private const MAX_PIXELS = 16_000_000;
    private const ALLOWED_MIMES = ['image/jpeg','image/png','image/webp'];

    public function __construct(private readonly string $backendRoot, private readonly ImageContentModerator $moderator, private readonly PomodoroChatObjectStorage $objects, private readonly mixed $uploadVerifier = null, private readonly mixed $testEncoder = null) {}

    /** @return array{path:string,mime:string,width:int,height:int} */
    public function store(array $file, int $roomId): array
    {
        $staged = null;
        try {
            $source = $this->validateUpload($file);
            $info = @getimagesize($source);
            if (!is_array($info)) throw new RuntimeException(self::REJECTION_MESSAGE,422);
            $width=(int)$info[0]; $height=(int)$info[1];
            if($width<1||$height<1||$width>self::MAX_WIDTH||$height>self::MAX_HEIGHT||$width*$height>self::MAX_PIXELS) throw new RuntimeException(self::REJECTION_MESSAGE,422);
            $mime=class_exists(\finfo::class)?((new \finfo(FILEINFO_MIME_TYPE))->file($source)?:''):(string)($info['mime']??'');
            if(!in_array($mime,self::ALLOWED_MIMES,true)||($info['mime']??'')!==$mime) throw new RuntimeException(self::REJECTION_MESSAGE,422);
            $staged=$this->reencode($source,$mime,$width,$height); $bytes=file_get_contents($staged);
            if(!is_string($bytes)||$bytes==='') throw new RuntimeException(self::REJECTION_MESSAGE,422);
            try{$result=$this->moderator->inspectImage('image/jpeg',$bytes);}catch(Throwable $e){error_log('[POMODORO_CHAT_IMAGE] event=moderation_unavailable');throw new RuntimeException(self::REJECTION_MESSAGE,503,$e);}
            if(($result['flagged']??true)===true){error_log('[POMODORO_CHAT_IMAGE] event=moderation_rejected');throw new RuntimeException(self::REJECTION_MESSAGE,422);}
            $key=PomodoroChatObjectKey::generate($roomId);
            $this->objects->put($key,$staged,'image/jpeg');
            return ['path'=>$key,'mime'=>'image/jpeg','width'=>$width,'height'=>$height];
        } finally { if($staged!==null&&is_file($staged))@unlink($staged); }
    }

    public function discard(string $path): void
    {
        try{$this->objects->delete(PomodoroChatObjectKey::assert($path));}catch(Throwable){}
    }

    private function validateUpload(array $file): string
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException(self::REJECTION_MESSAGE,422);
        $size=(int)($file['size']??0);$path=(string)($file['tmp_name']??'');$verify=$this->uploadVerifier??'is_uploaded_file';
        if($size<1||$size>self::MAX_BYTES||!is_callable($verify)||!$verify($path))throw new RuntimeException(self::REJECTION_MESSAGE,422);
        return $path;
    }

    private function reencode(string $path,string $mime,int $width,int $height): string
    {
        if(is_callable($this->testEncoder))return ($this->testEncoder)($path);
        $decoder=match($mime){'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp',default=>null};
        if($decoder===null||!function_exists($decoder))throw new RuntimeException(self::REJECTION_MESSAGE,503);
        $source=@$decoder($path);if(!$source instanceof \GdImage)throw new RuntimeException(self::REJECTION_MESSAGE,422);
        $canvas=imagecreatetruecolor($width,$height);if(!$canvas instanceof \GdImage){imagedestroy($source);throw new RuntimeException(self::REJECTION_MESSAGE,500);}
        imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));imagecopy($canvas,$source,0,0,0,0,$width,$height);
        $directory=rtrim($this->backendRoot,'/\\').'/storage/pomodoro-chat-staging';if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory)){imagedestroy($source);imagedestroy($canvas);throw new RuntimeException(self::REJECTION_MESSAGE,500);}
        $staged=tempnam($directory,'chat-');$ok=$staged!==false&&imagejpeg($canvas,$staged,88);imagedestroy($source);imagedestroy($canvas);
        if(!$ok||$staged===false)throw new RuntimeException(self::REJECTION_MESSAGE,500);return $staged;
    }
}
