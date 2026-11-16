<?php
declare(strict_types=1);
/**
 * PRAAN trusted/bundled module registry.
 * IMPORTANT: manifests contain DATA ONLY. No user-supplied PHP or arbitrary SQL is loaded.
 * A new module requires a reviewed code release and explicit registration in praan_module_ids().
 */
function praan_module_ids(): array {
    return ['volunteer_core','identity_core','updates_core','reports','import','directory','media','documents'];
}
function praan_module_catalog(): array {
    static $catalog=null;
    if ($catalog!==null) return $catalog;
    $catalog=[];
    foreach (praan_module_ids() as $id) {
        $path=__DIR__.'/../modules/'.$id.'/module.json';
        if(!is_file($path)||is_link($path))throw new RuntimeException('বিশ্বস্ত Module Manifest পাওয়া যায়নি: '.$id);
        $decoded=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)||($decoded['id']??'')!==$id||!preg_match('/^[a-z_]{3,40}$/D',$id))throw new RuntimeException('Module manifest ID সঠিক নয়।');
        foreach (['title','description','version'] as $key) if(!is_string($decoded[$key]??null))throw new RuntimeException('Module manifest fields invalid: '.$id);
        foreach(['routes','requires','features','settings'] as $key) if(!is_array($decoded[$key]??null))throw new RuntimeException('Module manifest schema invalid: '.$id);
        if(!is_bool($decoded['core']??null)||!is_bool($decoded['default_enabled']??null))throw new RuntimeException('Module manifest bool invalid: '.$id);
        $catalog[$id]=$decoded;
    }
    foreach($catalog as $module){
        foreach($module['requires'] as $dep)if(!is_string($dep)||!isset($catalog[$dep])||$dep===$module['id'])throw new RuntimeException('Module dependency invalid');
        foreach($module['routes'] as $route)if(!is_string($route)||!preg_match('/^[a-z_]{2,55}$/D',$route))throw new RuntimeException('Module route invalid');
        foreach($module['features'] as $key=>$def)if(!preg_match('/^[a-z_]{2,45}$/D',(string)$key)||!is_array($def)||!is_bool($def['default']??null))throw new RuntimeException('Module flag invalid');
    }
    return $catalog;
}
function praan_module_migrate(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS praan_modules (
 id TEXT PRIMARY KEY, enabled INTEGER NOT NULL CHECK(enabled IN (0,1)),
 mode TEXT NOT NULL DEFAULT 'production' CHECK(mode IN ('production','sandbox')),
 installed_version TEXT NOT NULL, installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INTEGER
);
CREATE TABLE IF NOT EXISTS praan_module_settings (
 module_id TEXT NOT NULL, option_key TEXT NOT NULL, option_value TEXT NOT NULL,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(module_id,option_key), FOREIGN KEY(module_id) REFERENCES praan_modules(id)
);
CREATE TABLE IF NOT EXISTS praan_feature_flags (
 module_id TEXT NOT NULL, flag_key TEXT NOT NULL,
 enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN(0,1)),
 audience TEXT NOT NULL DEFAULT 'all' CHECK(audience IN ('all','admins','super_admin')),
 rollout INTEGER NOT NULL DEFAULT 100 CHECK(rollout BETWEEN 0 AND 100),
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(module_id,flag_key), FOREIGN KEY(module_id) REFERENCES praan_modules(id)
);
CREATE TABLE IF NOT EXISTS praan_module_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, module_id TEXT NOT NULL,
 action TEXT NOT NULL, actor_id INTEGER, details TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_praan_module_events ON praan_module_events(module_id,id DESC);
