<?php
declare(strict_types=1);
require_once __DIR__.'/form_response_map.php';
require_once __DIR__.'/export.php';

/** Both imports and exports use the same column dictionary and positional rules. */
function import_detect_format(array $headers): string {
    if (in_array('নাম (বাংলায়) / Full name in Bangla', $headers, true) && in_array('Timestamp', $headers, true)) return 'google';
    $full = array_values(export_headers());
    if (in_array('Volunteer ID', $headers, true) && in_array('Source', $headers, true) && in_array($full[6]??'Unused',$headers,true)) return 'system';
    if (in_array('name_bn', $headers, true) || in_array('name_en',$headers,true)) return 'codes';
    throw new RuntimeException('কলামগুলো চেনা যায়নি। এই সাইটের Excel Export/Template অথবা Google Forms Responses ফাইল দিন।');
}
function import_date(mixed $v): string {
    $s=trim((string)($v??''));
    if ($s==='') return '';
    if (preg_match('/^\d+(?:\.\d+)?$/',$s) && (float)$s>10000 && (float)$s<110000) {
        return (new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify('+'.((int)floor((float)$s)).' days')->format('Y-m-d');
    }
    if(preg_match('/^\d{4}-\d\d-\d\d/',$s)) return substr($s,0,10);
    foreach(['d/m/Y','m/d/Y','d-m-Y','d.m.Y'] as $fmt) {
        $d=DateTimeImmutable::createFromFormat('!'.$fmt,$s);
        if($d && $d->format($fmt)===$s) return $d->format('Y-m-d');
    }
    return '';
}
function import_timestamp(mixed $v): string {
    $s=trim((string)($v??''));
    if($s==='')return '';
    if(preg_match('/^\d+(?:\.\d+)?$/',$s) && (float)$s>10000 && (float)$s<110000) {
        $day=(int)floor((float)$s);
        $seconds=(int)round(((float)$s-$day)*86400);
        return (new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify('+'.$day.' days')->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
    }
    return mb_safe_limit($s,50);
}
function import_phone(mixed $v): string {
    $s=trim((string)($v??''));
    if (preg_match('/^1[3-9][0-9]{8}$/',$s)) return '0'.$s;
    if (preg_match('/^8801[3-9][0-9]{8}$/',$s)) return '0'.substr($s,3);
    return mb_safe_limit($s,100);
}
function import_choice(string $value,array $allowed): string {
    $value=trim($value); if ($value==='') return '';
    foreach($allowed as $option) {
        if($value===$option || str_starts_with($value,$option.' (') || str_starts_with($value,$option.'(')) return $option;
    }
    if (in_array($value,['Male','পুরুষ (Male)'],true)) return 'পুরুষ';
    if (in_array($value,['Female','নারী (Female)'],true)) return 'নারী';
    if (str_starts_with($value,'অন্যান্য')) return 'অন্যান্য/বলতে অনিচ্ছুক';
    return '';
}
function import_multichoice(string $value,array $allowed): array {
    $opts=[];
    foreach(preg_split('/\s*[,;|\n]+\s*/u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[] as $part) {
        $s=import_choice($part,$allowed);
        if($s!=='' && !in_array($s,$opts,true)) $opts[]=$s;
    }
    return $opts;
}
function import_clean_google(array $cells,array $headers): array {
    $c=array_pad($cells,count($headers),'');
    $d=default_data(); $simple=[
      2=>'form_no',4=>'project',6=>'name_en',7=>'name_bn',11=>'blood_group',12=>'occupation',13=>'education',
      14=>'id_number',19=>'mfs',21=>'mfs_relation',23=>'mother_name',25=>'father_name',
      27=>'present_village',28=>'present_union',29=>'present_upazila',
      30=>'permanent_village',31=>'permanent_union',32=>'permanent_upazila',
      38=>'languages',39=>'special_skills',41=>'experience_summary',44=>'support_needs',48=>'driving_license',
      50=>'volunteer_sign_name',51=>'collector_sign_name'
    ];
    unset($simple[19]);
    foreach($simple as $at=>$key) $d[$key]=trim((string)($c[$at]??''));
    $d['email']=trim((string)($c[1]??''));
    $d['response_timestamp']=import_timestamp($c[0]??'');
    $d['age_reported']=trim((string)($c[10]??''));
    $d['google_drive_link']=trim((string)($c[53]??''));
    $d['source_emergency_raw']=trim((string)($c[22]??''));
    $d['source_work_raw']=trim((string)($c[42]??''));
    $d['source_training_raw']=trim((string)($c[43]??''));
    $d['source_consent_raw']=trim((string)($c[49]??''));
    $d['registration_date']=import_date($c[5]??'');
    $d['dob']=import_date($c[9]??'');
    $d['gender']=import_choice((string)($c[8]??''),form_sections()['A']['fields']['gender'][2]);
    foreach([15=>'phone',16=>'alternate_phone',17=>'whatsapp',24=>'mother_phone',26=>'father_phone'] as $at=>$key) $d[$key]=import_phone($c[$at]??'');
    $d['smartphone']=import_choice((string)($c[18]??''),['হ্যাঁ','না']);
    $d['mfs']=import_multichoice((string)($c[19]??''),['বিকাশ','নগদ','রকেট','অন্যান্য']);
    $d['mfs_ownership']=import_choice((string)($c[20]??''),['নিজ','অন্য']);
    $emergency=trim((string)($c[22]??''));
    if($emergency!=='') {
       $parts=preg_split('/[,;\n]+/u',$emergency)?:[];
       foreach($parts as $part) {
          if(preg_match('/(?:Mobile|Phone|মোবাইল)\s*[:ঃ]\s*([+\d\s-]+)/iu',$part,$m)) $d['emergency_phone']=import_phone(preg_replace('/[^0-9+]/','',$m[1]));
          elseif(preg_match('/(?:সম্পর্ক|Relation)\s*[:ঃ]\s*(.+)/iu',$part,$m)) $d['emergency_relation']=trim($m[1]);
          elseif(preg_match('/(?:নাম|Name)\s*[:ঃ]\s*(.+)/iu',$part,$m)) $d['emergency_name']=trim($m[1]);
       }
       // Do not discard unstructured source text: original row is retained below.
    }
    foreach([33=>'interests',34=>'availability',35=>'time_available',36=>'travel_scope',40=>'digital_skills',47=>'transport',52=>'attachments'] as $at=>$field) {
        $d[$field]=import_multichoice((string)($c[$at]??''),array_values(form_sections()[['interests'=>'C','availability'=>'C','time_available'=>'C','travel_scope'=>'C','digital_skills'=>'C','transport'=>'E','attachments'=>'G'][$field]]['fields'][$field][2]));
    }
    foreach([37=>'emergency_duty',45=>'first_aid',46=>'swimming'] as $at=>$field) {
        $defs=all_fields(); $d[$field]=import_choice((string)($c[$at]??''),$defs[$field][2]);
    }
    $rawWork=trim((string)($c[42]??''));
    if($rawWork!=='') $d['work_history']=[['org'=>mb_safe_limit($rawWork,220),'duty'=>'','area'=>'','period'=>'','reference'=>'']];
    $rawTraining=trim((string)($c[43]??''));
    if($rawTraining!=='') $d['training_history']=[['title'=>mb_safe_limit($rawTraining,220),'organizer'=>'','period'=>'','certificate'=>'']];
    $consent=(string)($c[49]??'');
    $matches=['code'=>'আচরণবিধি','safeguarding'=>'শিশু সুরক্ষা','confidentiality'=>'গোপনীয়','data'=>'তথ্য ব্যবহার','emergency'=>'জরুরি যোগাযোগ','media'=>'ছবি'];
    foreach($matches as $id=>$part) if(strpos($consent,$part)!==false) $d['consents'][]=$id;
    $d['status']='অপেক্ষমাণ';
    $d['_source_google']=array_map(static fn($v)=>is_scalar($v)?(string)$v:'',$c);
    $d['_source_google'][0] = $d['response_timestamp'];
    $d['_source_google'][5] = $d['registration_date'];
    $d['_source_google'][9] = $d['dob'];
    foreach([15,16,17,24,26] as $at) $d['_source_google'][$at]=import_phone($c[$at]??'');
    return $d;
}
function import_clean_system(array $cells,array $headings,string $mode): array {
    $defs=all_fields();$all=export_headers();$rev=array_flip($all);
    $raw=[]; $meta=[];
    foreach ($headings as $i=>$heading) {
        $key=$mode==='codes' ? $heading : ($rev[$heading]??'');
        $value=trim((string)($cells[$i]??''));
        if($key==='' || $value==='') continue;
        if(in_array($key,['volunteer_code','source_type','public_visible','registered_on'],true)) { $meta[$key]=$value;continue; }
        if(preg_match('/^(work_history|training_history)_([1-4])_(\w+)$/',$key,$m)) {
            $raw[$m[1]][((int)$m[2])-1][$m[3]]=$value; continue;
        }
        if(!isset($defs[$key])) continue;
        $raw[$key]=$defs[$key][1]==='multi' ? import_multichoice($value,array_is_list($defs[$key][2])?$defs[$key][2]:array_keys($defs[$key][2])) : ($defs[$key][1]==='date' ? import_date($value) : (($defs[$key][1]==='tel')?import_phone($value):$value));
    }
    if(empty($raw['registration_date']) && !empty($meta['registered_on'])) $raw['registration_date']=import_date($meta['registered_on']);
    $d=sanitize_form_data($raw,true);
    $d['_import_code']=(string)($meta['volunteer_code']??'');
    // Imports never imply consent to public publication. Public profile remains private.
    return $d;
}
function import_csv_matrix(string $path): array {
    $f=fopen($path,'rb');if(!$f)throw new RuntimeException('ফাইল পড়া যাচ্ছে না।');
    $line=fgets($f);rewind($f);
    $delim=substr_count((string)$line,"\t")>substr_count((string)$line,',')?"\t":',';
    $rows=[];$n=0;
    while(($row=fgetcsv($f,0,$delim,'"',''))!==false){
        if(++$n>2501) {fclose($f);throw new RuntimeException('সর্বোচ্চ ২৫০০টি রেকর্ড একবারে Import করুন।');}
        if($n===1 && isset($row[0]))$row[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$row[0]);
        $rows[]=$row;
    }
    fclose($f);return $rows;
}
function import_xlsx_matrix(string $path): array {
    if(!class_exists('ZipArchive') || !class_exists('DOMDocument')) throw new RuntimeException('XLSX Import-এর জন্য PHP zip এবং dom extension প্রয়োজন। বিকল্প হিসেবে CSV ব্যবহার করুন।');
    $zip=new ZipArchive(); if($zip->open($path)!==true) throw new RuntimeException('বৈধ XLSX ফাইল নয়।');
    try {
        foreach(['xl/sharedStrings.xml','xl/worksheets/sheet1.xml'] as $name) {
           $stat=$zip->statName($name);
           if($stat && $stat['size']>16*1024*1024)throw new RuntimeException('XLSX ফাইল খুব বড়।');
        }
        $shared=[];$ss=$zip->getFromName('xl/sharedStrings.xml');
        if($ss!==false) {
            $dom=new DOMDocument();
            if(!@$dom->loadXML($ss,LIBXML_NONET))throw new RuntimeException('XLSX shared strings পড়া যায়নি।');
            $xp=new DOMXPath($dom);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach($xp->query('//m:si') as $si){
                $text='';foreach($xp->query('.//m:t',$si) as $t)$text.=$t->textContent;
                $shared[]=$text;
                if(count($shared)>100000)throw new RuntimeException('অতিরিক্ত Shared Strings।');
            }
        }
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        if($xml===false || strlen($xml)>16*1024*1024)throw new RuntimeException('প্রথম Worksheet পাওয়া যায়নি বা অস্বাভাবিক বড়।');
        $dom=new DOMDocument();if(!@$dom->loadXML($xml,LIBXML_NONET)) throw new RuntimeException('Worksheet XML সঠিক নয়।');
        $xp=new DOMXPath($dom);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows=[];
        foreach($xp->query('//m:sheetData/m:row') as $r){
            $out=[];
            foreach($xp->query('./m:c',$r) as $cell){
                $ref=$cell->getAttribute('r');if(!preg_match('/^([A-Z]{1,3})[0-9]+$/',$ref,$m))continue;
                $col=0;foreach(str_split($m[1]) as $letter)$col=$col*26+ord($letter)-64;
                $col--;
                if($col>250)throw new RuntimeException('এক্সেলে অতিরিক্ত কলাম।');
                if($xp->query('./m:f',$cell)->length)throw new RuntimeException('সূত্রসহ Excel Cell গ্রহণ করা হয় না; Values Only করে Save করুন।');
                $v=$xp->query('./m:v',$cell)->item(0);
                $val=$v ? $v->textContent : '';
                if($cell->getAttribute('t')==='s')$val=$shared[(int)$val]??'';
                elseif($cell->getAttribute('t')==='inlineStr') {
                    $val='';foreach($xp->query('.//m:t',$cell) as $t)$val.=$t->textContent;
                }
                $out[$col]=$val;
            }
            if($out) {
                $max=max(array_keys($out));$dense=array_fill(0,$max+1,'');
                foreach($out as $i=>$v)$dense[$i]=$v;
                $rows[]=$dense;
            }
            if(count($rows)>2501)throw new RuntimeException('সর্বোচ্চ ২৫০০টি রেকর্ড একবারে Import করুন।');
        }
        return $rows;
    } finally {$zip->close();}
}
function import_sheet_file(string $path,string $extension): array {
    $rows=$extension==='xlsx'?import_xlsx_matrix($path):import_csv_matrix($path);
    if(count($rows)<2)throw new RuntimeException('ফাইলে কোনো তথ্যের সারি নেই।');
    $headings=array_map(static fn($v)=>trim((string)$v),array_shift($rows));
    $format=import_detect_format($headings);
    if($format==='google' && $headings!==google_form_headers()) throw new RuntimeException('Google Forms-এর ৫৪টি কলামের ক্রম/নাম বদলানো হয়েছে। মূল ফাইল বা প্রদত্ত টেমপ্লেট ব্যবহার করুন।');
    return [$headings,$rows,$format];
}
/**
 * Import identity policy: a person is matched only by explicit previously issued ID
 * or a strong identity document. Names and phones are NEVER blocking keys.
 * A phone match is only a warning and may be shared by family members.
 * We snapshot the rows once per import, avoiding O(import x database) full scans.
 */
function import_existing_index(PDO $pdo): array {
    $index=['by_code'=>[],'by_document'=>[],'by_phone'=>[],'rows'=>[]];
    $query=$pdo->query('SELECT id,volunteer_code,uid,legacy_code,form_no,name_bn,name_en,phone,payload,deleted_at FROM volunteers ORDER BY id');
    while($v=$query->fetch(PDO::FETCH_ASSOC)){
        $id=(int)$v['id'];$index['rows'][$id]=$v;
        foreach(['volunteer_code','uid','legacy_code'] as $field){
            $code=strtoupper(trim((string)($v[$field]??'')));
            if($code!=='')$index['by_code'][$code][$id]=true;
        }
        $data=json_decode((string)($v['payload']??''),true)?:[];
        $doc=praan_normalize_document((string)($data['id_number']??''));
        if($doc!=='')$index['by_document'][$doc][$id]=true;
        $phone=praan_normalize_phone((string)($v['phone']??''));
        if(preg_match('/^01[3-9][0-9]{8}$/D',$phone))$index['by_phone'][$phone][$id]=true;
    }
    return $index;
}
function import_set_ids(array $map,string $key): array {
    return $key!==''?array_map('intval',array_keys($map[$key]??[])):[];
}
/** Returns a deterministic row classification with a masked explanation. */
function import_classify_row(array $d,array $index,array &$seen): array {
    $code=strtoupper(trim((string)($d['_import_code']??'')));
    $doc=praan_normalize_document((string)($d['id_number']??''));
    $phone=praan_normalize_phone((string)($d['phone']??''));
    $validPhone=(bool)preg_match('/^01[3-9][0-9]{8}$/D',$phone);
    foreach(array_filter(['code:'.$code,'document:'.$doc],static fn($k)=>!str_ends_with($k,':')) as $k){
        if(isset($seen[$k]))return ['type'=>'review','id'=>0,'reason'=>'এই Excel ফাইলের অন্য সারিতে একই Volunteer ID/NID/জন্মনিবন্ধন নম্বর আছে।','phone_warning'=>false];
        $seen[$k]=true;
    }
    $byCode=import_set_ids($index['by_code'],$code);
    $byDoc=import_set_ids($index['by_document'],$doc);
    $all=array_values(array_unique(array_merge($byCode,$byDoc)));
    $phoneMatches=$validPhone?import_set_ids($index['by_phone'],$phone):[];
    if(count($all)>1)return ['type'=>'review','id'=>0,'reason'=>'Volunteer ID এবং NID/জন্মনিবন্ধন নম্বর আলাদা বিদ্যমান রেকর্ডের সঙ্গে মিলে গেছে।','phone_warning'=>false];
    if(count($all)===1){
        $id=$all[0];$old=$index['rows'][$id];
        if(!empty($old['deleted_at']))return ['type'=>'review','id'=>$id,'reason'=>'আগে আর্কাইভ করা রেকর্ড পাওয়া গেছে; Restore করে তারপর আপডেট করুন।','phone_warning'=>false];
        $payload=json_decode((string)($old['payload']??''),true)?:[];
        $oldDoc=praan_normalize_document((string)($payload['id_number']??''));
        if($doc!==''&&$oldDoc!==''&&$oldDoc!==$doc)return ['type'=>'review','id'=>$id,'reason'=>'একই Volunteer ID কিন্তু ভিন্ন NID/জন্মনিবন্ধন নম্বর; যাচাই প্রয়োজন।','phone_warning'=>false];
        return ['type'=>'update','id'=>$id,'reason'=>count($byCode)?'আগের Volunteer ID মিলেছে; একই রেকর্ড আপডেট হবে।':'আগের NID/জন্মনিবন্ধন নম্বর মিলেছে; একই রেকর্ড আপডেট হবে।','phone_warning'=>false];
    }
    return ['type'=>'new','id'=>0,'reason'=>'নতুন স্বেচ্ছাসেবক হিসেবে যোগ হবে।','phone_warning'=>count($phoneMatches)>0];
}
function import_value_present(mixed $v): bool {
    return is_array($v)?count($v)>0:trim((string)$v)!=='';
}
/**
 * Non-destructive update: only non-empty imported cells replace old payload values.
 * Never touch existing photo, receipt, public visibility, source or display IDs.
 * Form numbers stay unchanged. Existing unspecified consent remains unchanged.
 */
function import_merge_existing(array $old,array $incoming,string $format): array {
    $previous=json_decode((string)($old['payload']??''),true);
    if(!is_array($previous))$previous=[];
    $merged=array_replace(default_data(),$previous);
    $definitions=all_fields();
    $keys=$incoming['_import_present_keys']??array_keys($definitions);
    foreach($keys as $key){
        if(!is_string($key)||!array_key_exists($key,$incoming)||!array_key_exists($key,$definitions))continue;
        if($key==='form_no')continue;
        if(import_value_present($incoming[$key]))$merged[$key]=$incoming[$key];
    }
    foreach(['work_history','training_history'] as $group) if(!empty($incoming[$group])&&is_array($incoming[$group]))$merged[$group]=$incoming[$group];
    if($format==='google'&&!empty($incoming['_source_google']))$merged['_source_google']=$incoming['_source_google'];
    // Import source details are auxiliary and should survive future export.
    foreach(['response_timestamp','source_emergency_raw','source_work_raw','source_training_raw','source_consent_raw'] as $key){
        if(import_value_present($incoming[$key]??''))$merged[$key]=$incoming[$key];
    }
    return $merged;
}
function import_prepare_row(array $cells,array $headings,string $mode): array {
    $data=$mode==='google'?import_clean_google($cells,$headings):import_clean_system($cells,$headings,$mode);
    // Present keys are populated from the original source, NOT sanitize_form_data defaults.
    if($mode==='google'){
        $present=[];
        $mapping=[2=>'form_no',4=>'project',5=>'registration_date',6=>'name_en',7=>'name_bn',8=>'gender',9=>'dob',11=>'blood_group',12=>'occupation',13=>'education',14=>'id_number',15=>'phone',16=>'alternate_phone',17=>'whatsapp',18=>'smartphone',19=>'mfs',20=>'mfs_ownership',21=>'mfs_relation',23=>'mother_name',24=>'mother_phone',25=>'father_name',26=>'father_phone',27=>'present_village',28=>'present_union',29=>'present_upazila',30=>'permanent_village',31=>'permanent_union',32=>'permanent_upazila',33=>'interests',34=>'availability',35=>'time_available',36=>'travel_scope',37=>'emergency_duty',38=>'languages',39=>'special_skills',40=>'digital_skills',41=>'experience_summary',44=>'support_needs',45=>'first_aid',46=>'swimming',47=>'transport',48=>'driving_license',49=>'consents',50=>'volunteer_sign_name',51=>'collector_sign_name',52=>'attachments'];
        foreach($mapping as $i=>$key)if(trim((string)($cells[$i]??''))!=='' && import_value_present($data[$key]??''))$present[$key]=true;
    } else {
        $rev=array_flip(export_headers());$present=[];$definitions=all_fields();
        foreach($headings as $i=>$header){
            $key=$mode==='codes'?(string)$header:(string)($rev[$header]??'');
            if(isset($definitions[$key])&&trim((string)($cells[$i]??''))!==''&&import_value_present($data[$key]??''))$present[$key]=true;
        }
    }
    $data['_import_present_keys']=array_keys($present);
    return $data;
}
/**
 * $existingMode=update is the default (upsert, not duplicate insertion).
 * Optional 'skip' avoids changing any already-matched record.
 * Preview and Commit use identical classification logic.
 */
function import_process(PDO $pdo,array $headings,array $rows,string $mode,bool $dryRun,string $existingMode='update'): array {
    if(!in_array($existingMode,['update','skip'],true))throw new RuntimeException('Import mode সঠিক নয়।');
    $result=['added'=>0,'updated'=>0,'skipped'=>0,'review'=>0,'duplicates'=>0,'invalid'=>0,'total'=>0,'phone_warnings'=>0,'warnings'=>[],'rows'=>[]];
    $index=import_existing_index($pdo);$seen=[];
    $permissionToUpdate=(!function_exists('praan_can')||praan_can('volunteers.edit'));
    $pdo->beginTransaction();
    try {
        foreach($rows as $offset=>$cells){
            if(!array_filter($cells,static fn($v)=>trim((string)$v)!==''))continue;
            $result['total']++;$rowNumber=$offset+2;
            $d=import_prepare_row($cells,$headings,$mode);
            $name=mb_safe_limit(trim((string)($d['name_bn']?:$d['name_en']??'')),65);
            $identifier=mb_safe_limit(trim((string)($d['_import_code']??'')),45);
            $row=['line'=>$rowNumber,'code'=>$identifier,'name'=>$name,'status'=>'','reason'=>''];
            if($name===''){$result['invalid']++;$row['status']='invalid';$row['reason']='বাংলা বা ইংরেজি নাম নেই।';}
            else {
                if(empty($d['registration_date']))$d['registration_date']=date('Y-m-d');
                if(empty($d['status']))$d['status']='অপেক্ষমাণ';
                $c=import_classify_row($d,$index,$seen);
                $row['status']=$c['type'];$row['reason']=$c['reason'];
                if($c['phone_warning']){
                    $result['phone_warnings']++;
                    $row['reason'].=' একই মোবাইল অন্য রেকর্ডেও আছে—শেয়ার্ড ফোন হিসেবে গ্রহণযোগ্য।';
                }
                if($c['type']==='review'){$result['review']++;}
                elseif($c['type']==='update'){
                    $result['duplicates']++; // legacy metric; not an import rejection
                    if($existingMode==='skip'){$result['skipped']++;$row['status']='skipped';$row['reason']='আগের রেকর্ড অপরিবর্তিত রাখা হবে।';}
                    elseif(!$permissionToUpdate){$result['review']++;$row['status']='review';$row['reason']='বিদ্যমান রেকর্ড আপডেটের অনুমতি নেই।';}
                    else {
                        $result['updated']++;
                        if(!$dryRun){
                            $old=fetch_volunteer((int)$c['id']);
                            if(!$old)throw new RuntimeException('Preview-এর পর একটি রেকর্ড পরিবর্তিত হয়েছে; আবার Import Preview করুন।');
                            $merged=import_merge_existing($old,$d,$mode);
                            if(function_exists('praan_can')&&!praan_can('volunteers.approve'))$merged['status']=$old['status'];
                            save_volunteer($merged,$old,(string)$old['source_type'],(int)$old['public_visible'],(string)$old['photo_file']);
                        }
                    }
                } else {
                    $result['added']++;
                    if(!$dryRun){
                        unset($d['_import_present_keys']);
                        save_volunteer($d,null,'excel',0,'');
                    }
                }
            }
            if(count($result['rows'])<150)$result['rows'][]=$row;
        }
        if($dryRun)$pdo->rollBack();
        else {
            log_action(null,'excel_import','format='.$mode.' new='.$result['added'].' updated='.$result['updated'].' skipped='.$result['skipped'].' review='.$result['review'].' invalid='.$result['invalid']);
            $pdo->commit();
        }
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return $result;
}
/** Reconstruct original Google form headings, preserving source cells that are not individually structured. */
function google_row_for_export(array $v): array {
    $d=json_decode((string)$v['payload'],true)?:[];
    $out=array_fill(0,count(google_form_headers()),'');
    if(isset($d['_source_google']) && is_array($d['_source_google'])) {
        foreach($d['_source_google'] as $i=>$value)if(is_int($i)&&isset($out[$i]))$out[$i]=(string)$value;
    }
    $lookup=[2=>'form_no',4=>'project',5=>'registration_date',6=>'name_en',7=>'name_bn',8=>'gender',9=>'dob',11=>'blood_group',12=>'occupation',13=>'education',14=>'id_number',15=>'phone',16=>'alternate_phone',17=>'whatsapp',18=>'smartphone',19=>'mfs',20=>'mfs_ownership',21=>'mfs_relation',23=>'mother_name',24=>'mother_phone',25=>'father_name',26=>'father_phone',27=>'present_village',28=>'present_union',29=>'present_upazila',30=>'permanent_village',31=>'permanent_union',32=>'permanent_upazila',33=>'interests',34=>'availability',35=>'time_available',36=>'travel_scope',37=>'emergency_duty',38=>'languages',39=>'special_skills',40=>'digital_skills',41=>'experience_summary',44=>'support_needs',45=>'first_aid',46=>'swimming',47=>'transport',48=>'driving_license',50=>'volunteer_sign_name',51=>'collector_sign_name',52=>'attachments'];
    foreach($lookup as $i=>$key) {
        $value=display_value($d[$key]??'');
        // Blank structured values should not erase pre-existing original text.
        if($value!=='')$out[$i]=$value;
    }
    $out[3]=(string)($v['volunteer_code']??'');
    foreach([0=>'response_timestamp',10=>'age_reported',22=>'source_emergency_raw',42=>'source_work_raw',43=>'source_training_raw',49=>'source_consent_raw',53=>'google_drive_link'] as $i=>$key) if(trim((string)($d[$key]??''))!=='')$out[$i]=(string)$d[$key];
    $out[1]=(string)($d['email']??$out[1]);
    return $out;
}
function google_export_rows(?array $filters = null): iterable {
    $q=$filters===null?db()->query('SELECT * FROM volunteers WHERE deleted_at IS NULL ORDER BY id ASC'):analytics_query(db(),$filters,'v.*','ORDER BY v.id ASC');
    while($v=$q->fetch())yield google_row_for_export($v);
}
