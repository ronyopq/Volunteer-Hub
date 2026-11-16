<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function print_value(array $d, string $key): string { return e(display_value($d[$key] ?? '')); }
function print_field(array $d, string $key, string $label, string $cls = ''): void {
    echo '<div class="pf '.e($cls.' '.(in_array($key,['name_bn','name_en','phone','alternate_phone','whatsapp'],true)?'primary-print':'')) .'"><small>'.e($label).'</small><div>'.(print_value($d,$key) ?: '&nbsp;').'</div></div>';
}
function print_checks(array $d, string $key, array $options): void {
    $vals = $d[$key] ?? [];
    $vals = is_array($vals) ? $vals : (empty($vals) ? [] : [$vals]);
    foreach($options as $val=>$label) {
        if(is_int($val)) $val = $label;
        echo '<span class="pc"><span class="sq">'.(in_array($val,$vals,true)?'✓':'').'</span>'.e($label).'</span> ';
    }
}
function print_bar(string $label): void { echo '<div class="pbar">'.e($label).'</div>'; }
function ppage(int $page, bool $front = false): void {
    echo '<section class="print-page" aria-label="পৃষ্ঠা '.$page.'">';
    if(!$front) echo '<div class="print-top-logo"><img src="assets/pran-logo.png" alt="PRAAN"></div>';
}
function pend(int $page): void { echo '<div class="page-n">পৃষ্ঠা '.$page.' / 3 · '.e(praan_option('print_footer_note')).'</div></section>'; }
function printable_record(array $v): void {
    $d = $v['data'];
    if(!admin())foreach(form_sections()['H']['fields'] as $privateKey=>$fieldDef)$d[$privateKey]='';
    $age = trim((string)($d['age_reported']??''))!=='' ? (string)$d['age_reported'] : age_from_date((string)$d['dob']);
    header('Cache-Control: private, no-store');
    ?>
<!doctype html><html lang="bn"><head><meta charset="utf-8"><title><?=e($v['volunteer_code'])?> · PRAAN Volunteer Form</title><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/print.css?v=<?=e(APP_VERSION)?>"><link rel="stylesheet" href="assets/praan-foundation.css?v=<?=e(APP_VERSION)?>"></head><body class="<?=praan_enabled('print_emphasize_identity')?'praan-print-primary':''?>">
<div class="print-toolbar"><div>PRAAN · <?=e($v['volunteer_code'])?> · মূল ফর্মের সব তথ্যসহ ৩ পৃষ্ঠার A4 প্রিন্ট</div><button onclick="window.print()">🖨 প্রিন্ট / PDF</button></div>
<?php ppage(1,true); ?>
<div class="phead"><div class="phead-center"><img class="phead-logo" src="assets/pran-logo.png" alt="PRAAN"><h2>পার্টিসিপেটরি রিসার্চ অ্যান্ড অ্যাকশন<br>নেটওয়ার্ক - প্রান</h2><strong>স্বেচ্ছাসেবক নিবন্ধন ও প্রোফাইল ফর্ম</strong><span>YOUTH VOLUNTEER REGISTRATION &amp; PROFILE FORM</span></div><div class="passport"><?php if(!empty($v['photo_file'])): ?><img src="<?=e(url('photo',['id'=>$v['id']] + (admin()?[]:['token'=>(string)($_GET['token']??'')])) )?>" alt="ছবি"><?php else: ?>পাসপোর্ট সাইজ<br>ছবি<br><small>Passport-size photo</small><?php endif;?></div></div>
<table class="pf-table identifiers"><tr><th>ফর্ম নং / Form No.</th><th>স্বেচ্ছাসেবক আইডি / Volunteer ID</th><th>প্রকল্প/কর্মসূচি / Project/Programme</th><th>নিবন্ধনের তারিখ / Date</th></tr><tr><td><?=print_value($d,'form_no')?></td><td><?=e($v['volunteer_code'])?></td><td><?=print_value($d,'project')?></td><td><?=e($v['registered_on'])?></td></tr></table>
<div class="instruction">নির্দেশনা: ফর্মটি স্পষ্ট অক্ষরে পূরণ করুন। প্রয়োজনীয় ক্ষেত্রে □ চিহ্নিত করুন। সংবেদনশীল তথ্য শুধু কর্মসূচির প্রয়োজনেই সংগ্রহ ও ব্যবহার করা হবে।</div>
<?php print_bar('A. ব্যক্তিগত ও যোগাযোগের তথ্য / Personal & Contact Information'); ?>
<div class="pcells pcols1"><?php print_field($d,'name_en','নাম (ইংরেজি BLOCK LETTER) / Full name in English'); ?></div>
<div class="pcells pcols1"><?php print_field($d,'name_bn','নাম (বাংলায়) / Full name in Bangla'); ?></div>
<div class="pcells pcols3"><div class="pf"><small>লিঙ্গ / Gender</small><div><?php print_checks($d,'gender',['নারী','পুরুষ','অন্যান্য/বলতে অনিচ্ছুক']); ?></div></div><?php print_field($d,'dob','জন্মতারিখ / Date of birth'); ?><div class="pf"><small>বয়স / Years</small><div><?=e($age)?></div></div></div>
<div class="pcells pcols3"><?php print_field($d,'blood_group','রক্তের গ্রুপ / Blood group'); print_field($d,'occupation','পেশা / Occupation'); print_field($d,'education','সর্বশেষ শিক্ষাগত যোগ্যতা'); ?></div>
<div class="pcells pcols2"><?php print_field($d,'id_number',trim(display_value($d['id_document_type']??''))?:'জন্মনিবন্ধন / NID নং'); print_field($d,'phone','মোবাইল নং / Primary phone'); ?></div>
<div class="pcells pcols2"><?php print_field($d,'alternate_phone','বিকল্প মোবাইল নং'); print_field($d,'whatsapp','হোয়াটসঅ্যাপ নং'); ?></div>
<div class="pcells pcols2"><?php print_field($d,'email','ইমেইল'); ?><div class="pf"><small>স্মার্টফোন ও ইন্টারনেট ব্যবহার</small><div><?php print_checks($d,'smartphone',['হ্যাঁ','না']); ?></div></div></div>
<div class="pcells pcols2"><div class="pf"><small>মোবাইল ফাইন্যান্সিয়াল সার্ভিস</small><div><?php print_checks($d,'mfs',['বিকাশ','নগদ','রকেট','অন্যান্য']); ?></div><small>অ্যাকাউন্টের মালিকানা</small><div><?php print_checks($d,'mfs_ownership',['নিজ','অন্য']); ?> <?=print_value($d,'mfs_relation')?></div></div><div class="pf"><small>জরুরি যোগাযোগ: নাম, সম্পর্ক ও মোবাইল</small><div><?=print_value($d,'emergency_name')?> · <?=print_value($d,'emergency_relation')?> · <?=print_value($d,'emergency_phone')?></div></div></div>
<div class="pcells pcols2"><?php print_field($d,'mother_name','মাতার নাম'); print_field($d,'mother_phone','মাতার মোবাইল'); ?></div>
<div class="pcells pcols2"><?php print_field($d,'father_name','পিতার নাম'); print_field($d,'father_phone','পিতার মোবাইল'); ?></div>
<?php print_bar('B. ঠিকানা / Address'); ?>
<div class="pcells pcols2"><?php print_field($d,'present_holding','বর্তমান ঠিকানা / Present address – বাড়ি/হোল্ডিং'); print_field($d,'permanent_holding','স্থায়ী ঠিকানা / Permanent address – বাড়ি/হোল্ডিং'); ?></div>
<div class="pcells pcols2 address-cont"><div class="pf"><small>বর্তমান ঠিকানা / Present address</small><div>গ্রাম/মহল্লা: <?=print_value($d,'present_village')?></div><div>ওয়ার্ড: <?=print_value($d,'present_ward')?> · ইউনিয়ন/পৌরসভা: <?=print_value($d,'present_union')?></div><div>উপজেলা/থানা: <?=print_value($d,'present_upazila')?> · জেলা: <?=print_value($d,'present_district')?></div></div><div class="pf"><small>স্থায়ী ঠিকানা / Permanent address</small><div>গ্রাম/মহল্লা: <?=print_value($d,'permanent_village')?></div><div>ওয়ার্ড: <?=print_value($d,'permanent_ward')?> · ইউনিয়ন/পৌরসভা: <?=print_value($d,'permanent_union')?></div><div>উপজেলা/থানা: <?=print_value($d,'permanent_upazila')?> · জেলা: <?=print_value($d,'permanent_district')?></div></div></div>
<?php pend(1); ?>
<?php ppage(2); ?>
<?php print_bar('C. স্বেচ্ছাসেবী আগ্রহ, দক্ষতা ও প্রাপ্যতা / Volunteer Interests, Skills & Availability'); ?>
<div class="pblock"><strong>যে ধরনের কাজে আগ্রহী / Preferred volunteer activities</strong><p><?php print_checks($d,'interests',form_sections()['C']['fields']['interests'][2]); ?> <?=print_value($d,'interests_other')?></p></div>
<div class="pcells pcols2"><div class="pf"><small>সাপ্তাহিক প্রাপ্যতা</small><div><?php print_checks($d,'availability',['কর্মদিবস','সাপ্তাহিক ছুটি','উভয়']); ?></div></div><div class="pf"><small>সময়</small><div><?php print_checks($d,'time_available',['সকাল','বিকাল','সন্ধ্যা','জরুরি ডাক']); ?></div></div></div>
<div class="pcells pcols2"><div class="pf"><small>ভ্রমণ/মাঠে কাজের প্রস্তুতি</small><div><?php print_checks($d,'travel_scope',['নিজ ইউনিয়ন','উপজেলা','জেলা','দেশের অন্যত্র']); ?></div></div><div class="pf"><small>রাত/জরুরি সময়ে দায়িত্ব</small><div><?php print_checks($d,'emergency_duty',['সম্ভব','সীমিত','সম্ভব নয়']); ?></div></div></div>
<div class="pcells pcols2"><?php print_field($d,'languages','ভাষা দক্ষতা: বাংলা/ইংরেজি/স্থানীয় ভাষা/অন্যান্য'); print_field($d,'special_skills','বিশেষ দক্ষতা'); ?></div>
<div class="pblock"><strong>ডিজিটাল/প্রযুক্তিগত দক্ষতা (প্রযোজ্য সবগুলো)</strong><p><?php print_checks($d,'digital_skills',form_sections()['C']['fields']['digital_skills'][2]); ?> <?=print_value($d,'digital_skills_other')?></p></div>
<?php print_bar('D. অভিজ্ঞতা ও প্রশিক্ষণ / Experience & Training'); ?>
<div class="pblock"><strong>সার্ভে, ত্রাণ বিতরণ, প্রাকৃতিক দুর্যোগ বা অন্যান্য স্বেচ্ছাসেবী কাজের সংক্ষিপ্ত অভিজ্ঞতা</strong><div class="experience-text"><?=nl2br(print_value($d,'experience_summary'))?></div></div>
<div class="p-table-title">পূর্ববর্তী স্বেচ্ছাসেবী/কর্মঅভিজ্ঞতা (প্রযোজ্য হলে)</div>
<table class="pf-table row-table"><thead><tr><th>সংস্থা/প্রকল্প</th><th>দায়িত্ব/কাজ</th><th>এলাকা</th><th>সময়কাল</th><th>রেফারেন্স/মোবাইল</th></tr></thead><tbody><?php for($i=0;$i<4;$i++): ?><tr><?php foreach(['org','duty','area','period','reference'] as $key): ?><td><?=e($d['work_history'][$i][$key]??'')?></td><?php endforeach;?></tr><?php endfor;?></tbody></table>
<div class="p-table-title">প্রাসঙ্গিক প্রশিক্ষণ (প্রযোজ্য হলে)</div><table class="pf-table row-table training-table"><thead><tr><th>প্রশিক্ষণের নাম</th><th>আয়োজক</th><th>তারিখ/সময়কাল</th><th>সনদ আছে?</th></tr></thead><tbody><?php for($i=0;$i<4;$i++): ?><tr><?php foreach(['title','organizer','period','certificate'] as $key): ?><td><?=e($d['training_history'][$i][$key]??'')?></td><?php endforeach;?></tr><?php endfor;?></tbody></table>
<?php pend(2); ?>
<?php ppage(3); ?>
<?php print_bar('E. নিরাপদ অংশগ্রহণ ও সহায়তার প্রয়োজন / Safe Participation & Support Needs'); ?>
<div class="pblock"><strong>নিরাপদে দায়িত্ব পালনে কোনো বিশেষ সহায়তা/ব্যবস্থা প্রয়োজন কি? ঐচ্ছিক / Optional</strong><div class="support-text"><?=nl2br(print_value($d,'support_needs'))?></div></div>
<div class="pcells pcols2"><div class="pf"><small>জরুরি পরিস্থিতিতে প্রাথমিক চিকিৎসা জানা</small><div><?php print_checks($d,'first_aid',['হ্যাঁ','না']); ?></div></div><div class="pf"><small>সাঁতার জানা</small><div><?php print_checks($d,'swimming',['হ্যাঁ','না']); ?></div></div></div>
<div class="pcells pcols2"><div class="pf"><small>নিজস্ব পরিবহন/যাতায়াত সুবিধা</small><div><?php print_checks($d,'transport',['সাইকেল','মোটরবাইক','অন্যান্য','নেই']); ?></div></div><?php print_field($d,'driving_license','ড্রাইভিং লাইসেন্স (প্রযোজ্য হলে) ধরণ/নং'); ?></div>
<div class="instruction">সুরক্ষা নোট: সংস্থা দায়িত্ব বণ্টনের সময় বয়স, সক্ষমতা, নিরাপত্তা, যাতায়াত, স্থানীয় ঝুঁকি ও প্রয়োজনীয় সহায়তা বিবেচনা করবে।</div>
<?php print_bar('F. ঘোষণা, আচরণবিধি ও তথ্য ব্যবহারের সম্মতি / Declaration, Code of Conduct & Data Consent'); ?>
<div class="pblock declaration"><p>আমি ঘোষণা করছি যে উপরে প্রদত্ত তথ্য আমার জানা মতে সঠিক। আমি স্বেচ্ছাসেবক হিসেবে সংস্থার নির্দেশনা ও প্রযোজ্য নীতিমালা মেনে দায়িত্ব পালনে সম্মত।</p>
<?php foreach(form_sections()['F']['fields']['consents'][2] as $code=>$text): ?><div class="declare-row"><span class="sq"><?=in_array($code,$d['consents']??[],true)?'✓':''?></span><?=e($text)?></div><?php endforeach; ?></div>
<div class="pcells pcols2"><div class="pf"><small>স্বেচ্ছাসেবকের স্বাক্ষর</small><div>নাম: <?=print_value($d,'volunteer_sign_name')?></div><div>স্বাক্ষর ও তারিখ: <?=print_value($d,'volunteer_sign_date')?></div></div><div class="pf"><small>তথ্য সংগ্রহকারীর স্বাক্ষর</small><div>নাম: <?=print_value($d,'collector_sign_name')?></div><div>স্বাক্ষর ও তারিখ: <?=print_value($d,'collector_sign_date')?></div></div></div>
<?php print_bar('G. সংযুক্তি / Attachments'); ?>
<div class="pblock small-gap"><?php print_checks($d,'attachments',form_sections()['G']['fields']['attachments'][2]); ?> <?=print_value($d,'attachments_other')?></div>
<?php print_bar('H. শুধু অফিস ব্যবহারের জন্য / For Office Use Only'); ?>
<div class="pcells pcols2"><div class="pf"><small>ডকুমেন্ট যাচাই</small><div><?php print_checks($d,'document_check',['সম্পূর্ণ','অসম্পূর্ণ']); ?></div></div><div class="pf"><small>রেফারেন্স যাচাই</small><div><?php print_checks($d,'reference_check',['হয়েছে','প্রয়োজন নেই','বাকি']); ?></div></div></div>
<div class="pcells pcols2"><?php print_field($d,'orientation_date','ওরিয়েন্টেশন তারিখ'); ?><div class="pf"><small>স্বেচ্ছাসেবক ক্যাটাগরি</small><div><?php print_checks($d,'category',['সাধারণ','জরুরি সাড়া','দক্ষ']); ?></div></div></div>
<div class="pcells pcols2"><?php print_field($d,'assigned_team','নির্ধারিত এলাকা/টিম'); ?><div class="pf"><small>স্ট্যাটাস</small><div><?php print_checks($d,'status',['সক্রিয়','অপেক্ষমাণ','নির্বাচিত নয়']); ?></div></div></div>
<div class="pcells pcols2"><div class="pf"><small>আইডি/কার্ড ইস্যু</small><div><?php print_checks($d,'card_issued',['হ্যাঁ','না']); ?> তারিখ: <?=print_value($d,'card_issue_date')?></div></div><div class="pf"><small>অনুমোদনকারী</small><div><?=print_value($d,'approver_name')?></div><div>স্বাক্ষর ও তারিখ: <?=print_value($d,'approver_date')?></div></div></div>
<div class="pblock office-comments"><strong>মন্তব্য/বিশেষ নির্দেশনা</strong><p><?=nl2br(print_value($d,'office_notes'))?></p></div>
<?php pend(3); ?>
</body></html><?php
}
