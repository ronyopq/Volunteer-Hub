<?php
declare(strict_types=1);
/** PRAAN v1.7.0 trusted module control UI: no unreviewed code upload/execute. */
function praan_modules_export(): never {
    praan_require('modules.view');
    $pdo=db();$rows=[];
    foreach(praan_module_catalog() as $id=>$manifest){
        $state=praan_module_row($id);
        $q=$pdo->prepare('SELECT option_key,option_value FROM praan_module_settings WHERE module_id=?');$q->execute([$id]);
        $settings=$q->fetchAll(PDO::FETCH_KEY_PAIR);
        // Never include present or future secret settings in portable JSON exports.
        $settings=array_filter($settings,static fn($key)=>isset($manifest['settings'][$key]) && ($manifest['settings'][$key]['type']??'')!=='secret',ARRAY_FILTER_USE_KEY);
        $q=$pdo->prepare('SELECT flag_key,enabled,audience,rollout FROM praan_feature_flags WHERE module_id=?');$q->execute([$id]);
        $flags=[];foreach($q->fetchAll() as $flag)$flags[(string)$flag['flag_key']]=['enabled'=>(bool)$flag['enabled'],'audience'=>$flag['audience'],'rollout'=>(int)$flag['rollout']];
        $rows[$id]=['version'=>$manifest['version'],'enabled'=>(bool)($state['enabled']??$manifest['default_enabled']),'mode'=>$state['mode']??'production','options'=>$settings,'features'=>$flags];
    }
    $json=json_encode(['format'=>'PRAAN-module-config-v1','generated_at'=>gmdate('c'),'app_version'=>APP_VERSION,'modules'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="PRAAN_Modules_'.date('Y-m-d').'.json"');
    header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    echo $json;exit;
}

function praan_module_action(string $page): never {
    praan_require('modules.manage');
    verify_csrf();
    um_require_password((string)($_POST['actor_password']??''));
    $id=(string)($_POST['module_id']??'');
    if(!isset(praan_module_catalog()[$id]))throw new RuntimeException('বিশ্বস্ত মডিউল পাওয়া যায়নি।');
    switch($page){
        case 'module_state':
            praan_module_set_state($id,(string)($_POST['enabled']??'')==='1',(string)($_POST['mode']??'production'));
            flash('success','Module অবস্থান আপডেট করা হয়েছে।');break;
        case 'module_options':
            $count=praan_module_save_options($id,(array)($_POST['options']??[]));
            flash('success',$count.'টি Module Setting সংরক্ষণ হয়েছে।');break;
        case 'module_flag':
            $rollout=filter_var($_POST['rollout']??null,FILTER_VALIDATE_INT);
            if($rollout===false)throw new RuntimeException('Rollout ০–১০০ দিন।');
            praan_module_set_flag($id,(string)($_POST['flag_key']??''),(string)($_POST['enabled']??'')==='1',(string)($_POST['audience']??''),(int)$rollout);
            flash('success','Feature Flag সংরক্ষণ হয়েছে।');break;
        default: throw new RuntimeException('অপরিচিত অ্যাকশন।');
    }
    redirect('modules',['module'=>$id]);
}
function praan_modules_page(): void {
    praan_require('modules.view');
    $canManage=praan_can('modules.manage');
    $catalog=praan_module_catalog();
    $selected=(string)($_GET['module']??'reports');if(!isset($catalog[$selected]))$selected='reports';
    $tab=(string)($_GET['tab']??'overview');if(!in_array($tab,['overview','history'],true))$tab='overview';
    $rows=db()->query('SELECT * FROM praan_modules ORDER BY id')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $flags=db()->query('SELECT * FROM praan_feature_flags')->fetchAll();
    $flagMap=[];foreach($flags as $f)$flagMap[$f['module_id']][$f['flag_key']]=$f;
    $events=db()->query('SELECT m.*,u.display_name AS actor FROM praan_module_events m LEFT JOIN users u ON u.id=m.actor_id ORDER BY m.id DESC LIMIT 80')->fetchAll();
    $enabled=0;$sandbox=0;$warning=0;
    foreach($catalog as $id=>$mod){$r=$rows[$id]??[];if(!empty($r['enabled']))$enabled++;if(($r['mode']??'')==='sandbox')$sandbox++;if(praan_module_health($id)['status']!=='healthy')$warning++;}
    page_start('Module & Feature Center','modules',true);
    ?>
    <div class="pm-hero"><div><span class="eyebrow">TRUSTED MODULES · GOVERNED ROLLOUT</span><h2>মডিউল ও ফিচার নিয়ন্ত্রণ</h2><p>নিরাপদে Module Enable/Disable, Sandbox Preview, Feature Rollout, স্বাস্থ্য পরীক্ষা ও আলাদা Settings পরিচালনা করুন।</p></div><div class="pm-hero-symbol"><i class="bi bi-grid-1x2-fill"></i></div></div>
    <div class="pm-stat-grid">
      <div class="pm-stat"><i class="bi bi-boxes"></i><span>মডিউল</span><strong><?=count($catalog)?></strong></div>
      <div class="pm-stat"><i class="bi bi-toggle-on"></i><span>চালু</span><strong><?=$enabled?></strong></div>
      <div class="pm-stat"><i class="bi bi-flask"></i><span>Sandbox</span><strong><?=$sandbox?></strong></div>
      <div class="pm-stat"><i class="bi bi-heart-pulse"></i><span>Health Warning</span><strong><?=$warning?></strong></div>
    </div>
    <div class="pm-tabs"><a class="<?=$tab==='overview'?'active':''?>" href="<?=e(url('modules',['tab'=>'overview','module'=>$selected]))?>"><i class="bi bi-grid"></i> Module Registry</a><a class="<?=$tab==='history'?'active':''?>" href="<?=e(url('modules',['tab'=>'history']))?>"><i class="bi bi-clock-history"></i> Change History</a><a href="<?=e(url('settings'))?>"><i class="bi bi-sliders"></i> Global Settings</a><a href="<?=e(url('modules_export'))?>"><i class="bi bi-download"></i> Export Config JSON</a></div>
    <?php if($tab==='history'):?>
      <div class="card dashboard-card"><div class="card-header"><h3 class="card-title">Module Event History</h3><small class="text-muted">শেষ ৮০টি পরিবর্তন</small></div><div class="table-responsive"><table class="table modern-table align-middle mb-0"><thead><tr><th>সময়</th><th>মডিউল</th><th>অ্যাকশন</th><th>অ্যাডমিন</th><th>বিবরণ</th></tr></thead><tbody>
      <?php foreach($events as $event):?><tr><td><?=e($event['created_at'])?></td><td><code><?=e($event['module_id'])?></code></td><td><?=e($event['action'])?></td><td><?=e($event['actor']?:'System')?></td><td class="text-muted"><?=e($event['details'])?></td></tr><?php endforeach;if(!$events):?><tr><td colspan="5" class="text-center text-muted p-4">এখনো কোনো পরিবর্তন নেই।</td></tr><?php endif;?></tbody></table></div></div>
    <?php else: ?>
    <div class="pm-layout"><div class="pm-catalog" aria-label="উপলব্ধ মডিউল">
    <?php foreach($catalog as $id=>$mod):$r=$rows[$id]??[];$health=praan_module_health($id);$on=praan_module_access($id);?>
      <a class="pm-module-card <?=$id===$selected?'selected':''?>" href="<?=e(url('modules',['module'=>$id]))?>"><div class="pm-card-top"><span class="pm-module-icon"><i class="bi <?=e(match($id){'volunteer_core'=>'bi-person-vcard','identity_core'=>'bi-shield-lock','updates_core'=>'bi-arrow-repeat','reports'=>'bi-bar-chart','import'=>'bi-file-earmark-arrow-up','directory'=>'bi-card-list','media'=>'bi-images',default=>'bi-file-earmark-word'})?>"></i></span><span class="pm-lamp <?=$on?'pm-on':'pm-off'?>" title="<?=$on?'সক্রিয়':'বন্ধ'?>"></span></div><strong><?=e($mod['title'])?></strong><small><?=e($mod['description'])?></small><div class="pm-tags"><span><?=$mod['core']?'Core / Locked':'Optional'?></span><?php if(($r['mode']??'')==='sandbox'):?><span>Sandbox</span><?php endif;?><span class="<?=$health['status']==='healthy'?'pm-health-ok':'pm-health-warn'?>"><?=$health['status']==='healthy'?'Healthy':'Needs attention'?></span></div></a>
    <?php endforeach;?></div>
    <?php $mod=$catalog[$selected];$r=$rows[$selected]??[];$health=praan_module_health($selected);?>
    <div class="pm-detail">
      <section class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title"><?=e($mod['title'])?> <span class="badge text-bg-light ms-2">v<?=e($mod['version'])?></span></h3><span class="badge <?=$mod['core']?'text-bg-secondary':(!empty($r['enabled'])?'text-bg-success':'text-bg-warning')?>"><?=$mod['core']?'Locked':(!empty($r['enabled'])?'Enabled':'Disabled')?></span></div>
      <div class="card-body"><p class="text-muted"><?=e($mod['description'])?></p><div class="pm-meta"><span>Module: <code><?=e($selected)?></code></span><span>Requires: <?=e(implode(', ',$mod['requires'])?:'None')?></span><span>Installed: <?=e($r['installed_at']??'—')?></span></div>
      <?php if($mod['core']):?><div class="alert alert-secondary mt-3 mb-0"><i class="bi bi-lock-fill me-2"></i> এটি অপরিহার্য Core Module। নিরাপত্তার জন্য Disable বা Sandbox করা যাবে না।</div>
      <?php elseif($canManage):?><form class="pm-state-form" method="post" action="<?=e(url('module_state'))?>" data-pm-danger="1"><?=csrf_field()?><input type="hidden" name="module_id" value="<?=e($selected)?>"><div class="pm-state-row"><label><span>Module Status</span><select class="form-select" name="enabled"><option value="1" <?=!empty($r['enabled'])?'selected':''?>>Enabled</option><option value="0" <?=empty($r['enabled'])?'selected':''?>>Disabled</option></select></label><label><span>Release Scope</span><select class="form-select" name="mode"><option value="production" <?=($r['mode']??'')==='production'?'selected':''?>>Production — permission অনুযায়ী</option><option value="sandbox" <?=($r['mode']??'')==='sandbox'?'selected':''?>>Sandbox — শুধু Super Admin</option></select></label></div><label class="pm-password-label">পাসওয়ার্ড দিয়ে পরিবর্তন নিশ্চিত করুন<input class="form-control" type="password" name="actor_password" required autocomplete="current-password" placeholder="বর্তমান পাসওয়ার্ড"></label><button class="btn btn-brand" type="submit"><i class="bi bi-check-circle"></i> Module State সংরক্ষণ</button></form>
      <?php endif;?></div></section>
      <section class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-heart-pulse me-2"></i> Module Health</h3><span class="badge <?=$health['status']==='healthy'?'text-bg-success':'text-bg-warning'?>"><?=e(ucfirst($health['status']))?></span></div><div class="card-body"><div class="pm-health-list"><?php foreach($health['checks'] as [$label,$ok]):?><div><i class="bi <?=$ok?'bi-check-circle-fill text-success':'bi-exclamation-triangle text-warning'?>"></i><span><?=e($label)?></span><small><?=$ok?'OK':'Check needed'?></small></div><?php endforeach;?></div></div></section>
      <?php if($mod['settings']):?><section class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-sliders me-2"></i> Module-Specific Settings</h3></div><div class="card-body"><form method="post" action="<?=e(url('module_options'))?>"><?=csrf_field()?><input type="hidden" name="module_id" value="<?=e($selected)?>"><div class="pm-settings-grid"><?php foreach($mod['settings'] as $key=>$opt):?><label><span><?=e($opt['title'])?></span><select name="options[<?=e($key)?>]" class="form-select" <?=$canManage?'':'disabled'?>><?php foreach($opt['choices'] as $value=>$title):?><option value="<?=e($value)?>" <?=praan_module_option($selected,$key)===(string)$value?'selected':''?>><?=e($title)?></option><?php endforeach;?></select></label><?php endforeach;?></div><?php if($canManage):?><label class="pm-password-label">বর্তমান পাসওয়ার্ড<input class="form-control" type="password" name="actor_password" autocomplete="current-password" required></label><button class="btn btn-outline-brand" type="submit">Settings সংরক্ষণ</button><?php endif;?></form></div></section><?php endif;?>
      <?php if($mod['features']):?><section class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-toggles me-2"></i> Feature Flags & Rollout</h3></div><div class="card-body"><p class="small text-muted">০–১০০% Rollout শুধুমাত্র শনাক্তযোগ্য লগইন করা ইউজারের জন্য স্থিতিশীল Hash ব্যবহার করে। এটি Permission-এর বিকল্প নয়।</p><?php foreach($mod['features'] as $key=>$def):$f=$flagMap[$selected][$key]??['enabled'=>(int)$def['default'],'audience'=>'all','rollout'=>100];?><form class="pm-feature-form" method="post" action="<?=e(url('module_flag'))?>"><?=csrf_field()?><input type="hidden" name="module_id" value="<?=e($selected)?>"><input type="hidden" name="flag_key" value="<?=e($key)?>"><div class="pm-feature-name"><strong><?=e($def['title'])?></strong><code><?=e($selected.'.'.$key)?></code></div><div class="pm-feature-controls"><label>সক্রিয়<select class="form-select form-select-sm" name="enabled" <?=$canManage?'':'disabled'?>><?php foreach(['1'=>'On','0'=>'Off'] as $v=>$label):?><option value="<?=$v?>" <?=(string)$f['enabled']===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Audience<select class="form-select form-select-sm" name="audience" <?=$canManage?'':'disabled'?>><?php foreach(['all'=>'All Eligible','admins'=>'Admin Only','super_admin'=>'Super Admin'] as $v=>$label):?><option value="<?=$v?>" <?=$f['audience']===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Rollout %<input class="form-control form-control-sm" type="number" name="rollout" min="0" max="100" value="<?=(int)$f['rollout']?>" <?=$canManage?'':'disabled'?>></label></div><?php if($canManage):?><div class="pm-flag-submit"><input type="password" name="actor_password" class="form-control form-control-sm" autocomplete="current-password" required placeholder="Admin password"><button class="btn btn-sm btn-outline-brand" type="submit">Apply</button></div><?php endif;?></form><?php endforeach;?></div></section><?php endif;?>
      <div class="alert alert-info"><i class="bi bi-shield-lock me-1"></i> শুধু সফটওয়্যারের সঙ্গে পাঠানো <strong>বিশ্বস্ত Manifest</strong> গ্রহণ করা হয়। নতুন মডিউল যুক্ত করতে কোড রিভিউ ও Signed Release লাগবে; এই পেজ কোনো PHP ZIP চালায় না।</div>
    </div></div>
    <?php endif; page_end(true);
}
