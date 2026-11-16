<?php
declare(strict_types=1);
require_once __DIR__ . '/analytics.php';

function export_headers(): array {
    $headers = ['volunteer_code'=>'Volunteer ID','source_type'=>'Source','registered_on'=>'Registration date','public_visible'=>'Public listing consent'];
    foreach (all_fields() as $key=>$def) $headers[$key] = $def[0];
    foreach (['work_history'=>['org'=>'সংস্থা','duty'=>'দায়িত্ব','area'=>'এলাকা','period'=>'সময়কাল','reference'=>'রেফারেন্স'], 'training_history'=>['title'=>'প্রশিক্ষণ','organizer'=>'আয়োজক','period'=>'সময়কাল','certificate'=>'সনদ']] as $group=>$cols) {
        for ($i=1;$i<=4;$i++) foreach ($cols as $key=>$label) $headers[$group.'_'.$i.'_'.$key] = ($group==='work_history'?'কর্মঅভিজ্ঞতা ':'প্রশিক্ষণ ').$label.' '.$i;
    }
    return $headers;
}
function export_rows(?array $filters = null): iterable {
    $q = $filters === null ? db()->query('SELECT * FROM volunteers WHERE deleted_at IS NULL ORDER BY id ASC') : analytics_query(db(), $filters, 'v.*', 'ORDER BY v.id ASC');
    $headers = export_headers();
    while ($v=$q->fetch()) {
        $d = json_decode((string)$v['payload'],true);
        if(!is_array($d)) $d=[];
        $row=[];
        foreach ($headers as $key=>$label) {
            if(array_key_exists($key,$v)) $val=$v[$key];
            elseif(array_key_exists($key,$d)) $val=$d[$key];
            else {
                $val='';
                if(preg_match('/^(work_history|training_history)_([1-4])_(.+)$/',$key,$m)) $val=$d[$m[1]][((int)$m[2])-1][$m[3]]??'';
            }
            $row[] = display_value($val);
        }
        yield $row;
    }
}
function export_csv(?array $filters = null): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="praan_volunteers_'.date('Y-m-d').'.csv"');
    header('Cache-Control: no-store');
    $out=fopen('php://output','wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out,array_values(export_headers()));
    foreach(export_rows($filters) as $row) {
        // Spreadsheet software interprets leading = + - @ as formulas: neutralize.
        $safe=array_map(fn($value)=>preg_match('/^[\s]*[=+\-@]/u',$value) ? "'".$value : $value,$row);
        fputcsv($out,$safe);
    }
    fclose($out); exit;
}
function xml_safe(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE, 'UTF-8'); }
function xlsx_column(int $i): string {
    $s=''; $i++;
    do { $i--; $s=chr(65 + ($i % 26)).$s; $i=intdiv($i,26); } while ($i>0);
    return $s;
}
function zip_basic(array $files): string {
    // Small dependency-free ZIP: ZIP format is generated with zlib, not PHP zip extension.
    $data=''; $central=''; $offset=0; $total=0;
    foreach($files as $filename=>$contents) {
        $raw=(string)$contents; $compressed=gzdeflate($raw,6);
        $crc=crc32($raw); $length=strlen($raw); $clen=strlen($compressed); $nlen=strlen($filename);
        $local=pack('VvvvvvVVVvv',0x04034b50,20,0,8,0,0,$crc,$clen,$length,$nlen,0).$filename.$compressed;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,8,0,0,$crc,$clen,$length,$nlen,0,0,0,0,0,$offset).$filename;
        $data.=$local; $offset+=strlen($local); $total++;
    }
    $end=pack('VvvvvVVv',0x06054b50,0,0,$total,$total,strlen($central),strlen($data),0);
    return $data.$central.$end;
}
function xlsx_binary(array $headings, iterable $rows): string {
    $r=1;
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols><col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="24" customWidth="1"/><col min="3" max="200" width="23" customWidth="1"/></cols><sheetData>';
    $render=function(array $items, bool $header = false) use (&$xml,&$r) {
        $xml.='<row r="'.$r.'"'.($header?' ht="28" customHeight="1"':'').'>';
        foreach($items as $i=>$value) {
            $val=xml_safe((string)$value);
            $xml.='<c r="'.xlsx_column($i).$r.'"'.($header?' s="1"':'').' t="inlineStr"><is><t xml:space="preserve">'.$val.'</t></is></c>';
        }
        $xml.='</row>'; $r++;
    };
    $render($headings,true);
    foreach($rows as $row) $render($row);
    $xml.='</sheetData><autoFilter ref="A1:'.xlsx_column(count($headings)-1).max(1,$r-1).'"/></worksheet>';
    $styles='<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF127C87"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFill="1" applyFont="1"/></cellXfs></styleSheet>';
    $files=[
        '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="PRAAN Volunteers" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml'=>$styles,
        'xl/worksheets/sheet1.xml'=>$xml,
    ];
    return zip_basic($files);
}
function export_xlsx(?array $filters = null): never {
    $binary=xlsx_binary(array_values(export_headers()),export_rows($filters));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="praan_volunteers_'.date('Y-m-d').'.xlsx"');
    header('Content-Length: '.strlen($binary)); header('Cache-Control: no-store');
    echo $binary; exit;
}
function template_csv(): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="praan_bulk_import_template.csv"');
    $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['form_no','name_bn','name_en','phone','gender','blood_group','occupation','education','present_district','present_upazila','present_union','project','registration_date','status']);
    fputcsv($out,['001','উদাহরণ স্বেচ্ছাসেবক','EXAMPLE VOLUNTEER','01700000000','পুরুষ','O+','শিক্ষার্থী','HSC','নোয়াখালী','সদর','নোয়াখালী পৌরসভা','PRAAN','2026-11-15','অপেক্ষমাণ']);
    fclose($out); exit;
}