SQL);
    $insert=$pdo->prepare('INSERT OR IGNORE INTO praan_modules(id,enabled,installed_version) VALUES(?,?,?)');
    $oldSetting=$pdo->prepare('SELECT value FROM settings WHERE key=?');
    $flagInsert=$pdo->prepare('INSERT OR IGNORE INTO praan_feature_flags(module_id,flag_key,enabled) VALUES(?,?,?)');
    $optionInsert=$pdo->prepare('INSERT OR IGNORE INTO praan_module_settings(module_id,option_key,option_value) VALUES(?,?,?)');
    foreach(praan_module_catalog() as $id=>$module){
        $enabled=$module['default_enabled']?1:0;
        // First run upgrades must respect the legacy Control Center toggles.
        if($id==='reports'||$id==='import'){
            $oldSetting->execute([$id==='reports'?'module_reports':'module_import']);
            $legacy=$oldSetting->fetchColumn();
            if($legacy!==false)$enabled=$legacy==='1'?1:0;
        }
        $insert->execute([$id,$enabled,$module['version']]);
        foreach($module['features'] as $key=>$def)$flagInsert->execute([$id,$key,$def['default']?1:0]);
        foreach($module['settings'] as $key=>$def)$optionInsert->execute([$id,$key,(string)$def['default']]);
    }
}
function praan_module_row(string $id): ?array {
    if(!isset(praan_module_catalog()[$id]))return null;
    $q=db()->prepare('SELECT * FROM praan_modules WHERE id=?');$q->execute([$id]);
    return $q->fetch()?:null;
}
function praan_module_access(string $id): bool {
    static $cache=[];
    $cacheKey=$id.'|'.(admin()?(string)admin_id():'anon');
    if(isset($cache[$cacheKey]))return $cache[$cacheKey];
    $registry=praan_module_catalog();
    if(!isset($registry[$id]))return $cache[$cacheKey]=false;
    if($registry[$id]['core'])return $cache[$cacheKey]=true;
    $q=db()->prepare('SELECT enabled,mode FROM praan_modules WHERE id=?');$q->execute([$id]);$row=$q->fetch();
    if(!$row||!(int)$row['enabled'])return $cache[$cacheKey]=false;
    if($row['mode']==='sandbox'&&!praan_is_super())return $cache[$cacheKey]=false;
    foreach($registry[$id]['requires'] as $dep){if(!praan_module_access($dep))return $cache[$cacheKey]=false;}
    return $cache[$cacheKey]=true;
}
function praan_module_option(string $id,string $key): string {
    $def=praan_module_catalog()[$id]['settings'][$key]??null;
    if(!is_array($def))throw new InvalidArgumentException('অপরিচিত মডিউল সেটিংস।');
    $q=db()->prepare('SELECT option_value FROM praan_module_settings WHERE module_id=? AND option_key=?');$q->execute([$id,$key]);
    $v=$q->fetchColumn();return $v===false?(string)$def['default']:(string)$v;
}
function praan_flag_enabled(string $module,string $key): bool {
    if(!praan_module_access($module))return false;
    $def=praan_module_catalog()[$module]['features'][$key]??null;
    if(!$def)return false;
    $q=db()->prepare('SELECT enabled,audience,rollout FROM praan_feature_flags WHERE module_id=? AND flag_key=?');$q->execute([$module,$key]);
    $f=$q->fetch();if(!$f)return (bool)$def['default'];
    if(!(int)$f['enabled']|| (int)$f['rollout']===0)return false;
    if($f['audience']==='super_admin'&&!praan_is_super())return false;
    if($f['audience']==='admins'&&!admin())return false;
    if((int)$f['rollout']===100)return true;
    // Anonymous visitors cannot have a safe, stable RBAC rollout identity.
    if(!admin())return false;
    $bucket=(int)(hexdec(substr(hash('sha256',$module.'|'.$key.'|'.admin_id()),0,7))%100);
    return $bucket<(int)$f['rollout'];
}
function praan_module_route_owner(string $route): ?string {
    foreach(praan_module_catalog() as $id=>$manifest)if(in_array($route,$manifest['routes'],true))return $id;
    return null;
}
function praan_module_route_gate(string $page): void {
    $owner=praan_module_route_owner($page);
    if($owner!==null && !praan_module_access($owner)){
        http_response_code(404);header('Cache-Control: no-store');
        if(str_starts_with($page,'media_')){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>'এই মডিউলটি বন্ধ রয়েছে।'],JSON_UNESCAPED_UNICODE);}
        else echo '404 — এই মডিউলটি বর্তমানে উপলব্ধ নয়।';
        exit;
    }
    if($page==='quick'&&!praan_flag_enabled('volunteer_core','quick_entry')){http_response_code(404);exit('দ্রুত এন্ট্রি বন্ধ আছে।');}
    if($page==='report_download' && (string)($_GET['mode']??'')==='detail'&&!praan_flag_enabled('reports','detail_export')){http_response_code(404);exit('Detailed Report Export বন্ধ আছে।');}
    if(str_starts_with($page,'media_')&&!praan_flag_enabled('media','async_upload')){http_response_code(404);exit('Async Upload বন্ধ আছে।');}
}
function praan_module_health(string $id): array {
    $mod=praan_module_catalog()[$id]??null;if(!$mod)return ['status'=>'error','checks'=>[['Unknown module',false]]];
    $checks=[['বিশ্বস্ত Manifest',is_file(__DIR__.'/../modules/'.$id.'/module.json')],['PDO SQLite',extension_loaded('pdo_sqlite')]];
    foreach($mod['requires'] as $dependency)$checks[]=['Dependency: '.$dependency,praan_module_access($dependency)];
    if($id==='media'){$checks[]=['Fileinfo',extension_loaded('fileinfo')];$checks[]=['PHP GD (image processing)',extension_loaded('gd')];}
    if($id==='documents')$checks[]=['ZipArchive',class_exists('ZipArchive')];
    if($id==='import')$checks[]=['ZipArchive (XLSX)',class_exists('ZipArchive')];
    $problems=count(array_filter($checks,static fn($item)=>!$item[1]));
    return ['status'=>$problems===0?'healthy':'warning','checks'=>$checks];
}
function praan_module_requires_active(string $id): array {
    $result=[];foreach(praan_module_catalog() as $otherId=>$mod)if($otherId!==$id&&in_array($id,$mod['requires'],true)&&praan_module_access($otherId))$result[]=$otherId;
    return $result;
}
function praan_module_event(string $id,string $action,string $details=''): void {
    db()->prepare('INSERT INTO praan_module_events(module_id,action,actor_id,details) VALUES(?,?,?,?)')->execute([$id,$action,admin_id(),substr($details,0,400)]);
}
function praan_module_set_state(string $id,bool $enabled,string $mode): void {
    $module=praan_module_catalog()[$id]??null;if(!$module)throw new RuntimeException('মডিউল পাওয়া যায়নি।');
    if($module['core'])throw new RuntimeException('Core Module বন্ধ বা Sandbox করা যাবে না।');
    if(!in_array($mode,['sandbox','production'],true))throw new RuntimeException('অবৈধ Module Mode।');
    if($enabled){
        foreach($module['requires'] as $dep)if(!praan_module_access($dep))throw new RuntimeException('আগে Dependency চালু করুন: '.$dep);
        if($id==='media' && (!extension_loaded('gd')||!extension_loaded('fileinfo')))
            throw new RuntimeException('Resumable Media চালু করতে PHP GD ও Fileinfo extension প্রয়োজন।');
        if($id==='documents' && !class_exists('ZipArchive'))
            throw new RuntimeException('DOCX Module চালু করতে PHP ZipArchive extension প্রয়োজন।');
    }else{
        $dependents=praan_module_requires_active($id);
        if($dependents)throw new RuntimeException('এই Module ব্যবহার করছে: '.implode(', ',$dependents));
    }
    $old=praan_module_row($id);if(!$old)throw new RuntimeException('Module registry অসম্পূর্ণ।');
    $new=(int)$enabled;if((int)$old['enabled']===$new&&$old['mode']===$mode)return;
    db()->prepare('UPDATE praan_modules SET enabled=?,mode=?,updated_at=CURRENT_TIMESTAMP,updated_by=? WHERE id=?')
      ->execute([$new,$mode,admin_id(),$id]);
    praan_module_event($id,'module_state','enabled='.$new.'; mode='.$mode);
    log_action(null,'module_state_changed',$id.' enabled='.$new.' mode='.$mode);
}
function praan_module_save_options(string $id,array $submitted): int {
    $schema=praan_module_catalog()[$id]['settings']??null;if($schema===null)throw new RuntimeException('অপরিচিত মডিউল।');
    $count=0;$up=db()->prepare('INSERT INTO praan_module_settings(module_id,option_key,option_value) VALUES(?,?,?) ON CONFLICT(module_id,option_key) DO UPDATE SET option_value=excluded.option_value,updated_at=CURRENT_TIMESTAMP');
    foreach($schema as $key=>$def){
        $v=$submitted[$key]??null;if(!is_string($v)||!isset($def['choices'][$v]))continue;
        if(praan_module_option($id,$key)===$v)continue;
        $up->execute([$id,$key,$v]);$count++;
    }
    if($count){praan_module_event($id,'module_settings','changed='.$count);log_action(null,'module_settings_changed',$id.' changed='.$count);}
    return $count;
}
function praan_module_set_flag(string $id,string $flag,bool $enabled,string $audience,int $rollout): void {
    if(!isset(praan_module_catalog()[$id]['features'][$flag]))throw new RuntimeException('Feature Flag পাওয়া যায়নি।');
    if(!in_array($audience,['all','admins','super_admin'],true)||$rollout<0||$rollout>100)throw new RuntimeException('Feature rollout সঠিক নয়।');
    db()->prepare('UPDATE praan_feature_flags SET enabled=?,audience=?,rollout=?,updated_at=CURRENT_TIMESTAMP WHERE module_id=? AND flag_key=?')
      ->execute([(int)$enabled,$audience,$rollout,$id,$flag]);
    praan_module_event($id,'feature_flag',$flag.' enabled='.(int)$enabled.' audience='.$audience.' rollout='.$rollout);
    log_action(null,'module_flag_changed',$id.'.'.$flag);
}
