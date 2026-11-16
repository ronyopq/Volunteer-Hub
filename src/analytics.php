<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Read-only, admin-only reporting helpers. No schema changes are required in v1.1.0. */
function analytics_filters(array $input): array {
    $filters = [];
    foreach (['q','status','district','gender','source_type','project','category'] as $key) {
        $value = $input[$key] ?? '';
        $filters[$key] = is_scalar($value) ? mb_safe_limit(trim((string)$value), 100) : '';
    }
    foreach (['date_from','date_to'] as $key) {
        $value = $input[$key] ?? '';
        $filters[$key] = is_string($value) && valid_date($value) ? $value : '';
    }
    if ($filters['date_from'] && $filters['date_to'] && $filters['date_from'] > $filters['date_to']) {
        // A reversed interval is empty, not silently swapped.
        $filters['date_invalid'] = '1';
    }
    return $filters;
}
function analytics_conditions(array $f): array {
    $clauses = ['v.deleted_at IS NULL']; $bindings = [];
    foreach (['status','district','gender','source_type','project','category'] as $field) {
        if (($f[$field] ?? '') !== '') {
            $clauses[] = 'v.'.$field.' = :'.$field;
            $bindings[':'.$field] = $f[$field];
        }
    }
    if (!empty($f['date_from'])) {$clauses[]='v.registered_on >= :date_from';$bindings[':date_from']=$f['date_from'];}
    if (!empty($f['date_to'])) {$clauses[]='v.registered_on <= :date_to';$bindings[':date_to']=$f['date_to'];}
    if (!empty($f['date_invalid'])) $clauses[]='1=0';
    if (($f['q'] ?? '') !== '') {
        $term = '%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$f['q']).'%';
        $parts=[];
        foreach (praan_can('volunteers.sensitive')?['name_bn','name_en','volunteer_code','phone','form_no']:['name_bn','name_en','volunteer_code','form_no'] as $i=>$col) {
            $name=':search'.$i;
            $parts[]='v.'.$col." LIKE $name ESCAPE '\\'";
            $bindings[$name]=$term;
        }
        $clauses[]='('.implode(' OR ',$parts).')';
    }
    return [implode(' AND ',$clauses), $bindings];
}
function analytics_query(PDO $pdo, array $f, string $columns = 'v.*', string $suffix = ''): PDOStatement {
    [$where,$bindings] = analytics_conditions($f);
    $stmt = $pdo->prepare("SELECT $columns FROM volunteers v WHERE $where $suffix");
    $stmt->execute($bindings);
    return $stmt;
}
function analytics_link(string $page, array $filters, array $changes = []): string {
    $all = array_merge($filters, $changes);
    unset($all['date_invalid']);
    return url($page, array_filter($all,static fn($v) => $v !== '' && $v !== null));
}
function analytics_filter_options(PDO $pdo): array {
    $result=[];
    foreach (['status','district','gender','source_type','project','category'] as $col) {
        $rows=$pdo->query("SELECT DISTINCT $col FROM volunteers WHERE deleted_at IS NULL AND $col IS NOT NULL AND $col <> '' ORDER BY $col COLLATE NOCASE LIMIT 150")->fetchAll(PDO::FETCH_COLUMN);
        $result[$col] = array_map('strval',$rows);
    }
    return $result;
}
function analytics_tally(array &$groups, string $category, string $value): void {
    $value=trim($value);
    if ($value==='') $value='তথ্য নেই';
    if (mb_strlen_safe($value)>90) $value=mb_safe_limit($value,90).'…';
    $groups[$category][$value]=($groups[$category][$value]??0)+1;
}
function mb_strlen_safe(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value) : count(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[]);
}
function analytics_summary(PDO $pdo, array $filters): array {
    $metrics = array_fill_keys(['total','active','pending','paper','public','new_month','trained','districts','no_phone','no_district','no_interests','no_consents','total_trainings'],0);
    $groups=[]; $recent=[]; $districtDistinct=[]; $months=[];
    $thisMonth=date('Y-m');
    $stmt=analytics_query($pdo,$filters,'v.id,v.volunteer_code,v.name_bn,v.name_en,v.phone,v.district,v.gender,v.status,v.source_type,v.registered_on,v.created_at,v.project,v.category,v.blood_group,v.public_visible,v.payload','ORDER BY v.id DESC');
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
        $metrics['total']++;
        if ($row['status']==='সক্রিয়') $metrics['active']++;
        if ($row['status']==='অপেক্ষমাণ') $metrics['pending']++;
        if ($row['source_type']==='paper') $metrics['paper']++;
        if ((int)$row['public_visible']===1 && $row['status']==='সক্রিয়') $metrics['public']++;
        if (substr((string)$row['created_at'],0,7)===$thisMonth) $metrics['new_month']++;
        if (trim((string)$row['phone'])==='') $metrics['no_phone']++;
        if (trim((string)$row['district'])==='') $metrics['no_district']++;
        if (trim((string)$row['district'])!=='') $districtDistinct[mb_strtolower_safe(trim((string)$row['district']))]=1;
        foreach (['status','gender','source_type','district','project','category','blood_group'] as $col) analytics_tally($groups,$col,(string)($row[$col]??''));
        $payload=json_decode((string)$row['payload'],true);
        if (!is_array($payload)) $payload=[];
        analytics_tally($groups,'upazila',(string)($payload['present_upazila']??''));
        analytics_tally($groups,'emergency_duty',(string)($payload['emergency_duty']??''));
        $interests = is_array($payload['interests']??null) ? array_unique($payload['interests']) : [];
        $digital = is_array($payload['digital_skills']??null) ? array_unique($payload['digital_skills']) : [];
        if (!$interests && !$digital && trim((string)($payload['special_skills']??''))==='') $metrics['no_interests']++;
        foreach($interests as $interest) if(is_string($interest) && $interest!=='') analytics_tally($groups,'interests',$interest);
        foreach($digital as $skill) if(is_string($skill) && $skill!=='') analytics_tally($groups,'digital_skills',$skill);
        $consents = is_array($payload['consents']??null) ? $payload['consents'] : [];
        if (!$consents) $metrics['no_consents']++;
        $training = is_array($payload['training_history']??null) ? $payload['training_history'] : [];
        $validTrainings=0;
        foreach($training as $entry) if(is_array($entry) && trim((string)($entry['title']??''))!=='') $validTrainings++;
        if($validTrainings>0) $metrics['trained']++;
        $metrics['total_trainings'] += $validTrainings;
        $month=substr((string)$row['registered_on'],0,7);
        if(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) $months[$month]=($months[$month]??0)+1;
        if(count($recent)<8) $recent[]=$row;
    }
    $metrics['districts']=count($districtDistinct);
    foreach ($groups as &$counts) arsort($counts,SORT_NUMERIC);
    unset($counts);
    ksort($months);
    $trend=[];
    $endMonth = !empty($filters['date_to']) ? $filters['date_to'] : (array_key_last($months) ? array_key_last($months).'-01' : date('Y-m-01'));
    $end=new DateTimeImmutable(substr($endMonth,0,7).'-01');
    for($i=(int)praan_module_option('reports','trend_months')-1;$i>=0;$i--) {
        $key=$end->modify('-'.$i.' months')->format('Y-m');
        $trend[]=['month'=>$key,'count'=>$months[$key]??0];
    }
    return compact('metrics','groups','recent','trend','months');
}
function mb_strtolower_safe(string $text): string {return function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);}
function analytics_percentage(int $count,int $total): string {return $total ? number_format(($count/$total)*100,1) : '0.0';}
