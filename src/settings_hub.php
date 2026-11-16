<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
/** Only schema-defined settings can be modified via the UI. Empty keys and secrets are never exposed. */
function praan_settings_schema(): array {
    return [
      'brand'=>['title'=>'ব্র্যান্ডিং ও পরিচিতি','icon'=>'bi-stars','items'=>[
        'organization'=>['সংস্থার নাম','text','পার্টিসিপেটরি রিসার্চ অ্যান্ড অ্যাকশন নেটওয়ার্ক - প্রান',170],
        'site_title'=>['সাইটের নাম','text','PRAAN Volunteer Hub',100],
        'site_tagline'=>['সাবটাইটেল','text','Youth Volunteer Management System',130],
        'accent'=>['প্রধান রং','color','#168b8b'],
        'notice_enabled'=>['শীর্ষ নোটিশ চালু','bool','0'],
        'notice_text'=>['নোটিশের লেখা','text','',250],
      ]],
      'design'=>['title'=>'ডিজাইন ও ইন্টারফেস','icon'=>'bi-palette','items'=>[
        'ui_density'=>['ডেস্কটপ ঘনত্ব','select','comfortable',['comfortable'=>'আরামদায়ক','compact'=>'কমপ্যাক্ট']],
        'ui_motion'=>['অ্যানিমেশন','select','auto',['auto'=>'ডিভাইস অনুযায়ী','reduce'=>'কমিয়ে দিন']],
        'background_enabled'=>['পাবলিক হোমে ব্যাকগ্রাউন্ড ছবি','bool','0'],
        'pwa_enabled'=>['Installable PWA ও নিরাপদ অফলাইন পৃষ্ঠা','bool','1'],
        'mobile_nav_enabled'=>['মোবাইল Bottom Navigation','bool','1'],
        'command_palette_enabled'=>['Ctrl+K Command Palette','bool','1'],
      ]],
      'registration'=>['title'=>'নিবন্ধন ও ড্রাফট','icon'=>'bi-person-vcard','items'=>[
        'public_registration_enabled'=>['পাবলিক নিবন্ধন চালু','bool','1'],
        'draft_recovery_enabled'=>['ড্রাফট রিকভারি চালু','bool','1'],
        'async_photo_enabled'=>['Resume-যোগ্য ব্যাকগ্রাউন্ড ছবি আপলোড','bool','1'],
        'offline_queue_enabled'=>['এনক্রিপ্টেড Offline Queue (শুধু অ্যাডমিন নতুন ফর্ম)','bool','1'],
        'offline_queue_limit'=>['অফলাইন কিউ সর্বোচ্চ রেকর্ড','select','20',['5'=>'5','10'=>'10','20'=>'20']],
        'max_photo_mb'=>['ছবির সর্বোচ্চ সাইজ (MB)','select','2',['1'=>'1 MB','2'=>'2 MB','3'=>'3 MB','4'=>'4 MB']],
      ]],
      'directory'=>['title'=>'পাবলিক তথ্য ও গোপনীয়তা','icon'=>'bi-shield-lock','items'=>[
        'public_directory_enabled'=>['পাবলিক ডিরেক্টরি চালু','bool','1'],
        'directory_limit'=>['পাবলিক তালিকার সর্বোচ্চ সংখ্যা','select','100',['25'=>'25','50'=>'50','100'=>'100']],
      ]],
      'documents'=>['title'=>'প্রিন্ট ও ডকুমেন্ট','icon'=>'bi-file-earmark-word','items'=>[
        'docx_export_enabled'=>['DOCX ডাউনলোড চালু (অ্যাডমিন)','bool','1'],
        'print_emphasize_identity'=>['প্রিন্টে নাম ও ফোন বড় দেখানো','bool','1'],
        'print_footer_note'=>['প্রিন্ট ফুটারের লেখা','text','PRAAN · Volunteer Registration',150],
      ]],
      'table'=>['title'=>'স্বেচ্ছাসেবক তালিকা','icon'=>'bi-table','items'=>[
        'table_page_size'=>['প্রতি পৃষ্ঠায় ডিফল্ট সারি','select','25',['10'=>'10','25'=>'25','50'=>'50','100'=>'100']],
      ]],
      'support'=>['title'=>'সাপোর্ট ও ডেভেলপার','icon'=>'bi-headset','items'=>[
        'developer_badge_enabled'=>['Rony Siddiqi ব্যাজ চালু','bool','1'],
        'support_facebook'=>['Facebook URL','url','https://facebook.com/RonySiddiqi',220],
        'support_telegram'=>['Telegram URL','url','https://t.me/RonySiddiqi',220],
        'support_whatsapp_phone'=>['WhatsApp নম্বর (দেশ কোডসহ, + ছাড়া)','phone','',18],
      ]],
      'security'=>['title'=>'সেশন ও নিরাপত্তা','icon'=>'bi-fingerprint','items'=>[
        'admin_idle_minutes'=>['অ্যাডমিন নিষ্ক্রিয় থাকলে লগআউট','select','30',['15'=>'15 মিনিট','30'=>'30 মিনিট','60'=>'60 মিনিট','120'=>'120 মিনিট']],
      ]],
      'updates'=>['title'=>'আপডেট ও রক্ষণাবেক্ষণ','icon'=>'bi-arrow-repeat','items'=>[
        'update_notice_enabled'=>['Update Center লিংক ও ব্যাজ দেখানো','bool','1'],
      ]],
    ];
}
function praan_option(string $key): string {
    foreach(praan_settings_schema() as $group) if(isset($group['items'][$key])) return setting($key,(string)$group['items'][$key][2]);
    return '';
}
function praan_enabled(string $key): bool { return praan_option($key)==='1'; }
function praan_save_settings(array $submitted): int {
    $changes=[];
    foreach(praan_settings_schema() as $group) foreach($group['items'] as $key=>$def) {
        $type=$def[1]; $raw=$submitted[$key]??($type==='bool'?'0':null);
        if(!is_scalar($raw)) continue;
        $value=trim((string)$raw);
        if($type==='bool')$value=$value==='1'?'1':'0';
        if($type==='select' && !isset($def[3][$value]))continue;
        if($type==='color' && !preg_match('/^#[0-9a-fA-F]{6}$/D',$value))continue;
        if($type==='phone' && $value!=='' && !preg_match('/^[1-9][0-9]{7,17}$/D',$value))continue;
        if($type==='url' && ($value!=='' && (!filter_var($value,FILTER_VALIDATE_URL)||parse_url($value,PHP_URL_SCHEME)!=='https')))continue;
        if(in_array($type,['text','url','phone'],true))$value=mb_safe_limit(strip_tags($value),(int)($def[3]??220));
        if($key==='organization' && $value==='')continue;
        if(setting($key,(string)$def[2])===$value)continue;
        $changes[$key]=$value;
    }
    if(!$changes)return 0;
    $db=db();$db->beginTransaction();
    try {
        foreach($changes as $key=>$value)set_setting($key,$value);
        log_action(null,'settings_changed','keys='.implode(',',array_keys($changes)));
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    return count($changes);
}
function praan_settings_editor(): void {
    require_admin();page_start('PRAAN Control Center','settings',true);
    ?><div class="praan-control-hero"><div><span class="eyebrow">SITE-WIDE CONTROLS</span><h2>সম্পূর্ণ সাইটের সেটিংস</h2><p>এখান থেকে ব্র্যান্ডিং, পাবলিক নিবন্ধন, ডিরেক্টরি, প্রিন্ট, সাপোর্ট, সেশন ও মডিউল চালু/বন্ধ করুন।</p></div><div class="control-health"><i class="bi bi-shield-check"></i><span>Whitelist validated<br><small>Changes audited</small></span></div></div>
    <div class="praan-control-layout"><aside class="praan-control-tabs" aria-label="সেটিংস গ্রুপ"><input class="form-control mb-2" type="search" id="praanSettingSearch" placeholder="সেটিংস খুঁজুন...">
    <?php foreach(praan_settings_schema() as $group=>$info):?><button type="button" class="praan-control-tab" data-group="<?=e($group)?>"><i class="bi <?=e($info['icon'])?>"></i><?=e($info['title'])?></button><?php endforeach;?>
    <a class="praan-control-tab" href="<?=e(url('modules'))?>"><i class="bi bi-puzzle"></i> Module Manager &amp; Feature Flags <i class="bi bi-arrow-up-right ms-auto"></i></a>
    <a class="praan-control-tab" href="<?=e(url('updates'))?>"><i class="bi bi-github"></i> GitHub &amp; Version History <i class="bi bi-arrow-up-right ms-auto"></i></a>
    </aside><div><form id="praanSettingsForm" method="post" action="<?=e(url('settings_save_pro'))?>"><?=csrf_field()?>
    <?php foreach(praan_settings_schema() as $group=>$info):?><section class="card dashboard-card mb-3 praan-setting-group" id="group-<?=e($group)?>"><div class="card-header"><h3 class="card-title"><i class="bi <?=e($info['icon'])?> me-2"></i><?=e($info['title'])?></h3></div><div class="card-body"><div class="praan-setting-grid">
    <?php foreach($info['items'] as $key=>$def):$type=$def[1];$value=praan_option($key);?><label class="praan-setting-field" data-search="<?=e($def[0].' '.$key)?>"><span><?=e($def[0])?></span>
    <?php if($type==='bool'):?><select class="form-select" name="settings[<?=e($key)?>]"><option value="1" <?=$value==='1'?'selected':''?>>চালু</option><option value="0" <?=$value==='0'?'selected':''?>>বন্ধ</option></select>
    <?php elseif($type==='select'):?><select class="form-select" name="settings[<?=e($key)?>]"><?php foreach($def[3] as $opt=>$label):?><option value="<?=e($opt)?>" <?=$opt===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select>
    <?php else:?><input class="form-control <?=$type==='color'?'form-control-color':''?>" type="<?=$type==='color'?'color':($type==='url'?'url':'text')?>" name="settings[<?=e($key)?>]" value="<?=e($value)?>" maxlength="<?=e($def[3]??250)?>" <?=($key==='organization'?'required':'')?>><?php endif;?></label><?php endforeach;?></div></div></section><?php endforeach;?>
    <div class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-image me-2"></i> ব্যাকগ্রাউন্ড ছবি</h3></div><div class="card-body"><p class="text-muted small">পাবলিক হোমে ছবি ব্যবহার করুন; নির্বাচন করলেই আপলোড শুরু হবে। ইমেজ পুনরায় এনকোড করতে PHP GD প্রয়োজন।</p><input type="file" class="form-control" id="praanBackgroundUpload" data-upload="<?=e(url('background_upload'))?>" data-csrf="<?=e(csrf_token())?>" accept="image/jpeg,image/png,image/webp"><div class="mt-2" id="praanBackgroundProgress" aria-live="polite"></div><?php if(is_file(__DIR__.'/../storage/branding/home.jpg')):?><img class="praan-bg-preview" src="<?=e(url('background_asset'))?>" alt="বর্তমান ব্যাকগ্রাউন্ড"><button type="button" class="btn btn-sm btn-outline-danger mt-2" id="praanBackgroundRemove" data-action="<?=e(url('background_delete'))?>" data-csrf="<?=e(csrf_token())?>">ছবি মুছুন</button><?php endif;?></div></div>
    <div class="card dashboard-card praan-setting-save"><div class="card-body"><p class="small text-muted mb-2">সেটিংস পরিবর্তনের জন্য বর্তমান অ্যাডমিন পাসওয়ার্ড প্রয়োজন। ব্যক্তিগত তথ্য বা পাসওয়ার্ড Audit Log-এ সংরক্ষণ হয় না।</p><input class="form-control mb-2" type="password" name="admin_password" autocomplete="current-password" required placeholder="বর্তমান অ্যাডমিন পাসওয়ার্ড"><button class="btn btn-brand" type="submit"><i class="bi bi-check2-circle"></i> সব সেটিংস সংরক্ষণ করুন</button></div></div>
    </form></div></div><?php page_end(true);
}