/** Privacy-first aggregated reporting, suitable to share internally without personal fields. */
function report_summary_rows(array $summary, array $filters): iterable {
    $m=$summary['metrics'];
    yield ['সারসংক্ষেপ', 'মোট স্বেচ্ছাসেবক', (string)$m['total'], '100%'];
    foreach([
        'active'=>'সক্রিয়', 'pending'=>'অপেক্ষমাণ', 'paper'=>'কাগজের ফর্ম',
        'trained'=>'প্রশিক্ষণের তথ্যযুক্ত স্বেচ্ছাসেবক', 'total_trainings'=>'মোট প্রশিক্ষণের এন্ট্রি',
        'districts'=>'অনন্য জেলার সংখ্যা','public'=>'সক্রিয় পাবলিক প্রোফাইল',
        'no_phone'=>'মোবাইল তথ্য নেই','no_district'=>'জেলা তথ্য নেই',
        'no_interests'=>'আগ্রহ/দক্ষতা তথ্য নেই', 'no_consents'=>'সম্মতির তথ্য এন্ট্রি নেই'
    ] as $key=>$label) {
        yield ['সারসংক্ষেপ',$label,(string)$m[$key], in_array($key,['total_trainings','districts'],true)?'':analytics_percentage($m[$key],$m['total']).'%'];
    }
    foreach([
        'status'=>'স্ট্যাটাস','gender'=>'লিঙ্গ','source_type'=>'ডাটা এন্ট্রির উৎস',
        'district'=>'জেলা','upazila'=>'উপজেলা','project'=>'প্রকল্প','category'=>'ক্যাটাগরি',
        'blood_group'=>'রক্তের গ্রুপ','interests'=>'কাজের আগ্রহ',
        'digital_skills'=>'ডিজিটাল দক্ষতা','emergency_duty'=>'জরুরি দায়িত্ব'
    ] as $column=>$label) {
        foreach(($summary['groups'][$column]??[]) as $name=>$count) {
            yield [$label,(string)$name,(string)$count,analytics_percentage((int)$count,$m['total']).'%'];
        }
    }
    foreach($summary['months'] as $month=>$count) {
        yield ['মাসভিত্তিক নিবন্ধন',$month,(string)$count,analytics_percentage((int)$count,$m['total']).'%'];
    }
}
function report_summary_download(string $format,array $filters): never {
    $summary=analytics_summary(db(),$filters);
    $head=['বিভাগ / Category','বিবরণ / Item','সংখ্যা / Count','অনুপাত / Percentage'];
    $rows=report_summary_rows($summary,$filters);
    header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');
    if($format==='csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="praan_report_summary_'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$head);
        foreach($rows as $row) fputcsv($out,array_map(fn($s)=>preg_match('/^[\s]*[=+\-@]/u',(string)$s)?"'".$s:$s,$row));
        fclose($out);exit;
    }
    $binary=xlsx_binary($head,$rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="praan_report_summary_'.date('Y-m-d').'.xlsx"');
    header('Content-Length: '.strlen($binary));echo $binary;exit;
}
