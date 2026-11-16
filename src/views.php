<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings_hub.php';

function site_head(string $title): void {
    $accent = setting('accent', '#168b8b');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent = '#168b8b';
    ?>
    <!doctype html><html lang="bn" data-bs-theme="light" data-praan-version="<?=e(APP_VERSION)?>" data-praan-density="<?=e(praan_option('ui_density'))?>" data-praan-motion="<?=e(praan_option('ui_motion'))?>"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="praan-actor" content="<?=admin() ? (int)admin_id() : 0?>">
    <meta name="praan-pwa" content="<?=praan_enabled('pwa_enabled')?'1':'0'?>">
    <meta name="praan-queue-limit" content="<?=e(praan_option('offline_queue_limit'))?>">
    <meta name="praan-offline-queue" content="<?=admin()&&praan_can('volunteers.create')&&praan_enabled('offline_queue_enabled')?'1':'0'?>">
    <meta name="praan-command" content="<?=praan_enabled('command_palette_enabled')?'1':'0'?>">
    <?php if(praan_enabled('pwa_enabled')): ?><link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/pwa-icon-192.png"><?php endif; ?><meta name="theme-color" content="<?= e($accent) ?>">
    <title><?= e($title) ?> | <?=e(praan_option('site_title'))?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.16.0/styles/overlayscrollbars.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="assets/layout-fallback.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/app.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/analytics.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/update-center.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/praan-operations.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/volunteer-table.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/praan-foundation.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/praan-media.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/praan-access.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/praan-modules.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="assets/praan-mobile.css?v=<?= APP_VERSION ?>">
    <style>:root{--accent:<?=e($accent)?>;--accent-dark:<?=e($accent)?>}</style>
    <script>try{document.documentElement.setAttribute('data-bs-theme',localStorage.getItem('pran_theme')||'light')}catch(e){}</script>
    </head><?php
}
function site_scripts(): void { ?>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.16.0/browser/overlayscrollbars.browser.es6.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/js/adminlte.min.js"></script>
    <script src="assets/app.js?v=<?= APP_VERSION ?>"></script>
    <script src="assets/volunteer-table.js?v=<?= APP_VERSION ?>"></script>
    <script src="assets/praan-foundation.js?v=<?= APP_VERSION ?>"></script>
    <script src="assets/praan-media.js?v=<?= APP_VERSION ?>"></script>
    <script src="assets/praan-modules.js?v=<?= APP_VERSION ?>"></script>
    <script src="assets/praan-mobile.js?v=<?= APP_VERSION ?>"></script>
    <script src="assets/praan-offline.js?v=<?= APP_VERSION ?>"></script>
    <?php
}
function page_start(string $title, string $active = '', bool $dashboard = false): void {
    site_head($title);
    if ($dashboard && admin()): ?>
    <body class="layout-fixed sidebar-expand-lg bg-body-tertiary admin-layout"><div class="app-wrapper">
        <nav class="app-header navbar navbar-expand bg-body border-bottom">
            <div class="container-fluid"><ul class="navbar-nav"><li class="nav-item"><a class="nav-link" data-lte-toggle="sidebar" href="#" id="pranSidebarToggle" aria-expanded="true" aria-controls="pranAdminSidebar" aria-label="সাইডবার মেনু"><i class="bi bi-list fs-5"></i></a></li></ul>
            <div class="top-search d-none d-md-flex"><form action="index.php" method="get" class="d-flex"><input type="hidden" name="page" value="volunteers"><i class="bi bi-search"></i><input type="search" name="q" placeholder="নাম, মোবাইল বা ID দিয়ে খুঁজুন..." aria-label="Search"></form></div>
            <div class="ms-auto d-flex align-items-center gap-2 gap-md-3"><?php if(praan_enabled('command_palette_enabled')):?><button type="button" class="icon-button praan-command-trigger" data-praan-command-open title="Command Palette Ctrl+K" aria-label="দ্রুত সার্চ"><i class="bi bi-command"></i></button><?php endif;?><button type="button" class="icon-button praan-install-trigger" data-praan-install hidden title="Install PRAAN" aria-label="অ্যাপ ইনস্টল করুন"><i class="bi bi-download"></i></button><button type="button" class="icon-button theme-toggle" title="ডার্ক মোড"><i class="bi bi-moon-stars"></i></button><?php if(praan_can('volunteers.create')):?><a href="<?=e(url('new'))?>" class="btn btn-brand btn-sm"><i class="bi bi-plus-lg"></i> <span class="d-none d-sm-inline">নতুন এন্ট্রি</span></a><?php endif;?><a class="admin-initial" href="<?=e(url('profile'))?>" title="<?=e($_SESSION['admin_name'] ?? 'Profile')?>"><?=e(praan_initial((string)($_SESSION['admin_name']??'A')))?></a></div></div>
        </nav>
        <aside class="app-sidebar shadow" id="pranAdminSidebar" data-bs-theme="dark"><div class="sidebar-brand"><a href="<?=e(url('dashboard'))?>" class="brand-link"><img src="assets/pran-logo.png" alt="PRAAN" class="sidebar-logo"><span class="brand-text"><?=e(praan_option('site_title'))?> <small><?=e(praan_option('site_tagline'))?></small></span></a></div>
        <div class="sidebar-wrapper"><nav class="mt-3"><ul class="nav sidebar-menu flex-column" role="menu">
            <?php foreach ([
                ['dashboard','bi-grid-1x2','Advanced Dashboard'],['volunteers','bi-people','স্বেচ্ছাসেবক তালিকা'],['new','bi-person-plus','নতুন ফর্ম এন্ট্রি'],['import','bi-cloud-arrow-up','Excel / CSV ইমপোর্ট'],['reports','bi-bar-chart-line','Advanced Reports'],['activity','bi-clock-history','অ্যাক্টিভিটি লগ'],['archived','bi-archive','আর্কাইভ'],['export','bi-file-earmark-excel','Excel এক্সপোর্ট'],['updates','bi-arrow-repeat','সাইট আপডেট'],['system_doctor','bi-heart-pulse','System Doctor'],['settings','bi-sliders','Control Center'],['modules','bi-puzzle','Module Manager'],['users','bi-person-gear','ইউজার ম্যানেজমেন্ট'],['roles','bi-shield-lock','রোল ও পারমিশন'],['sessions','bi-laptop','অ্যাক্টিভ সেশন'],['login_history','bi-clock-history','লগইন হিস্ট্রি'],['profile','bi-person-circle','আমার প্রোফাইল'],
            ] as [$key,$icon,$label]): ?>
            <?php if(($key==='import'&&!praan_module_access('import'))||($key==='reports'&&!praan_module_access('reports'))||($key==='updates'&&!praan_enabled('update_notice_enabled'))||($key!=='profile' && praan_route_permission($key)!==null && !praan_can(praan_route_permission($key))))continue; ?>
            <li class="nav-item"><a href="<?=e(url($key))?>" class="nav-link <?= $key===$active?'active':''?>"><i class="nav-icon bi <?=e($icon)?>"></i><p><?=e($label)?></p></a></li>
            <?php endforeach; ?>
            <li class="nav-header">PUBLIC ACCESS</li>
            <li class="nav-item"><a href="<?=e(url('home'))?>" class="nav-link"><i class="nav-icon bi bi-globe"></i><p>পাবলিক পোর্টাল</p></a></li>
            <?php if(praan_enabled('public_directory_enabled')&&praan_module_access('directory')):?><li class="nav-item"><a href="<?=e(url('directory'))?>" class="nav-link"><i class="nav-icon bi bi-card-list"></i><p>পাবলিক ডিরেক্টরি</p></a></li><?php endif;?>
            <li class="nav-item"><form method="post" action="<?=e(url('logout'))?>"><?=csrf_field()?><button type="submit" class="nav-link praan-logout-btn"><i class="nav-icon bi bi-box-arrow-right"></i><p>লগআউট</p></button></form></li>
        </ul></nav><div class="sidebar-bottom"><span class="version-dot"></span> v<?=APP_VERSION?> <?php if(praan_enabled('developer_badge_enabled'))praan_credit_badge(); ?></div></div></aside>
        <main class="app-main"><div class="app-content-header"><div class="container-fluid"><div class="d-flex align-items-center justify-content-between flex-wrap gap-2"><div><div class="eyebrow">PRAAN / VOLUNTEER DATABASE</div><h1 class="page-heading"><?=e($title)?></h1></div><div class="header-date"><i class="bi bi-calendar3"></i> <?=e(date('d M Y'))?></div></div></div></div><div class="app-content"><div class="container-fluid">
    <?php else: ?>
    <body class="public-body"><div class="public-shell"><header class="public-nav"><div class="public-nav-inner"><a href="<?=e(url('home'))?>" class="public-brand"><img src="assets/pran-logo.png" alt="PRAAN"><span><?=e(praan_option('site_title'))?><small><?=e(praan_option('site_tagline'))?></small></span></a><div class="public-links"><button class="icon-button praan-install-trigger" type="button" data-praan-install hidden title="অ্যাপ ইনস্টল করুন" aria-label="অ্যাপ ইনস্টল করুন"><i class="bi bi-download"></i></button><a href="<?=e(url('home'))?>" class="<?= $active==='home'?'chosen':''?>">হোম</a><?php if(praan_enabled('public_directory_enabled')&&praan_module_access('directory')):?><a href="<?=e(url('directory'))?>" class="<?= $active==='directory'?'chosen':''?>">স্বেচ্ছাসেবক তালিকা</a><?php endif;?><?php if(praan_enabled('public_registration_enabled')):?><a href="<?=e(url('register'))?>" class="btn btn-brand btn-sm">নিবন্ধন করুন</a><?php endif;?><a href="<?=e(url(admin()?'dashboard':'login'))?>" class="nav-login"><i class="bi bi-shield-lock"></i> অ্যাডমিন</a><button type="button" class="icon-button theme-toggle" aria-label="Toggle theme"><i class="bi bi-moon-stars"></i></button></div></div></header><main class="public-main">
    <?php endif;
    $flashes=consume_flashes();
    if(praan_enabled('notice_enabled') && praan_option('notice_text')!=='') echo '<div class="praan-notice">'.e(praan_option('notice_text')).'</div>';
    if($flashes){echo '<div class="pran-toast-stack" role="status" aria-live="polite">';foreach($flashes as $f){$type=in_array($f['type'],['success','danger','warning','info'],true)?$f['type']:'info';$icon=$type==='success'?'check-circle-fill':($type==='danger'?'exclamation-triangle-fill':'info-circle-fill');echo '<div class="pran-toast '.e($type).'" role="'.($type==='danger'?'alert':'status').'"><i class="bi bi-'.e($icon).'"></i><div><strong>'.($type==='success'?'সফল হয়েছে':($type==='danger'?'সমস্যা হয়েছে':'বিজ্ঞপ্তি')).'</strong><p>'.e($f['message']).'</p></div><button type="button" data-toast-close aria-label="বন্ধ করুন">&times;</button></div>';}echo '</div>';}

}
function praan_credit_badge(): void {
    echo '<button type="button" class="praan-credit" data-praan-credit title="Rony Siddiqi · Developer showcase">Crafted with <span class="praan-heart">❤️</span> by Rony Siddiqi</button>';
}
function praan_developer_modal(): void {
    if (!praan_enabled('developer_badge_enabled')) return;
    $facebook=praan_option('support_facebook');$telegram=praan_option('support_telegram');$phone=praan_option('support_whatsapp_phone');
    echo '<dialog id="praanDeveloperDialog" class="praan-brand-modal"><div class="praan-showcase"><button type="button" class="btn btn-light btn-sm praan-close" data-praan-close aria-label="Close">✕</button><div class="praan-emblem" id="praanEmblem"><i class="bi bi-code-slash"></i></div><span class="eyebrow">CRAFTED WITH CARE</span><h2>Rony Siddiqi</h2><p>PRAAN Volunteer Management · Developer &amp; Support</p><div class="praan-socials">';
    if($facebook!=='')echo '<a rel="noopener noreferrer" target="_blank" href="'.e($facebook).'"><i class="bi bi-facebook"></i> Facebook</a>';
    echo '<a id="praanWhatsapp" data-phone="'.e($phone).'" rel="noopener noreferrer" target="_blank" href="https://wa.me/"><i class="bi bi-whatsapp"></i> WhatsApp Support</a>';
    if($telegram!=='')echo '<a rel="noopener noreferrer" target="_blank" href="'.e($telegram).'"><i class="bi bi-telegram"></i> Telegram</a>';
    echo '</div><p class="small">WhatsApp খুললে অ্যাপ ভার্সন ও ব্রাউজারের তথ্য মেসেজে যুক্ত হবে। পাঠানোর আগে আপনি সম্পাদনা করতে পারবেন।</p><small class="text-muted">v'.e(APP_VERSION).' · PRAAN</small></div></dialog>';
}
function praan_mobile_bottom_nav(): void {
    if(!praan_enabled('mobile_nav_enabled')) return;
    $isAdmin=admin();
    $items=$isAdmin ? [
      ['dashboard','bi-house-door','হোম','dashboard.view'],
      ['volunteers','bi-people','তালিকা','volunteers.view'],
      ['new','bi-plus-circle','নতুন','volunteers.create'],
      ['reports','bi-bar-chart','রিপোর্ট','reports.view']
    ] : [['home','bi-house-door','হোম',null],['register','bi-person-plus','নিবন্ধন',null],['directory','bi-people','তালিকা',null]];
    echo '<nav class="praan-bottom-nav" aria-label="মোবাইল নেভিগেশন">';
    foreach($items as [$page,$icon,$label,$permission]){
      if($permission!==null&&!praan_can($permission))continue;
      if($page==='reports'&&!praan_module_access('reports'))continue;
      if($page==='register'&&!praan_enabled('public_registration_enabled'))continue;
      if($page==='directory'&&(!praan_enabled('public_directory_enabled')||!praan_module_access('directory')))continue;
      echo '<a href="'.e(url($page)).'" class="praan-bottom-link" data-page="'.e($page).'" aria-label="'.e($label).'"><i class="bi '.e($icon).'"></i><span>'.e($label).'</span></a>';
    }
    if($isAdmin&&praan_enabled('command_palette_enabled'))echo '<button type="button" class="praan-bottom-link" data-praan-command-open aria-label="আরও পেজ"><i class="bi bi-grid"></i><span>আরও</span></button>';
    echo '</nav>';
}
function praan_mobile_widgets(): void {
    if(praan_enabled('command_palette_enabled') && admin()) {
      $items=[['dashboard','bi-house-door','ড্যাশবোর্ড','dashboard.view'],['volunteers','bi-people','স্বেচ্ছাসেবক তালিকা','volunteers.view'],['new','bi-person-plus','নতুন স্বেচ্ছাসেবক','volunteers.create'],['reports','bi-graph-up','রিপোর্ট ও বিশ্লেষণ','reports.view'],['import','bi-file-earmark-arrow-up','Excel / CSV ইমপোর্ট','import.use'],['settings','bi-sliders','Control Center','settings.manage'],['modules','bi-puzzle','মডিউল ম্যানেজার','modules.view'],['users','bi-person-gear','ইউজার ম্যানেজমেন্ট','users.view'],['updates','bi-arrow-repeat','সিস্টেম আপডেট','updates.manage'],['system_doctor','bi-heart-pulse','System Doctor','updates.manage'],['profile','bi-person-circle','আমার প্রোফাইল','dashboard.view']];
      echo '<dialog id="praanCommandDialog" class="praan-command-dialog" aria-label="দ্রুত সার্চ ও কমান্ড"><div class="praan-command-head"><i class="bi bi-search"></i><input type="search" id="praanCommandInput" placeholder="পেজ বা কমান্ড খুঁজুন…" autocomplete="off" aria-label="পেজ খুঁজুন"><button type="button" data-praan-command-close aria-label="বন্ধ করুন">ESC</button></div><div class="praan-command-results" id="praanCommandResults" role="listbox">';
      foreach($items as [$page,$icon,$label,$permission]){
        if(!praan_can($permission))continue;
        if($page==='reports'&&!praan_module_access('reports'))continue;
        if($page==='import'&&!praan_module_access('import'))continue;
        echo '<a role="option" href="'.e(url($page)).'" data-command-label="'.e($label).'" class="praan-command-item"><i class="bi '.e($icon).'"></i><span>'.e($label).'</span><i class="bi bi-arrow-up-right"></i></a>';
      }
      echo '</div><div class="praan-command-foot">Ctrl+K / ⌘K · / · ↑↓ · Enter · Esc</div></dialog>';
    }
    if(admin()&&praan_can('volunteers.create')&&praan_enabled('offline_queue_enabled')){
      echo '<button type="button" class="praan-queue-fab" id="praanQueueFab"><i class="bi bi-shield-lock"></i><span>অফলাইন কিউ</span><b id="praanQueueCount" hidden>0</b></button>';
      echo '<dialog class="praan-queue-dialog" id="praanQueueDialog" aria-label="এনক্রিপ্টেড অফলাইন কিউ"><div class="praan-queue-heading"><h2><i class="bi bi-cloud-arrow-up"></i> নিরাপদ অফলাইন কিউ</h2><button type="button" data-praan-queue-close class="btn btn-sm btn-outline-secondary">বন্ধ</button></div><p class="small text-muted">ফর্মের তথ্য এই ডিভাইসে AES-GCM এনক্রিপশনে থাকবে। ছবির ফাইল সংরক্ষণ হবে না। পুনরায় পাঠাতে পাসফ্রেজ প্রয়োজন হতে পারে।</p><div id="praanQueueRows" aria-live="polite"></div></dialog>';
    }
}
function page_end(bool $dashboard = false): void {
    if ($dashboard && admin()): ?>
        </div></div></main><?php praan_mobile_bottom_nav(); ?><footer class="app-footer"><div class="float-end">v<?=APP_VERSION?> <?php if(praan_enabled('developer_badge_enabled'))praan_credit_badge(); ?></div><strong>© <?=date('Y')?> PRAAN Network</strong></footer></div>
    <?php else: ?>
        </main><?php praan_mobile_bottom_nav(); ?><footer class="public-footer"><div><strong>PRAAN Volunteer Hub</strong><p>একসাথে মানবিক কার্যক্রম, একসাথে পরিবর্তন।</p></div><div><?php if(praan_enabled('public_registration_enabled')):?><a href="<?=e(url('register'))?>">নিবন্ধন</a><?php endif;?><?php if(praan_enabled('public_directory_enabled')&&praan_module_access('directory')):?><a href="<?=e(url('directory'))?>">পাবলিক তালিকা</a><?php endif;?><a href="<?=e(url('login'))?>">অ্যাডমিন লগইন</a></div><small>© <?=date('Y')?> PRAAN Network · v<?=APP_VERSION?> <?php if(praan_enabled('developer_badge_enabled'))praan_credit_badge(); ?></small></footer></div>
    <?php endif; praan_developer_modal(); praan_mobile_widgets(); site_scripts(); echo '</body></html>';
}
function render_input(string $field, array $def, array $data): void {
    [$label,$type] = $def;
    $value = $data[$field] ?? ($type === 'multi' ? [] : '');
    $wide = in_array($type,['multi','textarea'],true) || in_array($field,['name_en','name_bn','experience_summary'],true);
    $classes = 'field-item' . ($wide ? ' field-wide' : '');
    $id = 'f-' . $field;
    echo '<div class="'.e($classes).'"><label class="form-label" for="'.e($id).'">'.e($label).'</label>';
    if ($type === 'multi') {
        $items = is_array($value) ? $value : [];
        echo '<div class="choice-grid" id="'.e($id).'">';
        foreach ($def[2] as $key => $text) {
            $opt = is_int($key) ? $text : (string)$key;
            echo '<label class="choice"><input class="form-check-input" type="checkbox" name="data['.e($field).'][]" value="'.e($opt).'" '.(in_array($opt,$items,true)?'checked':'').'><span>'.e($text).'</span></label>';
        }
        echo '</div>';
    } elseif ($type === 'select') {
        echo '<select class="form-select" id="'.e($id).'" name="data['.e($field).']"><option value="">নির্বাচন করুন...</option>';
        foreach ($def[2] as $option) echo '<option value="'.e($option).'" '.($value===$option?'selected':'').'>'.e($option).'</option>';
        echo '</select>';
    } elseif ($type === 'textarea') {
        echo '<textarea class="form-control" rows="3" id="'.e($id).'" name="data['.e($field).']" placeholder="এখানে লিখুন...">'.e($value).'</textarea>';
    } else {
        echo '<input class="form-control" type="'.e($type).'" id="'.e($id).'" name="data['.e($field).']" value="'.e($value).'" '.($field === 'name_en' ? 'style="text-transform:uppercase"' : '').' autocomplete="off">';
    }
    echo '</div>';
}
function render_history(string $key, string $heading, array $cols, array $data): void {
    $rows = $data[$key] ?? [];
    echo '<div class="history-area"><div class="block-head"><h3>'.e($heading).'</h3><small>একাধিক হলে পরবর্তী সারিতে লিখুন</small></div><div class="table-responsive"><table class="table table-bordered history-table"><thead><tr>';
    foreach ($cols as $label) echo '<th>'.e($label).'</th>';
    echo '</tr></thead><tbody>';
    for ($i=0;$i<4;$i++) {
        echo '<tr>';
        foreach ($cols as $field => $label) {
            echo '<td><input class="form-control form-control-sm" aria-label="'.e($label).'" type="text" name="data['.e($key).']['.$i.']['.e($field).']" value="'.e($rows[$i][$field] ?? '').'"></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
}
function volunteer_form(?array $volunteer, bool $isAdmin, bool $quickMode = false): void {
    $data = $volunteer['data'] ?? array_replace(default_data(),$_SESSION['form_draft']??[]);
    if (!$volunteer) unset($_SESSION['form_draft']);
    if (!$volunteer && empty($data['registration_date'])) $data['registration_date'] = date('Y-m-d');
    $sections = form_sections();
    $isEdit = !empty($volunteer);
    $isPublic = !$isAdmin;
    $storedDraft=(string)($volunteer['draft_uid']??'');
    $draftForForm=praan_uid_valid($storedDraft,'DRF')?$storedDraft:praan_uid('DRF');
    ?>
    <div class="form-intro"><div><span class="eyebrow">PRAAN NETWORK</span><h2><?= $isEdit?'স্বেচ্ছাসেবক তথ্য সম্পাদনা':($isAdmin?'স্বেচ্ছাসেবক ডাটা এন্ট্রি':'নতুন স্বেচ্ছাসেবক নিবন্ধন') ?></h2><p>মূল ফর্মের সব বিভাগ অনুসারে ধাপে ধাপে তথ্য দিন। <?= $isAdmin?'পুরোনো কাগজের ফর্মের তথ্যও এন্ট্রি করতে পারবেন।':'তারকা (*) চিহ্নিত তথ্য ও প্রয়োজনীয় সম্মতি দিন।' ?></p></div><div class="form-intro-icon"><i class="bi bi-person-vcard"></i></div></div>
    <div class="form-workspace" data-wizard>
    <nav class="step-nav" aria-label="ফর্মের ধাপ"><button type="button" class="step-link current" data-go="0"><b>01</b><span>পরিচিতি ও ব্যক্তিগত</span></button><button type="button" class="step-link" data-go="1"><b>02</b><span>ঠিকানা</span></button><button type="button" class="step-link" data-go="2"><b>03</b><span>দক্ষতা ও আগ্রহ</span></button><button type="button" class="step-link" data-go="3"><b>04</b><span>অভিজ্ঞতা</span></button><button type="button" class="step-link" data-go="4"><b>05</b><span>সম্মতি ও সংযুক্তি</span></button><?php if($isAdmin):?><button type="button" class="step-link" data-go="5"><b>06</b><span>অফিস ব্যবহার</span></button><?php endif;?></nav>
    <form method="post" enctype="multipart/form-data" action="<?=e(url('save'))?>" id="volunteerForm" data-praan-draft="<?=praan_enabled('draft_recovery_enabled')?'1':'0'?>" data-praan-draft-scope="<?=$isAdmin?'local':'session'?>" data-praan-draft-key="<?=e($isEdit?'edit-'.(int)$volunteer['id']:($isAdmin?'admin-new-'.(int)admin_id():'public-new'))?>" data-praan-async="<?=praan_enabled('async_photo_enabled')&&praan_flag_enabled('media','async_upload')?'1':'0'?>" data-praan-media-endpoint="index.php" data-praan-offline-form="<?=$isAdmin&&!$isEdit&&praan_enabled('offline_queue_enabled')?'1':'0'?>">
        <?=csrf_field()?><input type="hidden" name="entry_type" value="<?=$isAdmin?'admin':'public'?>"><input type="hidden" name="id" value="<?=e($volunteer['id'] ?? '')?>"><input type="hidden" name="quick_mode" value="<?=$quickMode?'1':'0'?>"><input type="hidden" name="draft_uid" value="<?=e($draftForForm)?>">
        <section class="form-step active" data-step="0"><div class="form-section-heading"><span class="section-number">A</span><div><h3>নিবন্ধন পরিচিতি ও ব্যক্তিগত তথ্য</h3><p>মূল ফর্মের প্রথম পৃষ্ঠা</p></div></div>
        <div class="field-grid"><?php foreach ($sections['header']['fields'] as $key=>$def) render_input($key,$def,$data); ?></div>
        <div class="photo-upload"><div class="photo-mark"><i class="bi bi-camera"></i></div><div><strong>পাসপোর্ট সাইজ ছবি</strong><p>ঐচ্ছিক · JPG, PNG বা WebP · সর্বোচ্চ <?=e(praan_option('max_photo_mb'))?>MB</p><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm" data-praan-photo><span class="praan-upload-help" aria-live="polite">ছবি নির্বাচন করলে ব্যাকগ্রাউন্ডে ১ MB করে আপলোড হবে। মাঝপথে বন্ধ হলে একই ফাইল আবার নির্বাচন করুন।</span><?php if ($isEdit && $isAdmin && $volunteer['photo_file']):?><label class="small"><input type="checkbox" name="remove_photo" value="1"> পুরোনো ছবি মুছুন</label><?php endif;?></div></div>
        <div class="field-grid"><?php foreach($sections['A']['fields'] as $key=>$def) render_input($key,$def,$data); ?></div></section>
        <section class="form-step" data-step="1"><div class="form-section-heading"><span class="section-number">B</span><div><h3>ঠিকানা</h3><p>বর্তমান ও স্থায়ী ঠিকানা</p></div></div><div class="field-grid"><?php foreach($sections['B']['fields'] as $key=>$def) { if($key==='permanent_holding') echo '<div class="field-wide mb-2"><button class="btn btn-outline-brand btn-sm" type="button" id="copyAddress"><i class="bi bi-copy"></i> বর্তমান ঠিকানা স্থায়ী ঠিকানায় কপি করুন</button></div>'; render_input($key,$def,$data); }?></div></section>
        <section class="form-step" data-step="2"><div class="form-section-heading"><span class="section-number">C</span><div><h3>স্বেচ্ছাসেবী আগ্রহ, দক্ষতা ও প্রাপ্যতা</h3><p>যে ধরনের কার্যক্রমে অংশ নিতে পারবেন</p></div></div><div class="field-grid"><?php foreach($sections['C']['fields'] as $key=>$def) render_input($key,$def,$data); ?></div></section>
        <section class="form-step" data-step="3"><div class="form-section-heading"><span class="section-number">D</span><div><h3>অভিজ্ঞতা ও প্রশিক্ষণ</h3><p>আগের কাজ এবং প্রাসঙ্গিক প্রশিক্ষণ</p></div></div><div class="field-grid"><?php foreach($sections['D']['fields'] as $key=>$def) render_input($key,$def,$data); ?></div>
        <?php render_history('work_history','পূর্ববর্তী স্বেচ্ছাসেবী/কর্ম অভিজ্ঞতা',['org'=>'সংস্থা/প্রকল্প','duty'=>'দায়িত্ব/কাজ','area'=>'এলাকা','period'=>'সময়কাল','reference'=>'রেফারেন্স/মোবাইল'],$data); ?>
        <?php render_history('training_history','প্রাসঙ্গিক প্রশিক্ষণ',['title'=>'প্রশিক্ষণের নাম','organizer'=>'আয়োজক','period'=>'তারিখ/সময়কাল','certificate'=>'সনদ আছে?'],$data); ?></section>
        <section class="form-step" data-step="4"><div class="form-section-heading"><span class="section-number">E–G</span><div><h3>নিরাপত্তা, সম্মতি ও সংযুক্তি</h3><p>সচেতন ও নিরাপদ স্বেচ্ছাসেবা নিশ্চিত করতে</p></div></div>
            <?php foreach(['E','F','G'] as $sect):?><div class="subsection-title"><?=e($sections[$sect]['title'])?></div><div class="field-grid"><?php foreach($sections[$sect]['fields'] as $key=>$def) render_input($key,$def,$data);?></div><?php endforeach; ?>
            <div class="notice-box"><i class="bi bi-shield-check"></i><div><strong>তথ্য ও নিরাপত্তা</strong><p>নিবন্ধনের জন্য আচরণবিধি, নিরাপত্তা, গোপনীয়তা, তথ্য ব্যবহার ও জরুরি যোগাযোগের সম্মতি প্রযোজ্য। ছবি/ভিডিও ব্যবহারের সম্মতি সম্পূর্ণ ঐচ্ছিক।</p></div></div>
            <label class="consent-public"><input type="checkbox" name="public_visible" value="1" <?=!empty($volunteer['public_visible'])?'checked':''?>><span><strong>পাবলিক স্বেচ্ছাসেবক তালিকায় আমার সীমিত প্রোফাইল দেখাতে সম্মত</strong><small>শুধু নাম, স্বেচ্ছাসেবক আইডি, জেলা, দক্ষতা ও অনুমোদিত স্ট্যাটাস দেখানো হবে। ফোন, NID, ঠিকানা, আর্থিক তথ্য, ছবি প্রকাশ হবে না।</small></span></label>
        </section>
        <?php if($isAdmin):?><section class="form-step" data-step="5"><div class="form-section-heading"><span class="section-number">H</span><div><h3>শুধু অফিস ব্যবহারের জন্য</h3><p>যাচাই, অনুমোদন, ক্যাটাগরি ও টিম বরাদ্দ</p></div></div><div class="field-grid"><?php foreach($sections['H']['fields'] as $key=>$def) render_input($key,$def,$data);?></div></section><?php endif; ?>
        <div class="wizard-footer"><span class="form-progress-label">ধাপ <b id="stepCurrent">১</b> / <?= $isAdmin?6:5 ?></span><div class="wizard-actions"><?php if($isAdmin&&!$isEdit&&praan_enabled('offline_queue_enabled')):?><button type="button" class="btn btn-outline-brand" id="praanOfflineSave"><i class="bi bi-cloud-slash"></i> অফলাইনে নিরাপদে রাখুন</button><?php endif;?><button type="button" class="btn btn-outline-secondary" id="stepPrev" style="display:none"><i class="bi bi-arrow-left"></i> আগের ধাপ</button><button type="button" class="btn btn-brand" id="stepNext">পরবর্তী ধাপ <i class="bi bi-arrow-right"></i></button><button type="submit" class="btn btn-success" id="formSubmit" style="display:none"><i class="bi bi-check2-circle"></i> <?= $isEdit?'পরিবর্তন সংরক্ষণ':'নিবন্ধন সংরক্ষণ' ?></button></div></div>
    </form><div id="praanEarlySaveResult" class="praan-early-save" hidden role="status" aria-live="polite"></div></div>
    <?php
}
function display_record(array $v): void {
    $d = $v['data'];
    echo '<div class="record-title"><div class="record-identity">';
    if (!empty($v['photo_file']) && (!admin() || praan_can('volunteers.sensitive'))) {
        $tokenData = admin() ? [] : ['token'=>(string)($_GET['token']??'')];
        echo '<img class="record-photo" src="'.e(url('photo',array_merge(['id'=>$v['id']],$tokenData))).'" alt="স্বেচ্ছাসেবকের ছবি">';
    }
    echo '<div><span class="eyebrow">'.e($v['volunteer_code']).'</span><h2>'.e($v['name_bn'] ?: $v['name_en']).'</h2><p>'.e($v['project'] ?: 'PRAAN Volunteer').'</p><span class="status-pill '.($v['status']==='সক্রিয়'?'success':'pending').'">'.e($v['status']).'</span></div></div><div class="record-actions">';
    if (praan_can('volunteers.edit')) echo '<a class="btn btn-outline-brand" href="'.e(url('edit',['id'=>$v['id']])).'"><i class="bi bi-pencil-square"></i> এডিট</a>';
    $authQuery = admin() ? [] : ['token'=>(string)($_GET['token'] ?? '')];
    echo '<a class="btn btn-brand" href="'.e(url('print',array_merge(['id'=>$v['id']],$authQuery))).'" target="_blank" rel="noopener"><i class="bi bi-printer"></i> A4 প্রিন্ট / PDF</a>';
    if (praan_can('volunteers.export') && praan_enabled('docx_export_enabled')) echo '<a class="btn btn-outline-brand" href="'.e(url('download_docx',['id'=>$v['id']])).'"><i class="bi bi-file-earmark-word"></i> DOCX ডাউনলোড</a>';
    echo '</div></div>';
    $limited=admin() && !praan_can('volunteers.sensitive');
    $limitedFields=['name_bn','name_en','gender','occupation','education','present_district','permanent_district','interests','availability','time_available','travel_scope','languages','special_skills','digital_skills','category','status','project','registration_date','form_no'];
    foreach (form_sections() as $name=>$section) {
        if ($name==='H' && (!admin()||$limited)) continue;
        if ($limited && in_array($name,['D','E','F','G'],true))continue;
        echo '<div class="detail-section"><h3>'.e($section['title']).'</h3><div class="detail-grid">';
        foreach ($section['fields'] as $key=>$def) {
            if($limited && !in_array($key,$limitedFields,true))continue;
            $val = display_value($d[$key] ?? '');
            if ($key === 'consents' && is_array($d[$key] ?? null)) {
                $choices = form_sections()['F']['fields']['consents'][2];
                $val = implode(', ',array_map(fn($c) => $choices[$c] ?? $c,$d[$key]));
            }
            if ($val === '') continue;
            if ($key==='id_number' && !admin()) continue;
            echo '<div class="detail-cell"><small>'.e($def[0]).'</small><strong>'.e($val).'</strong></div>';
        }
        echo '</div>';
        if($name==='D') {
            foreach (['work_history'=>'অভিজ্ঞতার তালিকা','training_history'=>'প্রশিক্ষণের তালিকা'] as $field=>$heading) {
                if (!empty($d[$field])) { echo '<div class="subsection-title">'.e($heading).'</div>'; foreach($d[$field] as $row) echo '<p class="record-history">'.e(implode(' · ',array_filter($row))).'</p>'; }
            }
        }
        echo '</div>';
    }
}
