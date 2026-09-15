<?php
declare(strict_types=1);

use DersRotasi\Config\Env;
use DersRotasi\Database\Connection;
use DersRotasi\Repositories\ProfileRepository;
use Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env=new Env($_ENV);
if($env->appEnv()!=='local'||$env->dbHost()!=='127.0.0.1') throw new RuntimeException('Local database required.');
$pdo=Connection::make($env);
$repo=new ProfileRepository($pdo);
function profileCheck(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
$uid='profile-save-test-'.bin2hex(random_bytes(6));
$pdo->beginTransaction();
try {
    $repo->save($uid,['username'=>'test_'.bin2hex(random_bytes(5))]);
    $payload=['department'=>'Bilgisayar Mühendisliği','grade_level'=>'2','target_department'=>'Tıp','target_rank'=>15000,'score_type'=>'esit_agirlik','daily_study_hours'=>'24,0','profile_visibility'=>'public','birth_year'=>2005,'birth_year_public'=>1];
    $saved=$repo->save($uid,$payload);
    $loaded=$repo->findByUid($uid);
    foreach($payload as $key=>$value) {
        if($key==='daily_study_hours') { profileCheck((float)$loaded[$key]===24.0,'Comma decimal did not persist.'); continue; }
        profileCheck((string)$loaded[$key]===(string)$value,'Field did not persist: '.$key);
        profileCheck($saved[$key]===$loaded[$key],'Response differs from stored value: '.$key);
    }
    $saved=$repo->save($uid,['daily_study_hours'=>0,'profile_visibility'=>'private','birth_year_public'=>0]);
    profileCheck((float)$saved['daily_study_hours']===0.0 && (int)$saved['birth_year_public']===0 && $saved['profile_visibility']==='private','Zero hours or privacy toggle not saved.');
    profileCheck($saved['department']===$payload['department'],'Partial update lost existing fields.');
    foreach([['daily_study_hours'=>'25'],['daily_study_hours'=>'abc'],['daily_study_hours'=>'-1'],['target_rank'=>'24,0'],['score_type'=>'invalid'],['profile_visibility'=>'invalid']] as $invalid) {
        try { $repo->save($uid,$invalid); throw new RuntimeException('Invalid input accepted.'); }
        catch(RuntimeException $e) { profileCheck($e->getCode()===422,'Invalid input must yield 422.'); }
    }
    echo "ProfileSaveTest: OK (insert, update, reread, all requested fields, locale, privacy, 422 validation).\n";
} finally { $pdo->rollBack(); }
