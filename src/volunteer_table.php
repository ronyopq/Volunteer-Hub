<?php
declare(strict_types=1);
require_once __DIR__.'/import_tools.php';

function table_sort(array $input): array {
    $allowed=['id'=>'v.id','name'=>'v.name_bn','code'=>'v.volunteer_code','phone'=>'v.phone','district'=>'v.district','date'=>'v.registered_on','status'=>'v.status','created'=>'v.created_at'];
    $field=(string)($input['sort']??'id');if(!isset($allowed[$field]))$field='id';
    $direction=strtolower((string)($input['dir']??'desc'))==='asc'?'ASC':'DESC';
    return [$field,$allowed[$field],$direction];
}
function table_link(array $f,array $extra=[]): string {
    $params=[];foreach(['q','status','district','gender','source_type','project','category','date_from','date_to','per','p','sort','dir'] as $key) {
        $s=$extra[$key]??($_GET[$key]??'');if(is_scalar($s)&&$s!=='')$params[$key]=(string)$s;
    }
    return url('volunteers',$params);
}
function table_action(PDO $pdo,string $action,array $ids): array {
    if(!$ids || count($ids)>100)throw new RuntimeException('১–১০০টি রেকর্ড নির্বাচন করুন।');
    $valid=['archive','restore','active','pending'];
    if(!in_array($action,$valid,true))throw new RuntimeException('এই অ্যাকশন অনুমোদিত নয়।');
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn($id)=>$id>0)));
    if(!$ids)throw new RuntimeException('কোনো বৈধ ID পাওয়া যায়নি।');
    $pdo->beginTransaction();$affected=0;
    try {
        $q=$pdo->prepare('SELECT id,payload,status,deleted_at FROM volunteers WHERE id=?');
        $update=$pdo->prepare('UPDATE volunteers SET status=?,payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND deleted_at IS NULL');
        $archive=$pdo->prepare('UPDATE volunteers SET deleted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND deleted_at IS NULL');
        $restore=$pdo->prepare('UPDATE volunteers SET deleted_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND deleted_at IS NOT NULL');
        foreach($ids as $id) {
            $q->execute([$id]);$r=$q->fetch();if(!$r)continue;
            if($action==='archive' && $r['deleted_at']===null){$archive->execute([$id]);$affected+=$archive->rowCount();}
            elseif($action==='restore' && $r['deleted_at']!==null){$restore->execute([$id]);$affected+=$restore->rowCount();}
            elseif(in_array($action,['active','pending'],true) && $r['deleted_at']===null) {
                $status=$action==='active'?'সক্রিয়':'অপেক্ষমাণ';
                $data=json_decode((string)$r['payload'],true)?:[];$data['status']=$status;
                $update->execute([$status,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);$affected+=$update->rowCount();
            }
        }
        // One audit entry for each batch, avoiding storage of personal information in log details.
        log_action(null,'bulk_'.$action,'count='.$affected.', ids='.implode(',',array_slice($ids,0,100)));
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['count'=>$affected,'action'=>$action];
}
function table_selected_export(array $ids,string $format): never {
    if(!$ids||count($ids)>100)throw new RuntimeException('সর্বোচ্চ ১০০টি রেকর্ড এক্সপোর্ট করা যায়।');
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id>0)));
    $params=implode(',',array_fill(0,count($ids),'?'));
    $q=db()->prepare('SELECT * FROM volunteers WHERE id IN ('.$params.') AND deleted_at IS NULL ORDER BY id ASC');
    $q->execute($ids);
    $rows=(function()use($q){
        $headers=export_headers();
        while($v=$q->fetch()) {
            $d=json_decode((string)$v['payload'],true)?:[];$row=[];
            foreach($headers as $key=>$head) {
                if(array_key_exists($key,$v))$val=$v[$key];
                elseif(array_key_exists($key,$d))$val=$d[$key];
                elseif(preg_match('/^(work_history|training_history)_([1-4])_(.+)$/',$key,$m))$val=$d[$m[1]][(int)$m[2]-1][$m[3]]??'';
                else $val='';
                $row[]=display_value($val);
            }
            yield $row;
        }
    })();
    log_action(null,'selected_export',$format.' '.count($ids).' selected');
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex');
    if($format==='xlsx') {
        $binary=xlsx_binary(array_values(export_headers()),$rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="praan_selected_'.date('Y-m-d').'.xlsx"');
        header('Content-Length: '.strlen($binary));echo $binary;exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="praan_selected_'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_values(export_headers()));
    foreach($rows as $row)fputcsv($out,array_map(static fn($v)=>preg_match('/^\s*[=+\-@]/u',(string)$v)?"'".$v:$v,$row));
    fclose($out);exit;
}
function render_volunteer_table(PDO $pdo): void {
    require_admin();
    $f=analytics_filters($_GET);
    $total=(int)analytics_query($pdo,$f,'COUNT(*)')->fetchColumn();
    $per=(int)($_GET['per']??praan_option('table_page_size'));if(!in_array($per,[10,25,50,100],true))$per=25;
    $pages=max(1,(int)ceil($total/$per));$p=max(1,min($pages,(int)($_GET['p']??1)));
    [$sort,$sqlsort,$direction]=table_sort($_GET);
    $rows=analytics_query($pdo,$f,'v.id,v.volunteer_code,v.form_no,v.name_bn,v.name_en,v.phone,v.district,v.gender,v.project,v.status,v.created_at,v.registered_on,v.source_type,v.public_visible', 'ORDER BY '.$sqlsort.' '.$direction.', v.id DESC LIMIT '.$per.' OFFSET '.(($p-1)*$per))->fetchAll();
    $opts=analytics_filter_options($pdo);
    page_start('স্বেচ্ছাসেবক তালিকা','volunteers',true);
    $activeCount=(int)$pdo->query("SELECT count(*) FROM volunteers WHERE deleted_at IS NULL AND status='সক্রিয়'")->fetchColumn();
    $pendingCount=(int)$pdo->query("SELECT count(*) FROM volunteers WHERE deleted_at IS NULL AND status='অপেক্ষমাণ'")->fetchColumn();
    $allCount=(int)$pdo->query('SELECT count(*) FROM volunteers WHERE deleted_at IS NULL')->fetchColumn();
    ?>
    <div class="pro-heading"><div><span class="eyebrow">VOLUNTEER DATABASE / PEOPLE</span><h2>স্বেচ্ছাসেবকদের তথ্য <span class="pro-count"><?=number_format($total)?></span></h2><p>স্মার্ট সার্চ, নিরাপদ বাল্ক অ্যাকশন ও একই ফরম্যাটে Import / Export</p></div>
       <div class="pro-heading-actions"><?php if(praan_can('import.use')&&praan_module_access('import')):?><a class="btn btn-outline-brand" href="<?=e(url('import'))?>"><i class="bi bi-upload"></i> Import</a><?php endif;?><?php if(praan_can('volunteers.export')&&praan_module_access('reports')&&praan_flag_enabled('reports','detail_export')):?><a class="btn btn-outline-success" href="<?=e(analytics_link('report_download',$f,['format'=>'xlsx','mode'=>'detail']))?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><?php endif;?><?php if(praan_can('volunteers.create')):?><a class="btn btn-brand" href="<?=e(url('new'))?>"><i class="bi bi-plus-lg"></i> নতুন স্বেচ্ছাসেবক</a><?php endif;?></div></div>
    <div class="pro-mini-stats"><div><i class="bi bi-people"></i><span><b><?=number_format($allCount)?></b><small>মোট নিবন্ধিত</small></span></div><div><i class="bi bi-patch-check"></i><span><b><?=number_format($activeCount)?></b><small>সক্রিয়</small></span></div><div><i class="bi bi-hourglass-split"></i><span><b><?=number_format($pendingCount)?></b><small>অপেক্ষমাণ</small></span></div><div><i class="bi bi-funnel"></i><span><b><?=number_format($total)?></b><small>ফিল্টারে পাওয়া</small></span></div></div>
    <div class="card pro-table-card" id="pranVolunteerTable" data-table-url="<?=e(url('volunteers'))?>"><div class="pro-toolbar">
      <form method="get" class="pro-search-form" action="index.php" id="proFilters"><input type="hidden" name="page" value="volunteers"><input type="hidden" name="per" value="<?=$per?>"><input type="hidden" name="sort" value="<?=e($sort)?>"><input type="hidden" name="dir" value="<?=e(strtolower($direction))?>">
        <div class="pro-search"><i class="bi bi-search"></i><input type="search" name="q" value="<?=e($f['q'])?>" placeholder="নাম, মোবাইল, Volunteer ID বা ফর্ম নং" aria-label="তালিকায় খুঁজুন"><kbd>/</kbd></div>
        <select class="form-select" name="status" aria-label="স্ট্যাটাস"><option value="">সব স্ট্যাটাস</option><?php foreach(($opts['status']??[]) as $v):?><option <?=($f['status']===$v?'selected':'')?> value="<?=e($v)?>"><?=e($v)?></option><?php endforeach;?></select>
        <select class="form-select" name="district" aria-label="জেলা"><option value="">সব জেলা</option><?php foreach(($opts['district']??[]) as $v):?><option <?=($f['district']===$v?'selected':'')?> value="<?=e($v)?>"><?=e($v)?></option><?php endforeach;?></select>
        <button type="submit" class="btn btn-outline-brand"><i class="bi bi-funnel"></i><span class="visually-hidden">ফিল্টার</span></button><a href="<?=e(url('volunteers'))?>" class="btn btn-light pro-reset" title="ফিল্টার মুছুন"><i class="bi bi-arrow-counterclockwise"></i></a>
      <details class="pro-more-filters" <?=(!empty($f['date_from'])||!empty($f['date_to'])||!empty($f['gender'])||!empty($f['project'])||!empty($f['source_type']))?'open':''?>><summary><i class="bi bi-sliders"></i> আরও ফিল্টার <i class="bi bi-chevron-down"></i></summary><div class="pro-advanced-grid"><label>শুরু তারিখ<input type="date" class="form-control form-control-sm" name="date_from" value="<?=e($f['date_from'])?>"></label><label>শেষ তারিখ<input type="date" class="form-control form-control-sm" name="date_to" value="<?=e($f['date_to'])?>"></label><?php foreach(['gender'=>'লিঙ্গ','project'=>'প্রকল্প','source_type'=>'উৎস','category'=>'ক্যাটাগরি'] as $filter=>$label):?><label><?=e($label)?><select class="form-select form-select-sm" name="<?=e($filter)?>"><option value="">সব</option><?php foreach(($opts[$filter]??[]) as $item):?><option value="<?=e($item)?>" <?=($f[$filter]===$item?'selected':'')?>><?=e($item)?></option><?php endforeach;?></select></label><?php endforeach;?><button type="submit" class="btn btn-brand btn-sm align-self-end">ফিল্টার প্রয়োগ</button></div></details>
      </form>
      <div class="pro-toolbar-secondary"><label>প্রতি পৃষ্ঠায় <select id="proPerPage" class="form-select form-select-sm" aria-label="প্রতি পৃষ্ঠায় কতটি" data-base="<?=e(table_link($f,['p'=>1]))?>"><?php foreach([10,25,50,100] as $val):?><option value="<?=$val?>" <?=$val===$per?'selected':''?>><?=$val?></option><?php endforeach;?></select></label><a href="<?=e(url('archived'))?>" class="btn btn-light btn-sm"><i class="bi bi-archive"></i> আর্কাইভ</a></div>
    </div>
    <div class="pro-selection-bar" id="proSelectionBar" hidden><strong><span id="proSelectedCount">0</span>টি নির্বাচিত</strong><span class="pro-selection-hint">শুধু এই পৃষ্ঠার নির্বাচিত রেকর্ড</span><div class="pro-bulk-actions"><?php if(praan_can('volunteers.approve')):?><button type="button" data-bulk="active" class="btn btn-sm btn-outline-success"><i class="bi bi-check2-circle"></i> সক্রিয়</button><button type="button" data-bulk="pending" class="btn btn-sm btn-outline-secondary">অপেক্ষমাণ</button><?php endif;?><?php if(praan_can('volunteers.export')):?><button type="button" data-bulk="export_xlsx" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-down"></i> Excel</button><?php endif;?><?php if(praan_can('volunteers.archive')):?><button type="button" data-bulk="archive" class="btn btn-sm btn-outline-danger"><i class="bi bi-archive"></i> Soft Delete</button><?php endif;?><button type="button" class="btn btn-sm btn-light" id="proClearSelection">Clear</button></div></div>
    <div class="table-responsive pro-table-scroll"><table class="table pro-table align-middle" aria-label="স্বেচ্ছাসেবক তালিকা"><thead><tr><th class="check-col"><input id="proSelectAll" type="checkbox" class="form-check-input" aria-label="এই পৃষ্ঠার সবাই নির্বাচন" title="এই পৃষ্ঠার সবাই"></th>
      <?php foreach(['name'=>'নাম','code'=>'Volunteer ID','phone'=>'মোবাইল','district'=>'জেলা','date'=>'নিবন্ধনের তারিখ','status'=>'স্ট্যাটাস'] as $col=>$label):?><th><a class="pro-sort" href="<?=e(table_link($f,['sort'=>$col,'dir'=>($sort===$col&&$direction==='ASC'?'desc':'asc'),'p'=>1]))?>"><?=e($label)?> <?php if($sort===$col):?><i class="bi bi-sort-<?=strtolower($direction)==='asc'?'up':'down'?>"></i><?php endif;?></a></th><?php endforeach;?><th class="actions-col">অ্যাকশন</th></tr></thead>
      <tbody><?php foreach($rows as $r):$name=(string)($r['name_bn']?:$r['name_en']);$initial=function_exists('mb_substr')?mb_substr($name,0,1):'P';?><tr data-id="<?=e($r['id'])?>"><td class="check-col"><input class="form-check-input pro-row-check" type="checkbox" value="<?=e($r['id'])?>" aria-label="<?=e($name)?> নির্বাচন"></td>
      <td><a href="<?=e(url('view',['id'=>$r['id']]))?>" class="pro-person"><span class="pro-avatar"><?=e($initial)?></span><span><b><?=e($name)?></b><small><?=e($r['name_en']!==$name?$r['name_en']:$r['project'])?></small></span></a></td>
      <td><span class="pro-code"><?=e($r['volunteer_code'])?></span><small class="d-block text-muted"><?=e($r['form_no'])?></small></td><td><span class="pro-quiet"><?=e(praan_can('volunteers.sensitive')?($r['phone']?:'—'):praan_mask_phone((string)$r['phone']))?></span></td><td><?=e($r['district']?:'—')?></td><td><span class="pro-quiet"><?=e($r['registered_on']?:substr($r['created_at'],0,10))?></span></td><td><span class="pro-status <?=($r['status']==='সক্রিয়'?'is-active':($r['status']==='অপেক্ষমাণ'?'is-pending':'is-muted'))?>"><span></span><?=e($r['status'])?></span></td><td class="actions-col"><div class="pro-menu-wrap"><button type="button" class="pro-three-dot" data-menu-button aria-haspopup="true" aria-expanded="false" aria-label="<?=e($name)?>: আরও কাজ"><i class="bi bi-three-dots-vertical"></i></button><div class="pro-menu" role="menu" hidden><a role="menuitem" href="<?=e(url('view',['id'=>$r['id']]))?>"><i class="bi bi-eye"></i> বিস্তারিত</a><?php if(praan_can('volunteers.edit')):?><a role="menuitem" href="<?=e(url('edit',['id'=>$r['id']]))?>"><i class="bi bi-pencil-square"></i> সম্পাদনা</a><?php endif;?><?php if(praan_can('volunteers.sensitive')):?><a role="menuitem" target="_blank" rel="noopener" href="<?=e(url('print',['id'=>$r['id']]))?>"><i class="bi bi-printer"></i> A4 প্রিন্ট/PDF</a><?php endif;?><?php if(praan_enabled('docx_export_enabled')&&praan_can('volunteers.export')):?><a role="menuitem" href="<?=e(url('download_docx',['id'=>$r['id']]))?>"><i class="bi bi-file-earmark-word"></i> DOCX ডাউনলোড</a><?php endif;?><?php if(praan_can('volunteers.approve')):?><button type="button" role="menuitem" data-one="<?=e($r['id'])?>" data-action="<?=$r['status']==='সক্রিয়'?'pending':'active'?>"><i class="bi bi-patch-check"></i> <?=$r['status']==='সক্রিয়'?'অপেক্ষমাণ করুন':'সক্রিয় করুন'?></button><?php endif;?><div class="pro-menu-divider"></div><?php if(praan_can('volunteers.archive')):?><button type="button" role="menuitem" class="danger" data-one="<?=e($r['id'])?>" data-action="archive"><i class="bi bi-archive"></i> আর্কাইভ (Soft Delete)</button><?php endif;?></div></div></td></tr><?php endforeach; if(!$rows):?><tr><td colspan="8"><div class="pro-empty"><i class="bi bi-search"></i><h3>কোনো স্বেচ্ছাসেবক পাওয়া যায়নি</h3><p>ফিল্টার পরিবর্তন করে আবার চেষ্টা করুন।</p><a href="<?=e(url('volunteers'))?>" class="btn btn-outline-brand btn-sm">সব রেকর্ড দেখুন</a></div></td></tr><?php endif;?></tbody></table></div>
    <div class="pro-footer"><div>মোট <?=number_format($total)?>টির মধ্যে <?=($total?number_format(($p-1)*$per+1):'0')?>–<?=number_format(min($total,$p*$per))?> দেখানো হচ্ছে <small>· পৃষ্ঠা <?=$p?> / <?=$pages?></small></div><nav class="pro-pagination" aria-label="স্বেচ্ছাসেবক তালিকা পৃষ্ঠা"><a class="btn btn-sm btn-light <?=$p===1?'disabled':''?>" href="<?=e(table_link($f,['p'=>max(1,$p-1)]))?>"><i class="bi bi-chevron-left"></i></a><?php for($i=max(1,$p-2);$i<=min($pages,$p+2);$i++):?><a class="btn btn-sm <?=$i===$p?'btn-brand':'btn-light'?>" href="<?=e(table_link($f,['p'=>$i]))?>" <?=$i===$p?'aria-current="page"':''?>><?=$i?></a><?php endfor;?><a class="btn btn-sm btn-light <?=$p===$pages?'disabled':''?>" href="<?=e(table_link($f,['p'=>min($pages,$p+1)]))?>"><i class="bi bi-chevron-right"></i></a></nav></div>
    </div>
    <dialog id="proActionDialog" class="pro-confirm-modal" aria-labelledby="proDialogTitle"><div class="pro-dialog-top"><span class="pro-dialog-icon"><i class="bi bi-exclamation-triangle"></i></span><button type="button" class="pro-dialog-close" aria-label="বন্ধ করুন" data-cancel>&times;</button></div><h3 id="proDialogTitle">পরিবর্তন নিশ্চিত করুন</h3><p id="proDialogText"></p><div id="proDangerNotice" class="pro-danger-notice" hidden>এটি স্থায়ী ডিলিট নয়। রেকর্ড আর্কাইভে যাবে এবং পরে Restore করা যাবে।</div><div class="pro-dialog-actions"><button type="button" class="btn btn-light" data-cancel>বাতিল</button><button type="button" class="btn btn-danger" id="proDialogConfirm">নিশ্চিত করুন</button></div></dialog>
    <form id="proBulkForm" method="post" action="<?=e(url('bulk_action'))?>" hidden><?=csrf_field()?><input type="hidden" name="action_type" value=""><input type="hidden" name="confirmed" value="1"><input type="hidden" name="return_query" value="<?=e((string)($_SERVER['QUERY_STRING']??''))?>"><div id="proBulkIds"></div></form>
    <?php page_end(true);
}
