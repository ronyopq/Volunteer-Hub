<?php
declare(strict_types=1);
require_once __DIR__ . '/analytics.php';

function report_filters_form(array $f, array $options, string $page): void { ?>
    <form method="get" action="index.php" class="card dashboard-card analytics-filters" aria-label="রিপোর্ট ফিল্টার">
        <input type="hidden" name="page" value="<?=e($page)?>">
        <div class="card-body">
            <div class="analytics-filter-head"><div><strong><i class="bi bi-sliders2"></i> রিপোর্ট ফিল্টার</strong><span>ফিল্টার পরিবর্তন করে <b>ফিল্টার প্রয়োগ</b> চাপুন</span></div><a class="filter-reset" href="<?=e(url($page))?>"><i class="bi bi-arrow-counterclockwise"></i> সব রিসেট</a></div>
            <div class="analytics-filter-grid">
                <?php
                $selects=[
                    'status'=>['স্ট্যাটাস','সব স্ট্যাটাস'],
                    'district'=>['জেলা','সব জেলা'],
                    'gender'=>['লিঙ্গ','সব লিঙ্গ'],
                    'source_type'=>['ডাটা এন্ট্রির উৎস','সব উৎস'],
                    'project'=>['প্রকল্প/কর্মসূচি','সব প্রকল্প'],
                    'category'=>['ক্যাটাগরি','সব ক্যাটাগরি'],
                ];
                foreach ($selects as $field=>[$label,$placeholder]): ?>
                    <label class="analytics-field"><span><?=e($label)?></span><select class="form-select form-select-sm" name="<?=e($field)?>"><option value=""><?=e($placeholder)?></option>
                        <?php foreach($options[$field]??[] as $choice):?><option value="<?=e($choice)?>" <?=($f[$field]??'')===$choice?'selected':''?>><?=e($choice)?></option><?php endforeach;?>
                    </select></label>
                <?php endforeach; ?>
                <label class="analytics-field"><span>নিবন্ধন শুরু</span><input class="form-control form-control-sm" type="date" name="date_from" value="<?=e($f['date_from'])?>"></label>
                <label class="analytics-field"><span>নিবন্ধন শেষ</span><input class="form-control form-control-sm" type="date" name="date_to" value="<?=e($f['date_to'])?>"></label>
                <label class="analytics-field analytics-wide"><span>নাম, ফোন, ফর্ম নং বা আইডি</span><input class="form-control form-control-sm" type="search" name="q" value="<?=e($f['q'])?>" maxlength="100" placeholder="সার্চ করুন..."></label>
                <div class="analytics-filter-actions"><button type="submit" class="btn btn-brand"><i class="bi bi-funnel"></i> ফিল্টার প্রয়োগ</button></div>
            </div>
            <div class="analytics-filter-note"><i class="bi bi-info-circle"></i> তারিখের ফিল্টারটি মূল ফর্মের <strong>নিবন্ধনের তারিখ</strong>-এর ওপর প্রযোজ্য; ডাটা এন্ট্রির সময়ের ওপর নয়।</div>
        </div>
    </form>
<?php }
function analytics_metric(string $label, int $num, string $icon, string $theme, string $subtitle, string $link = ''): void { ?>
    <?php if($link):?><a class="metric-card metric-link" href="<?=e($link)?>"><?php else:?><div class="metric-card"><?php endif;?>
        <div class="metric-top"><span><?=e($label)?></span><span class="metric-icon <?=e($theme)?>"><i class="bi <?=e($icon)?>"></i></span></div>
        <strong><?=number_format($num)?></strong><small><?=e($subtitle)?><?= $link?' · বিস্তারিত দেখুন':'' ?></small>
    <?php if($link):?></a><?php else:?></div><?php endif;?>
<?php }
function analytics_group_card(string $title, string $category, array $summary, array $filters, int $limit = 8, ?string $drillField = null): void {
    $counts=$summary['groups'][$category]??[]; $total=(int)$summary['metrics']['total'];
    $shown=array_slice($counts,0,$limit,true);
    $max=$shown?max(1,max($shown)):1;
    ?>
    <section class="card dashboard-card analytics-breakdown">
        <div class="card-header"><h3 class="card-title"><?=e($title)?></h3><span class="card-label">TOP <?=count($shown)?> · মোট <?=count($counts)?> ধরনের</span></div>
        <div class="card-body"><div class="analytics-bars">
            <?php if(!$shown):?><p class="empty-mini">নির্বাচিত ফিল্টারে তথ্য পাওয়া যায়নি।</p><?php endif; ?>
            <?php foreach($shown as $name=>$count):
                $pct=($count/$max)*100;
                $urlLink=$drillField && $name!=='তথ্য নেই' ? analytics_link('volunteers',$filters,[$drillField=>(string)$name,'p'=>null]) : '';
            ?>
            <div class="analytics-bar-item">
                <div class="analytics-bar-label">
                    <?php if($urlLink):?><a href="<?=e($urlLink)?>" title="<?=e((string)$name)?>: রেকর্ড দেখুন"><?=e((string)$name)?> <i class="bi bi-box-arrow-up-right"></i></a><?php else:?><span><?=e((string)$name)?></span><?php endif;?>
                    <strong><?=number_format((int)$count)?> <small><?=analytics_percentage((int)$count,$total)?>%</small></strong>
                </div>
                <div class="analytics-track" role="img" aria-label="<?=e((string)$name)?> <?=number_format((int)$count)?> জন"><span style="width:<?=number_format($pct,2,'.','')?>%"></span></div>
            </div>
            <?php endforeach; ?>
            <?php if(count($counts)>$limit):?><p class="analytics-overflow">আরও <?=count($counts)-$limit?>টি বিভাগ রয়েছে। Excel রিপোর্টে সবগুলো পাবেন।</p><?php endif; ?>
        </div></div>
    </section>
<?php }
function analytics_trend_chart(array $trend, array $filters): void {
    $max=max(1,...array_column($trend,'count')); $left=54;$right=820;$top=23;$bottom=218;
    $points=[];
    foreach($trend as $i=>$row) {
        $x=$left+($right-$left)*$i/11;
        $y=$bottom-($bottom-$top)*($row['count']/$max);
        $points[]=[$x,$y,$row['count'],$row['month']];
    }
    $coordinates=implode(' ',array_map(fn($p)=>number_format($p[0],1,'.','').','.number_format($p[1],1,'.',''),$points));
    $area=$coordinates.' '.$right.','.$bottom.' '.$left.','.$bottom;
    ?>
    <div class="analytics-line-chart">
    <svg viewBox="0 0 865 273" role="img" aria-label="১২ মাসের নিবন্ধন প্রবণতা; সর্বোচ্চ <?=e($max)?> জন" xmlns="http://www.w3.org/2000/svg">
        <defs><linearGradient id="pranChartArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#17a398" stop-opacity=".28"/><stop offset="100%" stop-color="#17a398" stop-opacity=".018"/></linearGradient></defs>
        <?php foreach([0,1,2,3,4] as $step): $y=$top+($bottom-$top)*$step/4; $label=round($max*(4-$step)/4); ?>
            <line x1="<?=$left?>" y1="<?=$y?>" x2="<?=$right?>" y2="<?=$y?>" stroke="currentColor" stroke-opacity=".11" stroke-dasharray="4,5" />
            <text x="40" y="<?=$y+4?>" text-anchor="end" font-size="12" fill="currentColor" opacity=".65"><?=$label?></text>
        <?php endforeach; ?>
        <polygon points="<?=e($area)?>" fill="url(#pranChartArea)"/>
        <polyline points="<?=e($coordinates)?>" stroke="var(--accent,#168b8b)" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round" />
        <?php foreach($points as $i=>$p):
            [$x,$y,$n,$month]=$p;
            $start=$month.'-01';$last=(new DateTimeImmutable($start))->format('Y-m-t');
            $href=analytics_link('volunteers',$filters,['date_from'=>$start,'date_to'=>$last,'p'=>null]);
        ?>
            <a href="<?=e($href)?>" aria-label="<?=e($month)?> মাসের <?=e($n)?>টি নিবন্ধন দেখুন"><title><?=e($month)?> · <?=$n?>টি নিবন্ধন — তালিকা দেখুন</title><circle cx="<?=$x?>" cy="<?=$y?>" r="8" fill="transparent"/><circle cx="<?=$x?>" cy="<?=$y?>" r="4.3" fill="var(--accent,#168b8b)" stroke="var(--surface,#fff)" stroke-width="2" /></a>
            <?php if($i%2===0 || $i===count($points)-1):?><text x="<?=$x?>" y="248" font-size="11" text-anchor="middle" fill="currentColor" opacity=".72"><?=e(substr($month,2))?></text><?php endif;?>
        <?php endforeach;?>
    </svg>
    </div>
    <p class="analytics-chart-footnote">বৃত্তে ক্লিক করলে ওই মাসের নিবন্ধনের তালিকা দেখাবে। মূল ফর্মের নিবন্ধন তারিখ অনুযায়ী গণনা।</p>
<?php }
function analytics_status_donut(array $summary, array $filters): void {
    $m=$summary['metrics'];$total=max(1,$m['total']);$active=$m['active'];$pending=$m['pending'];$other=max(0,$m['total']-$active-$pending);
    $s=$m['total']?round(100*$active/$total,2):0; $p=$m['total']?round(100*($active+$pending)/$total,2):0;
    ?>
    <div class="analytics-status-wrap"><div class="donut" style="--success:<?=$s?>%;--pending:<?=$p?>%"><div><strong><?=number_format($m['total'])?></strong><small>মোট রেকর্ড</small></div></div>
        <div class="legend">
            <a href="<?=e(analytics_link('volunteers',$filters,['status'=>'সক্রিয়']))?>"><i style="background:#13a68c"></i><span>সক্রিয়</span><strong><?=number_format($active)?></strong></a>
            <a href="<?=e(analytics_link('volunteers',$filters,['status'=>'অপেক্ষমাণ']))?>"><i style="background:#f2aa49"></i><span>অপেক্ষমাণ</span><strong><?=number_format($pending)?></strong></a>
            <div><i style="background:#cbd5e1"></i><span>অন্যান্য</span><strong><?=number_format($other)?></strong></div>
        </div>
    </div>
<?php }
function render_dashboard_v110(PDO $pdo): void {
    require_admin(); $filters=analytics_filters($_GET); $options=analytics_filter_options($pdo); $summary=analytics_summary($pdo,$filters); $m=$summary['metrics'];
    page_start('Advanced Dashboard','dashboard',true); $umNotice=um_notification(); ?>
    <?php if($umNotice):?><div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-cloud-download"></i> <strong>PRAAN v<?=e($umNotice['version'])?></strong> নতুন সংস্করণ পাওয়া গেছে।</span><a class="btn btn-sm btn-outline-brand" href="<?=e(url('updates',['check'=>1]))?>">Update Center</a></div><?php endif;?>
    <div class="analytics-hero"><div class="analytics-hero-text"><span class="eyebrow">PRAAN VOLUNTEER INTELLIGENCE · VERSION <?=e(APP_VERSION)?></span><h2>এক নজরে স্বেচ্ছাসেবক নেটওয়ার্ক</h2><p>নিবন্ধন, যাচাই, এলাকা, দক্ষতা ও প্রশিক্ষণ—সব পরিসংখ্যান একটি ড্যাশবোর্ডে।</p><div class="analytics-hero-actions"><?php if(praan_can('volunteers.create')&&praan_flag_enabled('volunteer_core','quick_entry')):?><a class="btn btn-light btn-sm" href="<?=e(url('quick'))?>"><i class="bi bi-lightning-charge-fill"></i> দ্রুত এন্ট্রি</a><?php endif;?><?php if(praan_module_access('reports')):?><a class="btn btn-outline-light btn-sm" href="<?=e(analytics_link('reports',$filters))?>"><i class="bi bi-file-earmark-bar-graph"></i> বিস্তারিত রিপোর্ট</a><?php endif;?><?php if(praan_module_access('reports')):?><a class="btn btn-outline-light btn-sm" href="<?=e(analytics_link('report_download',$filters+['format'=>'xlsx','mode'=>'summary']))?>"><i class="bi bi-download"></i> Excel সারসংক্ষেপ</a><?php endif;?></div></div><div class="analytics-hero-art"><i class="bi bi-pie-chart-fill"></i></div></div>
    <?php if(praan_can('users.view')):
      $totalUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
      $activeSessions=(int)$pdo->query('SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at>'.time())->fetchColumn();
      $disabledUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='disabled' AND deleted_at IS NULL")->fetchColumn();
    ?>
    <div class="praan-identity-overview"><div><i class="bi bi-people"></i><span><b><?=number_format($totalUsers)?></b><small>অফিস ইউজার</small></span></div><div><i class="bi bi-laptop"></i><span><b><?=number_format($activeSessions)?></b><small>অ্যাক্টিভ সেশন</small></span></div><div><i class="bi bi-person-x"></i><span><b><?=number_format($disabledUsers)?></b><small>নিষ্ক্রিয় ইউজার</small></span></div><a href="<?=e(url('users'))?>" class="btn btn-sm btn-outline-brand"><i class="bi bi-arrow-right"></i> User Manager</a></div>
    <?php endif;?>
    <?php report_filters_form($filters,$options,'dashboard'); ?>
    <?php if(!empty($filters['date_invalid'])):?><div class="alert alert-warning">শুরুর তারিখ শেষের তারিখের পরে দেওয়া হয়েছে; তাই কোনো রেকর্ড দেখানো হচ্ছে না।</div><?php endif; ?>
    <div class="analytics-section-line"><h2>মূল পরিসংখ্যান</h2><span>নির্বাচিত ফিল্টার অনুযায়ী · <?=number_format($m['total'])?>টি রেকর্ড</span></div>
    <div class="stat-grid analytics-stats">
    <?php analytics_metric('মোট স্বেচ্ছাসেবক',$m['total'],'bi-people','teal','আর্কাইভ বাদে',analytics_link('volunteers',$filters));
    analytics_metric('সক্রিয় ও অনুমোদিত',$m['active'],'bi-person-check','green','মোটের '.analytics_percentage($m['active'],$m['total']).'%',analytics_link('volunteers',$filters,['status'=>'সক্রিয়']));
    analytics_metric('অনুমোদনের অপেক্ষায়',$m['pending'],'bi-clock-history','orange','যাচাই করা প্রয়োজন',analytics_link('volunteers',$filters,['status'=>'অপেক্ষমাণ']));
    analytics_metric('কাগজের ফর্ম এন্ট্রি',$m['paper'],'bi-journal-check','purple','আগের হাতে লেখা ফর্ম',analytics_link('volunteers',$filters,['source_type'=>'paper']));
    analytics_metric('প্রশিক্ষণ তথ্য আছে',$m['trained'],'bi-mortarboard','blue','মোট '.number_format($m['total_trainings']).'টি প্রশিক্ষণ এন্ট্রি');
    analytics_metric('জেলা কভারেজ',$m['districts'],'bi-geo-alt','pink','তথ্যযুক্ত অনন্য জেলা');
    analytics_metric('এই মাসে ডাটা এন্ট্রি',$m['new_month'],'bi-calendar-check','teal',date('F Y').' · এন্ট্রির সময়');
    analytics_metric('পাবলিক তালিকায়',$m['public'],'bi-person-vcard','green','সক্রিয় ও প্রকাশযোগ্য রেকর্ড'); ?>
    </div>
    <div class="analytics-main-grid">
        <section class="card dashboard-card analytics-trend"><div class="card-header"><h3 class="card-title">মাসভিত্তিক নিবন্ধনের প্রবণতা</h3><span class="card-label">LAST <?=e(praan_module_option('reports','trend_months'))?> MONTHS</span></div><div class="card-body"><?php analytics_trend_chart($summary['trend'],$filters);?></div></section>
        <section class="card dashboard-card"><div class="card-header"><h3 class="card-title">স্ট্যাটাস বিশ্লেষণ</h3><span class="card-label">LIVE STATUS</span></div><div class="card-body"><?php analytics_status_donut($summary,$filters);?></div></section>
    </div>
    <div class="analytics-charts-grid">
        <?php analytics_group_card('জেলাভিত্তিক চিত্র','district',$summary,$filters,8,'district');
              analytics_group_card('ডাটা এন্ট্রির উৎস','source_type',$summary,$filters,8,'source_type');
              analytics_group_card('স্বেচ্ছাসেবকের আগ্রহ','interests',$summary,$filters,8);
              analytics_group_card('লিঙ্গভিত্তিক বিশ্লেষণ','gender',$summary,$filters,6,'gender');?>
    </div>
    <div class="analytics-main-grid analytics-bottom">
        <section class="card dashboard-card"><div class="card-header"><h3 class="card-title">সাম্প্রতিক নিবন্ধন</h3><a href="<?=e(analytics_link('volunteers',$filters))?>" class="text-link">সব দেখুন <i class="bi bi-arrow-up-right"></i></a></div><div class="card-body p-0"><div class="table-responsive"><table class="table modern-table align-middle"><thead><tr><th>স্বেচ্ছাসেবক</th><th>আইডি</th><th>জেলা</th><th>স্ট্যাটাস</th><th></th></tr></thead><tbody>
        <?php foreach($summary['recent'] as $v):?><tr><td><div class="person-line"><span class="avatar-initial"><?=e(mb_safe_limit((string)($v['name_bn']?:$v['name_en']),2))?></span><div><strong><?=e($v['name_bn']?:$v['name_en'])?></strong><small><?=e($v['source_type'])?></small></div></div></td><td><code><?=e($v['volunteer_code'])?></code></td><td><?=e($v['district']?:'—')?></td><td><span class="status-pill <?=$v['status']==='সক্রিয়'?'success':($v['status']==='অপেক্ষমাণ'?'pending':'muted')?>"><?=e($v['status'])?></span></td><td><a href="<?=e(url('view',['id'=>$v['id']]))?>" aria-label="প্রোফাইল দেখুন" class="table-action"><i class="bi bi-arrow-up-right"></i></a></td></tr><?php endforeach; if(!$summary['recent']):?><tr><td class="text-center py-4" colspan="5">এই ফিল্টারে কোনো নিবন্ধন নেই।</td></tr><?php endif;?>
        </tbody></table></div></div></section>
        <section class="card dashboard-card"><div class="card-header"><h3 class="card-title">তথ্যের পূর্ণতার সূচক</h3><a class="text-link" href="<?=e(analytics_link('reports',$filters))?>">রিপোর্ট দেখুন</a></div><div class="card-body"><div class="analytics-quality-list">
            <?php foreach([['মোবাইল নম্বর নেই','no_phone','bi-telephone-x'],['জেলা লেখা নেই','no_district','bi-pin-map'],['দক্ষতা বা আগ্রহ নেই','no_interests','bi-stars'],['সম্মতি এন্ট্রি নেই','no_consents','bi-shield-exclamation']] as [$label,$key,$icon]):?>
            <div class="analytics-quality"><span><i class="bi <?=e($icon)?>"></i> <?=e($label)?></span><strong><?=number_format($m[$key])?></strong></div>
            <?php endforeach; ?></div><p class="analytics-help">এগুলো তথ্য এন্ট্রির ঘাটতি বোঝায়; ব্যক্তির সক্ষমতা বা অনুমোদনের সিদ্ধান্ত নয়।</p></div></section>
    </div>
    <?php page_end(true);
}
function render_reports_v110(PDO $pdo): void {
    require_admin(); $filters=analytics_filters($_GET); $options=analytics_filter_options($pdo); $summary=analytics_summary($pdo,$filters);$m=$summary['metrics'];
    page_start('Advanced Reports','reports',true); ?>
    <div class="analytics-section-line"><div><span class="eyebrow">REPORTING & INSIGHTS</span><h2>স্বেচ্ছাসেবক রিপোর্ট কেন্দ্র</h2><p>ফিল্টারভিত্তিক পরিসংখ্যান, চার্ট, Excel/CSV এবং প্রিন্ট</p></div><div class="analytics-report-actions">
        <a href="<?=e(analytics_link('report_download',$filters,['format'=>'xlsx','mode'=>'summary']))?>" class="btn btn-brand btn-sm"><i class="bi bi-file-earmark-excel"></i> সারসংক্ষেপ Excel</a>
        <a href="<?=e(analytics_link('report_download',$filters,['format'=>'csv','mode'=>'summary']))?>" class="btn btn-outline-brand btn-sm"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a href="<?=e(analytics_link('report_print',$filters))?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> প্রিন্ট</a>
    </div></div>
    <?php report_filters_form($filters,$options,'reports'); ?>
    <div class="analytics-report-summary">
        <?php foreach([['মোট রেকর্ড',$m['total']],['সক্রিয়',$m['active']],['অপেক্ষমাণ',$m['pending']],['প্রশিক্ষণপ্রাপ্ত',$m['trained']]] as [$label,$number]):?><div><span><?=e($label)?></span><strong><?=number_format($number)?></strong></div><?php endforeach; ?>
    </div>
    <section class="card dashboard-card analytics-full-trend"><div class="card-header"><h3 class="card-title">নিবন্ধনের প্রবণতা · <?=e(praan_module_option('reports','trend_months'))?> মাস</h3><span class="card-label">নিবন্ধনের তারিখ অনুযায়ী</span></div><div class="card-body"><?php analytics_trend_chart($summary['trend'],$filters);?></div></section>
    <div class="analytics-section-line"><h2>বিস্তারিত পরিসংখ্যান</h2><span>অনুপাতে প্রতিটি বিভাগের সংখ্যা / মোট রেকর্ড</span></div>
    <div class="analytics-charts-grid">
        <?php foreach([
            ['নিবন্ধনের স্ট্যাটাস','status',7,'status'],['জেলাভিত্তিক','district',12,'district'],
            ['উপজেলাভিত্তিক','upazila',12,null],['প্রকল্প ও কর্মসূচি','project',10,'project'],
            ['লিঙ্গভিত্তিক','gender',8,'gender'],['স্বেচ্ছাসেবক ক্যাটাগরি','category',10,'category'],
            ['ডাটা সংগ্রহের উৎস','source_type',8,'source_type'],['রক্তের গ্রুপ','blood_group',12,null],
            ['স্বেচ্ছাসেবী আগ্রহ','interests',16,null],['ডিজিটাল দক্ষতা','digital_skills',16,null],
            ['জরুরি সময়ে দায়িত্ব','emergency_duty',8,null]
        ] as [$label,$col,$limit,$drill]): analytics_group_card($label,$col,$summary,$filters,$limit,$drill); endforeach; ?>
    </div>
    <div class="card dashboard-card analytics-quality-panel"><div class="card-header"><h3 class="card-title">ডাটা কোয়ালিটি ও করণীয়</h3><span class="card-label">DATA COMPLETENESS</span></div><div class="card-body"><div class="analytics-quality-grid">
        <?php foreach([['মোবাইল তথ্য অনুপস্থিত','no_phone'],['জেলা অনুপস্থিত','no_district'],['দক্ষতা/আগ্রহ অনুপস্থিত','no_interests'],['সম্মতি এন্ট্রি হয়নি','no_consents']] as [$label,$key]): ?><div><strong><?=number_format($m[$key])?></strong><span><?=e($label)?></span></div><?php endforeach;?>
    </div><p class="analytics-help">কাগজের ফর্মের তথ্য যাচাই করে সংশোধন করুন। বিশেষ করে সম্মতি কাগজে আছে কিন্তু সফটওয়্যারে এন্ট্রি হয়নি—এমন রেকর্ড যাচাই প্রয়োজন।</p></div></div>
    <div class="analytics-download-box"><div><h3>নির্বাচিত রিপোর্ট ডাউনলোড করুন</h3><p>সংক্ষিপ্ত রিপোর্টে ব্যক্তিগত শনাক্তকারী তথ্য নেই। পূর্ণ রেকর্ড এক্সপোর্টে NID, মোবাইল ও অন্য ব্যক্তিগত তথ্য থাকতে পারে—শুধু অনুমোদিত অ্যাডমিনের জন্য।</p></div><div>
        <a class="btn btn-light btn-sm" href="<?=e(analytics_link('report_download',$filters,['format'=>'xlsx','mode'=>'summary']))?>"><i class="bi bi-file-spreadsheet"></i> Summary XLSX</a>
        <?php if(praan_flag_enabled('reports','detail_export')):?><a class="btn btn-outline-light btn-sm" href="<?=e(analytics_link('report_download',$filters,['format'=>'xlsx','mode'=>'detail']))?>"><i class="bi bi-download"></i> Filtered Records XLSX</a><?php endif;?>
        <?php if(praan_flag_enabled('reports','detail_export')):?><a class="btn btn-outline-light btn-sm" href="<?=e(analytics_link('report_download',$filters,['format'=>'csv','mode'=>'detail']))?>"><i class="bi bi-filetype-csv"></i> Filtered Records CSV</a><?php endif;?>
    </div></div>
    <?php page_end(true);
}
function render_report_print_v110(PDO $pdo): void {
    require_admin(); $filters=analytics_filters($_GET); $s=analytics_summary($pdo,$filters);$m=$s['metrics'];
    header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
    ?><!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PRAAN রিপোর্ট · <?=e(date('Y-m-d'))?></title>
    <style>
        @page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Noto Sans Bengali","Hind Siliguri",sans-serif;color:#18304a;margin:20px;background:#fff}header{border-bottom:3px solid #168b8b;padding-bottom:12px;display:flex;align-items:flex-start;justify-content:space-between}h1{font-size:21px;margin:4px 0}h2{font-size:14px;margin:22px 0 8px;color:#146f75}p{font-size:11px}.muted{color:#5e7385}.tools{display:flex;justify-content:end;gap:10px;margin:0 0 12px}button{padding:9px 14px;background:#168b8b;color:white;border:0;border-radius:6px;cursor:pointer}.stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:15px}.stat{border:1px solid #d9e7e8;border-radius:8px;padding:12px;flex:1;min-width:110px}.stat strong{display:block;font-size:19px}.stat span{font-size:10px;color:#557b85}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}section{break-inside:avoid}table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:5px 9px;border:1px solid #dce5eb;text-align:left}th{background:#edf6f6}small{font-size:10px}.foot{border-top:1px solid #dde5e8;margin-top:18px;padding-top:8px;font-size:10px;color:#789}@media print{.tools{display:none}body{margin:0}}
    </style></head><body><div class="tools"><button onclick="window.print()">প্রিন্ট / Save as PDF</button></div><header><div><small>PARTICIPATORY RESEARCH & ACTION NETWORK · PRAAN</small><h1>স্বেচ্ছাসেবক রিপোর্ট · <?=e(date('d-m-Y'))?></h1><p class="muted">রিপোর্টটি কেবল অ্যাডমিনের জন্য। ব্যক্তিগত পরিচয় বা যোগাযোগের তথ্য অন্তর্ভুক্ত নয়।</p></div><div><strong>PRAAN Volunteer Hub</strong><p>v<?=APP_VERSION?></p></div></header>
    <p>ফিল্টার: <?php $labels=[];foreach(['status'=>'স্ট্যাটাস','district'=>'জেলা','gender'=>'লিঙ্গ','project'=>'প্রকল্প','source_type'=>'উৎস','category'=>'ক্যাটাগরি','date_from'=>'শুরু','date_to'=>'শেষ','q'=>'সার্চ'] as $key=>$label) if(!empty($filters[$key]))$labels[]=$label.': '.$filters[$key];echo e(implode(' · ',$labels)?:'সব রেকর্ড');?></p>
    <div class="stats"><?php foreach([['মোট রেকর্ড',$m['total']],['সক্রিয়',$m['active']],['অপেক্ষমাণ',$m['pending']],['কাগজ থেকে এন্ট্রি',$m['paper']],['প্রশিক্ষণপ্রাপ্ত',$m['trained']],['জেলা',$m['districts']]] as [$label,$value]):?><div class="stat"><strong><?=number_format($value)?></strong><span><?=e($label)?></span></div><?php endforeach;?></div>
    <div class="grid"><?php foreach(['status'=>'স্ট্যাটাস','gender'=>'লিঙ্গ','district'=>'জেলা','source_type'=>'ডাটা উৎস','project'=>'প্রকল্প','category'=>'ক্যাটাগরি','interests'=>'আগ্রহ','digital_skills'=>'ডিজিটাল দক্ষতা','upazila'=>'উপজেলা','blood_group'=>'রক্তের গ্রুপ'] as $category=>$label):?><section><h2><?=e($label)?></h2><table><thead><tr><th>বিভাগ</th><th>সংখ্যা</th><th>অনুপাত</th></tr></thead><tbody><?php foreach(array_slice($s['groups'][$category]??[],0,12,true) as $name=>$count):?><tr><td><?=e($name)?></td><td><?=$count?></td><td><?=analytics_percentage($count,$m['total'])?>%</td></tr><?php endforeach;if(empty($s['groups'][$category])):?><tr><td colspan="3">কোনো তথ্য নেই</td></tr><?php endif;?></tbody></table></section><?php endforeach;?></div>
    <div class="foot">Generated: <?=e(date('Y-m-d H:i'))?> · PRAAN Volunteer Management System v<?=APP_VERSION?> · A4 Landscape</div></body></html><?php
}
