<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
/** Minimal dependency-free OOXML writer; does not execute user-submitted markup. */
function praan_docx_xml(mixed $s): string {
    return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)($s??'')) ?? '', ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8');
}
function praan_docx_paragraph(string $text, int $size=18, bool $bold=false, string $color='18354A', string $align='left'): string {
    // OOXML size is measured in half-points (18 = 9pt).
    return '<w:p><w:pPr><w:spacing w:before="0" w:after="16" w:line="210" w:lineRule="auto"/><w:jc w:val="'.$align.'"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Nirmala UI" w:hAnsi="Nirmala UI" w:cs="Nirmala UI"/><w:sz w:val="'.$size.'"/><w:szCs w:val="'.$size.'"/>'.($bold?'<w:b/>':'').'<w:color w:val="'.$color.'"/></w:rPr><w:t xml:space="preserve">'.praan_docx_xml($text).'</w:t></w:r></w:p>';
}
/** Inline DrawingML images; embedded locally, no remote reference. */
function praan_docx_drawing(string $relationship, int $width, int $height, int $id, string $alt): string {
    $graphic='<wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:docPr id="'.$id.'" name="PRAAN image" descr="'.praan_docx_xml($alt).'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="'.$id.'" name="PRAAN image"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$relationship.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline>';
    return '<w:p><w:pPr><w:spacing w:after="20"/></w:pPr><w:r><w:drawing>'.$graphic.'</w:drawing></w:r></w:p>';
}
function praan_docx_table(array $pairs): string {
    if(!$pairs)return '';
    $xml='<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="D6E4EA"/><w:left w:val="single" w:sz="4" w:color="D6E4EA"/><w:bottom w:val="single" w:sz="4" w:color="D6E4EA"/><w:right w:val="single" w:sz="4" w:color="D6E4EA"/><w:insideH w:val="single" w:sz="4" w:color="D6E4EA"/><w:insideV w:val="single" w:sz="4" w:color="D6E4EA"/></w:tblBorders><w:tblCellMar><w:top w:w="54" w:type="dxa"/><w:bottom w:w="54" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="4700"/><w:gridCol w:w="4700"/></w:tblGrid>';
    foreach(array_chunk($pairs,2) as $row) {
        $xml.='<w:tr>';
        foreach([0,1] as $i){[$label,$val,$primary]=$row[$i]??['','',''];
            $xml.='<w:tc><w:tcPr><w:tcW w:w="4700" w:type="dxa"/></w:tcPr>';
            $xml.=praan_docx_paragraph((string)$label,14,true,'526D84');
            $xml.=praan_docx_paragraph((string)($val?:'—'),$primary?24:18,(bool)$primary,'163A55');
            $xml.='</w:tc>';
        }
        $xml.='</w:tr>';
    }
    return $xml.'</w:tbl>';
}
function praan_docx_section(string $section, array $data, array $fieldDefs): string {
    $xml=praan_docx_paragraph($section,21,true,'116D77');$fields=[];
    foreach($fieldDefs as $key=>$def){
        if(in_array($key,['response_timestamp','source_emergency_raw','source_work_raw','source_training_raw','source_consent_raw'],true))continue;
        $value=$data[$key]??'';
        if($key==='consents'){
            $choices=$def[2];$value=is_array($value)?implode(' | ',array_map(fn($c)=>($choices[$c]??$c),$value)):'';
        }else $value=display_value($value);
        $fields[]=[$def[0],$value,in_array($key,['name_bn','name_en','phone','alternate_phone','whatsapp'],true)];
    }
    return $xml.praan_docx_table($fields);
}
function praan_docx_history(string $title, array $rows, array $cols): string {
    $xml=praan_docx_paragraph($title,19,true,'116D77');
    if(!$rows) return $xml.praan_docx_paragraph('—',16);
    foreach(array_slice($rows,0,4) as $row){
        $value=[];foreach($cols as $col=>$label)$value[]=$label.': '.(string)($row[$col]??'');
        $xml.=praan_docx_paragraph(implode(' | ',$value),15);
    }
    return $xml;
}
/** Editable 3-page template, closely matching the A4 print: styled A-H sections and grid cells. */
function praan_docx_template_binary(array $volunteer): string {
    $template=__DIR__.'/../resources/praan_print_layout_3page.docx';
    if(!is_file($template))throw new RuntimeException('PRAAN DOCX template missing');
    if(!class_exists('ZipArchive'))throw new RuntimeException('PHP ZipArchive extension চালু করুন।');
    $d=$volunteer['data']??[];
    $values=$d;
    foreach($values as $key=>$value)$values[$key]=display_value($value);
    $values['form_no']=(string)$volunteer['form_no'];
    $values['volunteer_code']=(string)$volunteer['volunteer_code'];
    $values['registration_date']=(string)($d['registration_date']?:($volunteer['registered_on']??''));
    $values['age_reported']=(string)($d['age_reported']?:age_from_date((string)($d['dob']??'')));
    $values['present_address']=implode(' · ',array_filter(array_map('trim',array_map('strval',array_intersect_key($d,array_flip(['present_holding','present_village','present_ward','present_union','present_upazila','present_district']))))));
    $values['permanent_address']=implode(' · ',array_filter(array_map('trim',array_map('strval',array_intersect_key($d,array_flip(['permanent_holding','permanent_village','permanent_ward','permanent_union','permanent_upazila','permanent_district']))))));
    $values['emergency_details']=trim((string)($d['emergency_relation']??'').' · '.(string)($d['emergency_phone']??''),' ·');
    $values['volunteer_sign']=trim((string)($d['volunteer_sign_name']??'').' · '.(string)($d['volunteer_sign_date']??''),' ·');
    $values['collector_sign']=trim((string)($d['collector_sign_name']??'').' · '.(string)($d['collector_sign_date']??''),' ·');
    $values['consents']='';
    foreach(form_sections()['F']['fields']['consents'][2] as $key=>$label) {
        $values['consents'].=(in_array($key,(array)($d['consents']??[]),true)?'☑ ':'☐ ').$label.'   ';
    }
    foreach(['interests'=>'interests_other','digital_skills'=>'digital_skills_other','attachments'=>'attachments_other'] as $main=>$extra){
        if(!empty($d[$extra]))$values[$main]=trim((string)($values[$main]??'').' · '.(string)$d[$extra]);
    }
    if(!empty($d['google_drive_link']))$values['attachments']=trim((string)$values['attachments'].' · Drive: '.(string)$d['google_drive_link']);
    foreach(['work'=>['work_history',['org','duty','area','period','reference']], 'training'=>['training_history',['title','organizer','period','certificate']]] as $prefix=>$info){
        foreach(range(0,3) as $i)foreach($info[1] as $j=>$key) $values[$prefix.'_'.$i.'_'.$j]=(string)($d[$info[0]][$i][$key]??'');
    }
    $path=tempnam(sys_get_temp_dir(),'praan_form_');if(!$path)throw new RuntimeException('Temporary file unavailable');
    if(!copy($template,$path)){@unlink($path);throw new RuntimeException('DOCX template could not be copied');}
    $zip=new ZipArchive();
    if($zip->open($path)!==true){@unlink($path);throw new RuntimeException('DOCX could not be opened');}
    try{
        $xml=$zip->getFromName('word/document.xml');if(!is_string($xml))throw new RuntimeException('Invalid Word template');
        $xml=preg_replace_callback('/\[\[([a-z][a-z0-9_]*)\]\]/',static function($m)use($values){return praan_docx_xml($values[$m[1]]??'');},$xml);
        if(!is_string($xml))throw new RuntimeException('Could not fill DOCX template');
        $zip->addFromString('word/document.xml',$xml);
        $picture=(string)($volunteer['photo_file']??'');$photoPath=PHOTO_DIR.'/'.basename($picture);
        if($picture!=='' && is_file($photoPath) && filesize($photoPath)<=4000000 && extension_loaded('gd')){
            $info=@getimagesize($photoPath);
            if(is_array($info) && in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)){
                $binary=file_get_contents($photoPath);$source=$binary?@imagecreatefromstring($binary):false;
                if($source!==false){
                    $image=imagecreatetruecolor(180,220);$fill=imagecolorallocate($image,243,247,249);imagefill($image,0,0,$fill);
                    $w=imagesx($source);$h=imagesy($source);$ratio=max(180/$w,220/$h);$sw=(int)round(180/$ratio);$sh=(int)round(220/$ratio);
                    imagecopyresampled($image,$source,0,0,max(0,(int)(($w-$sw)/2)),max(0,(int)(($h-$sh)/2)),180,220,min($sw,$w),min($sh,$h));
                    ob_start();imagepng($image);$photoPng=ob_get_clean();imagedestroy($source);imagedestroy($image);
                    if(is_string($photoPng))$zip->addFromString('word/media/image1.png',$photoPng);
                }
            }
        }
    }finally{$zip->close();}
    $binary=file_get_contents($path);@unlink($path);
    if(!is_string($binary)||strlen($binary)<2000)throw new RuntimeException('DOCX could not be generated');
    return $binary;
}
function praan_docx_binary(array $volunteer): string {
    if(is_file(__DIR__.'/../resources/praan_print_layout_3page.docx'))return praan_docx_template_binary($volunteer);
    if(!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension চালু করুন।');
    $d=$volunteer['data']??[];$sections=form_sections();
    $xml=praan_docx_drawing('rIdLogo',810000,255000,2,'PRAAN logo');
    $xml.=praan_docx_paragraph('PRAAN',28,true,'087E80','center');
    $xml.=praan_docx_paragraph('স্বেচ্ছাসেবক নিবন্ধন ও প্রোফাইল ফর্ম',22,true,'1B3C58','center');
    $xml.=praan_docx_paragraph('YOUTH VOLUNTEER REGISTRATION & PROFILE FORM',14,true,'64768A','center');
    $photo='';$photoExt='';
    if(!empty($volunteer['photo_file'])) {
        $path=PHOTO_DIR.'/'.basename((string)$volunteer['photo_file']);
        if(is_file($path) && filesize($path)<3145728) {
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
            if($mime==='image/jpeg')$photoExt='jpg';elseif($mime==='image/png')$photoExt='png';
            if($photoExt)$photo=(string)file_get_contents($path);
        }
    }
    if($photo!=='')$xml.=praan_docx_drawing('rIdPhoto',565000,680000,3,'Volunteer portrait');
    $xml.=praan_docx_table([
       ['স্বেচ্ছাসেবক আইডি / Volunteer ID',(string)($volunteer['volunteer_code']??''),true],
       ['ফর্ম নম্বর',(string)($d['form_no']??''),false],
       ['প্রকল্প/কর্মসূচি',(string)($d['project']??''),false],
       ['নিবন্ধনের তারিখ',(string)($d['registration_date']??''),false],
    ]);
    foreach(['A','B'] as $s)$xml.=praan_docx_section($sections[$s]['title'],$d,$sections[$s]['fields']);
    $xml.=praan_docx_paragraph('পৃষ্ঠা ১ / ৩',13,false,'7F93A3','center');
    $xml.='<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    foreach(['C','D'] as $s)$xml.=praan_docx_section($sections[$s]['title'],$d,$sections[$s]['fields']);
    $xml.=praan_docx_history('পূর্ববর্তী কাজের অভিজ্ঞতা',(array)($d['work_history']??[]),['org'=>'সংস্থা','duty'=>'দায়িত্ব','area'=>'এলাকা','period'=>'সময়কাল','reference'=>'যোগাযোগ']);
    $xml.=praan_docx_history('প্রাসঙ্গিক প্রশিক্ষণ',(array)($d['training_history']??[]),['title'=>'প্রশিক্ষণ','organizer'=>'আয়োজক','period'=>'সময়কাল','certificate'=>'সনদ']);
    $xml.=praan_docx_paragraph('পৃষ্ঠা ২ / ৩',13,false,'7F93A3','center');
    $xml.='<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    foreach(['E','F','G','H'] as $s)$xml.=praan_docx_section($sections[$s]['title'],$d,$sections[$s]['fields']);
    $xml.=praan_docx_paragraph('পৃষ্ঠা ৩ / ৩ · শুধুমাত্র অনুমোদিত ব্যবহারের জন্য',13,false,'7F93A3','center');
    $document='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'.$xml.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="620" w:right="680" w:bottom="620" w:left="680" w:header="280" w:footer="280"/></w:sectPr></w:body></w:document>';
    $parts=[
      '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
      '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
      'word/document.xml'=>$document,
      'word/_rels/document.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/praan-logo.png"/>'.($photo!==''?'<Relationship Id="rIdPhoto" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/volunteer.'.$photoExt.'"/>':'').'</Relationships>',
      'word/media/praan-logo.png'=>(string)file_get_contents(__DIR__.'/../assets/pran-logo.png'),
    ];
    if($photo!=='')$parts['word/media/volunteer.'.$photoExt]=$photo;
    $path=tempnam(sys_get_temp_dir(),'praan_docx_');if(!$path)throw new RuntimeException('Temporary file unavailable');
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::OVERWRITE)!==true){@unlink($path);throw new RuntimeException('DOCX ZIP open failed');}
    foreach($parts as $filename=>$content)$zip->addFromString($filename,$content);
    $zip->close();$binary=file_get_contents($path);@unlink($path);
    if(!is_string($binary)||strlen($binary)<100)throw new RuntimeException('DOCX could not be generated');
    return $binary;
}
