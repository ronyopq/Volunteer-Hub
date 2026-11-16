<?php
declare(strict_types=1);
require_once __DIR__ . '/src/runtime_errors.php';
require_once __DIR__ . '/src/views.php';
require_once __DIR__ . '/src/print.php';
require_once __DIR__ . '/src/export.php';
require_once __DIR__ . '/src/analytics_views.php';
require_once __DIR__ . '/src/updates.php';
require_once __DIR__ . '/src/volunteer_table.php';
require_once __DIR__ . '/src/docx_download.php';
require_once __DIR__ . '/src/background.php';
require_once __DIR__ . '/src/media_upload.php';
require_once __DIR__ . '/src/user_management.php';
require_once __DIR__ . '/src/module_views.php';

$pdo = db();
praan_auth_validate($pdo);
$page = (string)($_GET['page'] ?? (admin() ? 'dashboard' : 'home'));
if(admin()) {
    $minutes=(int)praan_option('admin_idle_minutes');
    if(isset($_SESSION['admin_last_active']) && time()-(int)$_SESSION['admin_last_active']>max(15,$minutes)*60){
        praan_session_end();unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['admin_last_active'],$_SESSION['praan_v16_session']);
        flash('warning','নিষ্ক্রিয় থাকার কারণে অ্যাডমিন সেশন শেষ হয়েছে।');redirect('login');
    }
    $_SESSION['admin_last_active']=time();
}
um_gate($page, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
praan_authorize_route($page);
praan_module_route_gate($page);
// Check GitHub on admin GET requests only, cached and rate limited.
if (admin() && ($_SERVER['REQUEST_METHOD']??'GET') === 'GET' && $page !== 'notification_poll') {
    praan_notifications_tick($pdo, $page === 'updates' && (string)($_GET['check']??'') === '1');
}
if ($page === 'notifications' && ($_SERVER['REQUEST_METHOD']??'GET') === 'GET') {praan_notifications_page($pdo);exit;}
if ($page === 'notification_poll' && ($_SERVER['REQUEST_METHOD']??'GET') === 'GET') {
    require_admin();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
    echo json_encode(praan_notification_payload($pdo, (int)admin_id()), JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if (in_array($page,['notification_read','notification_read_all','notification_send'],true) && ($_SERVER['REQUEST_METHOD']??'GET') === 'POST') {
    require_admin();verify_csrf();
    try {
        if ($page === 'notification_send') {
            if (!praan_is_super()) throw new RuntimeException('শুধু Super Admin নোটিফিকেশন পাঠাতে পারবেন।');
            um_require_password((string)($_POST['admin_password']??''));
            $total=praan_notification_broadcast($pdo, $_POST);
            flash('success',$total.' জনকে নোটিফিকেশন পাঠানো হয়েছে।');
        } elseif($page === 'notification_read_all') {
            $stmt=$pdo->prepare('UPDATE praan_notifications SET read_at=CURRENT_TIMESTAMP WHERE user_id=? AND read_at IS NULL');
            $stmt->execute([admin_id()]);flash('success','সব নোটিফিকেশন পড়া হয়েছে।');
        } else {
            $stmt=$pdo->prepare('UPDATE praan_notifications SET read_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?');
            $stmt->execute([(int)($_POST['id']??0),admin_id()]);
        }
    }catch(Throwable $e){error_log('PRAAN notification action: '.$e->getMessage());flash('danger','নোটিফিকেশন পরিবর্তন করা যায়নি।');}
    redirect('notifications');
}

if(($page==='register'&&!praan_enabled('public_registration_enabled'))||($page==='directory'&&!praan_enabled('public_directory_enabled'))){
    http_response_code(404);page_start('এই বিভাগটি বন্ধ');echo '<div class="directory-empty"><h2>এই বিভাগটি বর্তমানে বন্ধ রয়েছে।</h2></div>';page_end();exit;
}
if (!have_admin() && $page !== 'setup') redirect('setup');

// Externally visible record IDs are opaque. Numeric legacy links redirect only for an
// authenticated user or a visitor holding the original 48-character receipt secret.
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET' && in_array($page,['view','submitted','edit','print','photo','download_docx'],true) && isset($_GET['id'])){
    $requested=(string)$_GET['id'];
    if(ctype_digit($requested)){
        $q=$pdo->prepare('SELECT id,public_token,receipt_hash FROM volunteers WHERE id=?');$q->execute([(int)$requested]);$row=$q->fetch();
        $receipt=(string)($_GET['token']??'');
        $allowed=admin() || ($receipt!=='' && strlen($receipt)===48 && $row && !empty($row['receipt_hash']) && hash_equals((string)$row['receipt_hash'],hash('sha256',$receipt)));
        if(!$row||!$allowed){praan_error_page(404,'রেকর্ড পাওয়া যায়নি।');}
        $params=$_GET;$params['id']=$row['public_token'];
        header('Location: index.php?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),true,302);exit;
    }
    if(!preg_match('/^PRAAN-VOL-[0-9A-HJKMNP-TV-Z]{20}$/D',$requested)){praan_error_page(404,'রেকর্ড পাওয়া যায়নি।');}
    $q=$pdo->prepare('SELECT id FROM volunteers WHERE public_token=?');$q->execute([$requested]);
    $id=$q->fetchColumn();if(!$id){praan_error_page(404,'রেকর্ড পাওয়া যায়নি।');}
    $_GET['id']=(string)$id;
}


// System Doctor and read-only progress; both require update-management authorization.
if ($page === 'system_doctor' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    render_system_doctor($pdo);exit;
}
if ($page === 'system_doctor_export' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    require_admin();praan_require('updates.manage');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="PRAAN-System-Doctor-'.APP_VERSION.'.json"');
    header('Cache-Control: no-store, private');
    echo json_encode(praan_doctor($pdo),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if ($page === 'update_progress' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    require_admin();praan_require('updates.manage');
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
    echo json_encode(praan_update_progress_read(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
if ($page === 'security_center') {praan_security_center($pdo);exit;}
if ($page === 'privacy_desk') {praan_privacy_desk($pdo);exit;}
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && in_array($page,['privacy_create','privacy_resolve','security_cleanup'],true)) {
    praan_require('security.manage');verify_csrf();
    try {
        um_require_password((string)($_POST['password']??''));
        if($page==='security_cleanup'){
            $counts=praan_production_cleanup($pdo);
            log_action(null,'security_housekeeping',implode(', ',array_map(static fn($k,$v)=>$k.'='.$v,array_keys($counts),$counts)));
            flash('success','পুরোনো অস্থায়ী লগ/সেশন পরিষ্কার হয়েছে; স্বেচ্ছাসেবক ও Audit Log অক্ষত।');
        } else {
            praan_privacy_action($pdo,$page,$_POST);
            flash('success','Privacy Request সংরক্ষণ করা হয়েছে।');
        }
    }catch(Throwable $e){error_log('PRAAN privacy/security action: '.$e->getMessage());flash('danger','পরিবর্তন হয়নি: '.$e->getMessage());}
    redirect($page==='security_cleanup'?'security_center':'privacy_desk');
}
if ($page === 'offline_status') {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);exit('Method Not Allowed');}
    praan_require('volunteers.create');
    header('Cache-Control: no-store, private');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'csrf'=>csrf_token(),'actor_id'=>(int)admin_id(),'enabled'=>praan_enabled('offline_queue_enabled'),'version'=>APP_VERSION],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    exit;
}
if (in_array($page,['media_init','media_status','media_chunk'],true)) media_api($page,$pdo);
if($_SERVER['REQUEST_METHOD']==='POST' && in_array($page,['module_state','module_options','module_flag'],true)){
    try{praan_module_action($page);}catch(Throwable $e){
        error_log('PRAAN Module Manager '.$page.': '.$e->getMessage());
        flash('danger',$e->getMessage());
        $selected=(string)($_POST['module_id']??'reports');
        redirect('modules',['module'=>$selected]);
    }
}

if ($page === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (have_admin()) redirect('login');
    $name = trim((string)($_POST['display_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['password_confirmation'] ?? '');
    if (!hash_equals($password,$confirmation)) { flash('danger','পাসওয়ার্ড ও নিশ্চিতকরণ মিলছে না।'); redirect('setup'); }
    if (strlen($username) < 4 || strlen($username) > 60 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username) || strlen($password) < 12 || strlen($name) < 2) {
        flash('danger','নাম, ৪+ অক্ষরের ইউজারনেম এবং ১২+ অক্ষরের শক্তিশালী পাসওয়ার্ড দিন।'); redirect('setup');
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users(uid,username,display_name,password_hash,status) VALUES(?,?,?,?,?)')->execute([praan_uid('USR'),$username,mb_safe_limit($name,100),password_hash($password,PASSWORD_DEFAULT),'active']);
        $newAdminId=(int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE users SET display_code=? WHERE id=?')->execute([praan_next_user_code($pdo),$newAdminId]);
        $rid=(int)$pdo->query("SELECT id FROM roles WHERE slug='super_admin'")->fetchColumn();
        $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)')->execute([$newAdminId,$rid]);
        $pdo->commit();
        session_regenerate_id(true); $_SESSION['admin_id'] = $newAdminId; $_SESSION['admin_name'] = $name;
        praan_session_begin($newAdminId);praan_login_event($newAdminId,$username,'success');
        flash('success','Super Admin অ্যাকাউন্ট তৈরি হয়েছে।'); redirect('dashboard');
    } catch(PDOException $e) { flash('danger','এই ইউজারনেম ব্যবহার করা যাচ্ছে না।'); redirect('setup'); }
}
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!ip_throttle('login',15,900)) { flash('danger','অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।'); redirect('login'); }
    $loginUsername=trim((string)($_POST['username']??''));
    if(praan_login_username_locked($pdo,$loginUsername)){flash('danger','অনেকবার ভুল পাসওয়ার্ড দেওয়া হয়েছে। ১৫ মিনিট পরে চেষ্টা করুন।');redirect('login');}
    $q=$pdo->prepare('SELECT * FROM users WHERE username=?'); $q->execute([trim((string)($_POST['username'] ?? ''))]); $user=$q->fetch();
    if ($user && $user['status']==='active' && $user['deleted_at']===null && password_verify((string)($_POST['password'] ?? ''),(string)$user['password_hash'])) {
        session_regenerate_id(true); $_SESSION['admin_id'] = (int)$user['id']; $_SESSION['admin_name'] = $user['display_name'];
        $_SESSION['admin_last_active']=time();unset($_SESSION['csrf']);
        praan_session_begin((int)$user['id']);
        $pdo->prepare('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
        praan_login_event((int)$user['id'],(string)$user['username'],'success');
        redirect('dashboard');
    }
    praan_login_event($user?(int)$user['id']:null,(string)($_POST['username']??''),'failure');
    flash('danger','ইউজারনেম অথবা পাসওয়ার্ড সঠিক নয় কিংবা অ্যাকাউন্টটি নিষ্ক্রিয়।'); redirect('login');
}
if ($page === 'logout') {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();praan_session_end();$_SESSION=[];session_regenerate_id(true);redirect('home');
    }
    if(!admin())redirect('login');
    page_start('লগআউট নিশ্চিত করুন','profile',true);
    echo '<div class="card dashboard-card"><div class="card-body"><h3>সেশন বন্ধ করতে চান?</h3><form method="post" action="'.e(url('logout')).'">'.csrf_field().'<button class="btn btn-danger">লগআউট</button> <a class="btn btn-outline-secondary" href="'.e(url('dashboard')).'">বাতিল</a></form></div></div>';
    page_end(true);exit;
}
// Every administrative identity mutation requires both CSRF and current-password confirmation.
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($page,['user_save','user_state','user_password','role_save','role_delete','session_revoke','my_session_revoke','profile_save','profile_password'],true)) {
    try {
        match($page) {
            'user_save'=>praan_user_save_post(),
            'user_state'=>praan_user_state_post(),
            'user_password'=>praan_reset_user_password(),
            'role_save'=>praan_role_save_post(),
            'role_delete'=>praan_role_delete_post(),
            'session_revoke'=>praan_session_revoke_post(true),
            'my_session_revoke'=>praan_session_revoke_post(false),
            'profile_save'=>praan_profile_save_post(),
            'profile_password'=>praan_profile_password_post(),
        };
    } catch (Throwable $e) {
        error_log('PRAAN identity action '. $page .': '. $e->getMessage());
        flash('danger',$e instanceof PDOException?'ইউজারনেম, রোল বা তথ্যের মিল পাওয়া যায়নি।':$e->getMessage());
    }
    redirect(match($page){'role_save','role_delete'=>'roles','session_revoke'=>'sessions','my_session_revoke'=>'profile','profile_save','profile_password'=>'profile',default=>'users'});
}
function praan_save_json(array $payload,int $status=200): never {media_json($payload,$status);}
if ($page === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax=(string)($_SERVER['HTTP_X_PRAAN_AJAX']??'')==='1';
    if(!admin()&&!praan_enabled('public_registration_enabled')){http_response_code(403);exit('পাবলিক নিবন্ধন বন্ধ রয়েছে।');}
    verify_csrf();
    $isAdmin = admin() && (string)($_POST['entry_type'] ?? 'admin') !== 'public';
    if (!$isAdmin && praan_enabled('public_honeypot_enabled') && (trim((string)($_POST['website_url']??''))!=='' || !isset($_SESSION['praan_public_form_opened_at']) || time()-(int)$_SESSION['praan_public_form_opened_at']<2)) {
        if($ajax)praan_save_json(['ok'=>false,'message'=>'ফর্মটি আবার খুলে চেষ্টা করুন।'],422);
        http_response_code(422);exit('ফর্মটি আবার খুলে চেষ্টা করুন।');
    }
    if (!$isAdmin && !ip_throttle('register',10,3600)) { if($ajax)praan_save_json(['ok'=>false,'message'=>'অতিরিক্ত নিবন্ধন হয়েছে। পরে চেষ্টা করুন।'],429);flash('danger','এই সংযোগ থেকে অতিরিক্ত ফর্ম জমা হয়েছে। পরে চেষ্টা করুন।'); redirect('register'); }
    $id = (int)($_POST['id'] ?? 0);
    $old = $id ? fetch_volunteer($id) : null;
    if ($id && (!$old || !$isAdmin)) { if($ajax)praan_save_json(['ok'=>false,'message'=>'রেকর্ড সম্পাদনার অনুমতি নেই।'],403); http_response_code(403); exit('এই রেকর্ড সম্পাদনার অনুমতি নেই।'); }
    $raw = $_POST['data'] ?? [];
    if (!is_array($raw)) $raw = [];
    $data = sanitize_form_data($raw,$isAdmin,$old['data']??[]);
    // Editors cannot silently promote a record unless granted verification permission.
    if ($isAdmin && !praan_can('volunteers.approve')) {
        $data['status']=$old ? (string)$old['status'] : 'অপেক্ষমাণ';
    }
    $draftUid=(string)($_POST['draft_uid']??'');
    if(praan_uid_valid($draftUid,'DRF'))$data['draft_uid']=$draftUid;
    // Durable idempotency for encrypted offline queue replay. Scope the draft to its creator.
    // The database already has a unique index on volunteers.draft_uid.
    if ($ajax && $isAdmin && !$old && praan_uid_valid($draftUid,'DRF')) {
        $existingStmt=$pdo->prepare('SELECT id FROM volunteers WHERE draft_uid=? AND created_by=? AND deleted_at IS NULL LIMIT 1');
        $existingStmt->execute([$draftUid,(int)admin_id()]);
        $existingId=(int)($existingStmt->fetchColumn() ?: 0);
        if($existingId){
            praan_save_json(['ok'=>true,'already_saved'=>true,'message'=>'এই ড্রাফট আগে সংরক্ষিত হয়েছে। নতুন রেকর্ড তৈরি হয়নি।','volunteer_id'=>$existingId,'redirect_url'=>url('view',['id'=>$existingId])]);
        }
    }
    if($ajax && !$old && praan_uid_valid($draftUid,'DRF')){
        $prev=$_SESSION['praan_saved_drafts'][$draftUid]??null;
        if(is_array($prev) && time()-(int)($prev['time']??0)<3600 && (bool)($prev['admin']??false)===$isAdmin){praan_save_json($prev['response']);}
    }
    if($old && isset($old['data']['_source_google']) && is_array($old['data']['_source_google'])) $data['_source_google']=$old['data']['_source_google'];
    if (trim((string)$data['name_bn']) === '' && trim((string)$data['name_en']) === '') {
        $_SESSION['form_draft'] = $data;
        if($ajax)praan_save_json(['ok'=>false,'message'=>'স্বেচ্ছাসেবকের বাংলা বা ইংরেজি নাম অন্তত একটি দিতে হবে।'],422);
        flash('danger','স্বেচ্ছাসেবকের বাংলা বা ইংরেজি নাম অন্তত একটি দিতে হবে।'); redirect($isAdmin ? ($id?'edit':'new') : 'register',$id?['id'=>$id]:[]);
    }
    if (!$isAdmin) {
        if (trim((string)$data['phone']) === '') { $_SESSION['form_draft']=$data; if($ajax)praan_save_json(['ok'=>false,'message'=>'মোবাইল নম্বর প্রয়োজন।'],422);flash('danger','নিবন্ধনের জন্য মোবাইল নম্বর প্রয়োজন।'); redirect('register'); }
        foreach(['code','safeguarding','confidentiality','data','emergency'] as $code) {
            if(!in_array($code,$data['consents'],true)) { $_SESSION['form_draft']=$data; if($ajax)praan_save_json(['ok'=>false,'message'=>'প্রথম পাঁচটি আচরণবিধি/তথ্য ব্যবহারের সম্মতি প্রয়োজন।'],422);flash('danger','নিবন্ধনের জন্য প্রথম পাঁচটি আচরণবিধি/তথ্য ব্যবহারের সম্মতি দিতে হবে। প্রচারমাধ্যমের সম্মতি ঐচ্ছিক।'); redirect('register'); }
        }
        $data['status']='অপেক্ষমাণ';
    }
    if ($data['registration_date'] === '') $data['registration_date'] = date('Y-m-d');
    if ($data['status'] === '') $data['status'] = 'অপেক্ষমাণ';
    $source = $old ? (string)$old['source_type'] : ($isAdmin ? (!empty($_POST['quick_mode'])?'paper':'admin') : 'public');
    if ($isAdmin && $id===0 && (string)($_POST['source_type']??'') === 'admin') $source = 'admin';
    $publicVisible = !empty($_POST['public_visible']) ? 1 : 0;
    // An operator may publish a profile only after explicitly confirming separate consent.
    if($isAdmin && $publicVisible===1 && (int)($old['public_visible']??0)!==1 && empty($_POST['confirm_public_consent'])){
        if($ajax)praan_save_json(['ok'=>false,'message'=>'পাবলিক তালিকায় প্রকাশের পৃথক সম্মতি যাচাই করুন।'],422);
        flash('danger','পাবলিক তালিকায় প্রকাশের পৃথক সম্মতি নিশ্চিত করুন।');
        $_SESSION['form_draft']=$data;
        redirect($id?'edit':'new',$id?['id'=>$id]:[]);
    }
    // Names can repeat. Exact identity documents block duplicates; shared mobiles prompt review.
    if(!$old){
        $evidence=praan_duplicate_evidence($pdo,$data);
        if($evidence['document']){
            $message='এই NID/জন্মনিবন্ধন নম্বরে নিবন্ধন আগে থেকেই আছে।';
            if($ajax)praan_save_json(['ok'=>false,'message'=>$message],409);
            $_SESSION['form_draft']=$data;flash('danger',$message);redirect($isAdmin?'new':'register');
        }
        if($evidence['phone'] && $isAdmin && empty($_POST['confirm_shared_phone'])){
            $message='এই মোবাইল নম্বর অন্য রেকর্ডে আছে। পরিবারের শেয়ার করা নম্বর হলে Confirm করুন।';
            if($ajax)praan_save_json(['ok'=>false,'message'=>$message,'possible_duplicate'=>true],409);
            $_SESSION['form_draft']=$data;flash('warning',$message);redirect('new');
        }
    }
    if(!$old&&!empty($evidence['phone']))log_action(null,'shared_phone_review','Matched phone on another volunteer record');
    $photo = (string)($old['photo_file']??'');
    try {
        $previousPhoto=$photo;
        if($isAdmin && !empty($_POST['remove_photo'])) $photo='';
        $photo=store_photo($_FILES['photo']??null,$photo);
        $createdPhoto=($photo!==$previousPhoto)?$photo:'';
        // Keep the previous image until the database has successfully committed the change.
        $receipt = !$isAdmin && !$old ? bin2hex(random_bytes(24)) : '';
        $saved = save_volunteer($data,$old,$source,$publicVisible,$photo,$receipt ? hash('sha256',$receipt) : '');
        if($photo!==$previousPhoto && $previousPhoto && is_file(PHOTO_DIR.'/'.$previousPhoto)) @unlink(PHOTO_DIR.'/'.$previousPhoto);
        if(praan_uid_valid($draftUid,'DRF')){try{media_attach_ready($pdo,$draftUid,$saved);}catch(Throwable $mediaError){error_log('PRAAN image attach after save: '.$mediaError->getMessage());}}
        unset($_SESSION['form_draft']);
        if($ajax){
            $target=$isAdmin?url('view',['id'=>$saved]):url('submitted',['id'=>$saved,'token'=>$receipt]);
            $response=['ok'=>true,'message'=>'নিবন্ধন তথ্য সংরক্ষিত হয়েছে। ছবি চলমান থাকলে পরে সংযুক্ত হবে।','volunteer_id'=>$saved,'redirect_url'=>$target];
            if(!$old && praan_uid_valid($draftUid,'DRF')) $_SESSION['praan_saved_drafts'][$draftUid]=['time'=>time(),'admin'=>$isAdmin,'response'=>$response];
            praan_save_json($response);
        }
        if ($isAdmin) {
            flash('success',$old?'তথ্য সফলভাবে আপডেট হয়েছে।':'নতুন রেকর্ড সংরক্ষণ হয়েছে।');
            if(!$old && !empty($_POST['quick_mode'])) redirect('quick');
            redirect('view',['id'=>$saved]);
        }
        redirect('submitted',['id'=>$saved,'token'=>$receipt]);
    } catch(Throwable $e) {
        error_log('PRAAN Save: '.$e->getMessage());
        $_SESSION['form_draft'] = $data;
        if($ajax)praan_save_json(['ok'=>false,'message'=>'তথ্য সংরক্ষণ করা যায়নি। ফাইল ও দেওয়া তথ্য পরীক্ষা করুন।'],422);
        flash('danger','তথ্য সংরক্ষণ করা যায়নি। ফাইল ও দেওয়া তথ্য পরীক্ষা করুন।');
        redirect($isAdmin ? ($id?'edit':'new') : 'register',$id?['id'=>$id]:[]);
    }
}
if ($page === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    $id=(int)($_POST['id']??0);
    $v=fetch_volunteer($id);
    if($v) {
        $payload=$v['data']; $payload['status']='সক্রিয়';
        $pdo->prepare('UPDATE volunteers SET status=?,payload=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute(['সক্রিয়',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),$id]);
        log_action($id,'approved','Status changed to active');
        flash('success','স্বেচ্ছাসেবককে সক্রিয় করা হয়েছে।');
    }
    redirect('volunteers');
}
if ($page === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    $id=(int)($_POST['id']??0);
    if($id && fetch_volunteer($id)) {
        $pdo->prepare('UPDATE volunteers SET deleted_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
        log_action($id,'archived'); flash('success','রেকর্ড আর্কাইভ করা হয়েছে; ডাটাবেইস থেকে স্থায়ীভাবে মুছে দেওয়া হয়নি।');
    }
    redirect('volunteers');
}
if ($page === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    $id=(int)($_POST['id']??0);
    $q=$pdo->prepare('SELECT id FROM volunteers WHERE id=? AND deleted_at IS NOT NULL');$q->execute([$id]);
    if($q->fetchColumn()) {
        $pdo->prepare('UPDATE volunteers SET deleted_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
        log_action($id,'restored'); flash('success','আর্কাইভ থেকে রেকর্ড ফিরিয়ে আনা হয়েছে।');
    }
    redirect('archived');
}
// Old settings route retained only as a safe redirect; the Control Center requires password re-check.
if($page==='settings_save' && $_SERVER['REQUEST_METHOD']==='POST'){require_admin();verify_csrf();flash('warning','পুরোনো সেটিংস ফর্ম বন্ধ। নতুন Control Center ব্যবহার করুন।');redirect('settings');}
if($page==='settings_save_pro' && $_SERVER['REQUEST_METHOD']==='POST') {
    require_admin();verify_csrf();
    try{um_require_password((string)($_POST['admin_password']??''));$count=praan_save_settings((array)($_POST['settings']??[]));flash('success',$count.'টি সেটিংস আপডেট হয়েছে।');}
    catch(Throwable $e){flash('danger','সেটিংস পরিবর্তন হয়নি: '.$e->getMessage());}
    redirect('settings');
}
if($page==='background_asset') {
    $file=praan_background_file();if(!is_file($file)){http_response_code(404);exit;}
    header('Content-Type: image/jpeg');header('Cache-Control: public, max-age=120');header('X-Content-Type-Options: nosniff');readfile($file);exit;
}
if(in_array($page,['background_upload','background_delete'],true) && $_SERVER['REQUEST_METHOD']==='POST') {
    require_admin();header('Content-Type: application/json; charset=utf-8');
    try {
        verify_csrf();
        if($page==='background_upload')$data=praan_background_upload($_FILES['image']??[]);
        else {if(is_file(praan_background_file()))@unlink(praan_background_file());log_action(null,'site_background_deleted');$data=['ok'=>true,'message'=>'ব্যাকগ্রাউন্ড সরানো হয়েছে।'];}
        echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
    exit;
}
if($page==='download_docx') {
    require_admin();
    if(!praan_enabled('docx_export_enabled')){http_response_code(403);exit('DOCX বন্ধ আছে।');}
    $v=fetch_volunteer((int)($_GET['id']??0));if(!$v){http_response_code(404);exit('রেকর্ড পাওয়া যায়নি');}
    try{$binary=praan_docx_binary($v);}catch(Throwable $e){error_log('PRAAN docx: '.$e->getMessage());http_response_code(500);exit('DOCX তৈরির জন্য PHP ZipArchive চালু করুন।');}
    log_action((int)$v['id'],'docx_download');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="PRAAN_Volunteer_'.preg_replace('/[^A-Za-z0-9-]/','',(string)$v['volunteer_code']).'.docx"');
    header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($binary));echo $binary;exit;
}
if ($page === 'update_key_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); praan_require('updates.manage'); if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');} verify_csrf();
    try { um_require_password((string)($_POST['password']??'')); um_install_key((string)($_POST['public_key']??'')); flash('success','Trusted Ed25519 Public Key সংরক্ষিত।'); }
    catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('updates');
}
if ($page === 'update_maintenance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); praan_require('updates.manage'); if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');} verify_csrf();
    try {um_require_password((string)($_POST['password']??'')); um_lock(function(){um_set_maintenance((string)($_POST['enabled']??'')==='1','অ্যাডমিন পরিচালিত Maintenance Mode');});flash('success','Maintenance Mode পরিবর্তন হয়েছে।');}
    catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('updates');
}
if ($page === 'update_backup_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    try {$result=um_lock(fn()=>um_create_backup($pdo,'manual'));flash('success','Verified Backup প্রস্তুত: '.$result['name']);}
    catch(Throwable $e){flash('danger','ব্যাকআপ ব্যর্থ: '.$e->getMessage());}
    redirect('updates');
}
if ($page === 'update_backup_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    try {$result=um_verify_backup(um_backup_path((string)($_POST['name']??'')));flash($result['ok']?'success':'danger',$result['ok']?'Backup অক্ষত: SHA-256 এবং SQLite integrity যাচাই সফল।':'Backup যাচাই ব্যর্থ: '.$result['error']);um_audit('backup_check',($_POST['name']??'').': '.($result['ok']?'verified':'invalid'));}
    catch(Throwable $e){flash('danger',$e->getMessage());}
    redirect('updates');
}
if ($page === 'update_backup_download') {
    require_admin();
    log_action(null,'full_backup_download','private update backup');
    try{$path=um_backup_path((string)($_GET['name']??''));
        header('Content-Type: application/zip');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store, private');
        header('Content-Disposition: attachment; filename="'.basename($path).'"');header('Content-Length: '.filesize($path));readfile($path);exit;
    }catch(Throwable $e){http_response_code(404);exit('Backup ফাইল পাওয়া যায়নি।');}
}
if ($page === 'update_install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();praan_require('updates.manage');
    if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');}
    verify_csrf();
    $async=(string)($_POST['async_update']??'')==='1';
    $error=null;$result=null;
    try{
        um_require_password((string)($_POST['password']??''));
        if(($_POST['confirmation']??'')!=='UPDATE')throw new RuntimeException('UPDATE লিখে নিশ্চিত করুন।');
        // Release the PHP session lock so progress polling can run concurrently.
        if($async && session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $result=um_lock(fn()=>um_install_latest($pdo));
        if(!$async)flash('success','Signed v'.$result['version'].' ইনস্টল হয়েছে। Backup: '.$result['backup']);
    }catch(Throwable $e){
        error_log('PRAAN update install: '.$e->getMessage());$error=$e->getMessage();
        if(!$async)flash('danger','ইনস্টল হয়নি: '.$error);
    }
    if($async){
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
        if($error!==null)http_response_code(422);
        echo json_encode(['ok'=>$error===null,'message'=>$error??'Signed Update সফল; নতুন সংস্করণ Reload করুন।','result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    redirect('updates');
}
if ($page === 'update_rollback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); praan_require('updates.manage'); if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');} verify_csrf();
    try{
        um_require_password((string)($_POST['password']??''));
        if(($_POST['confirmation']??'')!=='ROLLBACK')throw new RuntimeException('ROLLBACK লিখুন।');
        if(empty(um_maintenance()['enabled']))throw new RuntimeException('আগে Maintenance Mode চালু করতে হবে।');
        $name=(string)($_POST['name']??'');
        $result=um_lock(function()use($name){
            $path=um_backup_path($name);$restored=um_restore_code($path);
            $journal=praan_tx_read();
            if(($journal['backup']??'')===$name && in_array((string)($journal['state']??''),['installed','installing','rollback_failed'],true)){
                praan_update_clean_new_files((array)($journal['new_files']??[]));
                $journal['state']='rolled_back';praan_tx_write($journal);
            }
            return $restored;
        });
        um_audit('code_rollback',$name.' '.$result);flash('success','আগের কোড ফিরেছে। SQLite এবং ছবি পরিবর্তন হয়নি। এখন Maintenance বন্ধ করুন।');
    }catch(Throwable $e){flash('danger','Rollback ব্যর্থ: '.$e->getMessage());}
    redirect('updates');
}
if ($page === 'update_migrate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();praan_require('updates.manage');
    if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');}
    verify_csrf();
    try{
        um_require_password((string)($_POST['password']??''));
        $result=um_lock(function()use($pdo){
            $already=!empty(um_maintenance()['enabled']);
            if(!$already)um_set_maintenance(true,'Signed-safe migration');
            try{
                $snapshot=um_create_backup($pdo,'before-manual-migration');
                $applied=praan_migration_apply($pdo);
                um_audit('manual_migration','backup '.$snapshot['name'].' · applied '.count($applied['applied']));
                return ['snapshot'=>$snapshot['name'],'applied'=>$applied['applied']];
            }finally{if(!$already)um_set_maintenance(false,'Migration complete / cancelled');}
        });
        flash('success','Migration সফল: '.count($result['applied']).'টি নতুন; Backup: '.$result['snapshot']);
    }catch(Throwable $e){flash('danger','Migration চালানো যায়নি: '.$e->getMessage());}
    redirect('updates');
}
if ($page === 'update_recover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();praan_require('updates.manage');
    if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');}
    verify_csrf();
    try{
        um_require_password((string)($_POST['password']??''));
        if(($_POST['confirmation']??'')!=='RECOVER')throw new RuntimeException('RECOVER লিখে নিশ্চিত করুন।');
        $result=um_lock(function(){
            $journal=praan_tx_read();
            if(!in_array((string)($journal['state']??''),['installing','rollback_failed','migration_failed'],true))throw new RuntimeException('অসমাপ্ত Update Transaction পাওয়া যায়নি।');
            $name=(string)($journal['backup']??'');
            $path=um_backup_path($name);
            $check=um_verify_backup($path);
            if(empty($check['ok']))throw new RuntimeException('Verified Backup পাওয়া যায়নি।');
            um_set_maintenance(true,'Interrupted update recovery');
            $restored=um_restore_code($path);
            praan_update_clean_new_files((array)($journal['new_files']??[]));
            $journal['state']='rolled_back';praan_tx_write($journal);
            um_audit('interrupted_recovery',$name.' '.$restored);
            um_set_maintenance(false,'Recovery complete');
            praan_update_progress('recovery','আগের কোড সফলভাবে ফিরেছে',100,'success');
            return $restored;
        });
        flash('success','Recovery সম্পন্ন: '.$result.'. SQLite ও ছবি পরিবর্তন হয়নি।');
    }catch(Throwable $e){flash('danger','Recovery ব্যর্থ: '.$e->getMessage().'। প্রয়োজনে cPanel restore করুন।');}
    redirect('updates');
}
if ($page === 'update_repo_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); praan_require('updates.manage'); if(!praan_is_super()){http_response_code(403);exit('Super Admin প্রয়োজন।');} verify_csrf();
    $repo = trim((string)($_POST['github_repo'] ?? ''));
    if ($repo !== '' && !valid_github_repository($repo)) {
        flash('danger','GitHub Repository owner/repository আকারে লিখুন; শুধু public repository সমর্থিত।');
    } else {
        set_setting('github_repo', $repo);
        @unlink(UM_STORAGE.'/update-cache.json');
        log_action(null, 'update_repository_settings', $repo === '' ? 'Disconnected public GitHub release source' : 'Configured public GitHub release source');
        flash('success',$repo === '' ? 'GitHub Update Check বন্ধ করা হয়েছে।' : 'GitHub Release উৎস সংরক্ষিত হয়েছে।');
    }
    redirect('updates');
}
if ($page === 'import_preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    $file=$_FILES['file']??null;
    try {
        if(!$file || (int)($file['error']??1)!==UPLOAD_ERR_OK || (int)($file['size']??0)>(int)praan_module_option('import','max_file_mb')*1024*1024 || !is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('নির্ধারিত আকারের বৈধ Excel/CSV ফাইল দিন।');
        $extension=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
        if(!in_array($extension,['xlsx','csv','txt'],true)) throw new RuntimeException('শুধু .xlsx এবং .csv ফাইল গ্রহণ করা হয়।');
        if(!in_array($extension,['xlsx'],true))$extension='csv';
        [$headers,$rows,$mode]=import_sheet_file((string)$file['tmp_name'],$extension);
        // Never leave unvalidated uploads inside the public webroot.
        $folder=__DIR__.'/storage/import_previews';
        if(!is_dir($folder) && !mkdir($folder,0750,true))throw new RuntimeException('Private storage permission নেই।');
        $name=bin2hex(random_bytes(16)).'.'.$extension;
        $path=$folder.'/'.$name;
        if(!move_uploaded_file((string)$file['tmp_name'],$path))throw new RuntimeException('অস্থায়ী ফাইল রাখা যায়নি।');
        @chmod($path,0600);
        if(!empty($_SESSION['import_pending']['path']))@unlink((string)$_SESSION['import_pending']['path']);
        $existingMode=(string)($_POST['existing_mode']??'update');
        if(!in_array($existingMode,['update','skip'],true))throw new RuntimeException('Import করার পদ্ধতি সঠিক নয়।');
        $stats=import_process($pdo,$headers,$rows,$mode,true,$existingMode);
        $_SESSION['import_pending']=['path'=>$path,'extension'=>$extension,'time'=>time(),'stats'=>$stats,'format'=>$mode,'existing_mode'=>$existingMode,'digest'=>hash_file('sha256',$path)];
        flash('info','Import Preview প্রস্তুত। যাচাই করে নিশ্চিত করুন; এখনো ডাটাবেইসে কিছু যোগ হয়নি।');
    } catch(Throwable $e) {
        if(!empty($path) && is_file($path))@unlink($path);
        error_log('PRAAN Import preview: '.$e->getMessage());
        flash('danger','Import Preview ব্যর্থ: '.$e->getMessage());
    }
    redirect('import');
}
if ($page === 'import_commit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); verify_csrf();
    $pending=$_SESSION['import_pending']??null;
    try {
        if(!$pending || !is_array($pending) || time()-(int)($pending['time']??0)>1200 || !str_starts_with((string)$pending['path'],__DIR__.'/storage/import_previews/'))throw new RuntimeException('Import Preview-এর সময় শেষ। ফাইলটি আবার দিন।');
        $file=(string)$pending['path'];
        if(!is_file($file))throw new RuntimeException('ফাইল পাওয়া যায়নি।');
        if(!hash_equals((string)($pending['digest']??''),(string)hash_file('sha256',$file)))throw new RuntimeException('Import ফাইল Preview-এর পর বদলেছে; পুনরায় Preview করুন।');
        [$headers,$rows,$mode]=import_sheet_file($file,(string)$pending['extension']);
        $existingMode=(string)($pending['existing_mode']??'update');
        $recheck=import_process($pdo,$headers,$rows,$mode,true,$existingMode);
        foreach(['total','added','updated','skipped','review','invalid'] as $key){
            if((int)($pending['stats'][$key]??-1)!==(int)($recheck[$key]??-2))throw new RuntimeException('Preview-এর পর ডাটাবেইসে পরিবর্তন হয়েছে; নতুন করে Preview করুন।');
        }
        $stats=import_process($pdo,$headers,$rows,$mode,false,$existingMode);
        @unlink($file);unset($_SESSION['import_pending']);
        $_SESSION['praan_import_last']=['stats'=>$stats,'at'=>time()];
        flash('success','Import সম্পন্ন: নতুন '.$stats['added'].'টি · আপডেট '.$stats['updated'].'টি · বাদ '.$stats['skipped'].'টি · যাচাই প্রয়োজন '.$stats['review'].'টি · অসম্পূর্ণ '.$stats['invalid'].'টি।');
        redirect('import');
    } catch(Throwable $e) {
        error_log('PRAAN Import commit: '.$e->getMessage());
        flash('danger','ইমপোর্ট করা যায়নি: '.$e->getMessage());redirect('import');
    }
}
if ($page === 'import_cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();verify_csrf();
    if(!empty($_SESSION['import_pending']['path']) && str_starts_with((string)$_SESSION['import_pending']['path'],__DIR__.'/storage/import_previews/'))@unlink((string)$_SESSION['import_pending']['path']);
    unset($_SESSION['import_pending']);flash('info','Import বাতিল করা হয়েছে।');redirect('import');
}
if ($page === 'bulk_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();verify_csrf();
    $action=(string)($_POST['action_type']??'');
    $ids=is_array($_POST['ids']??null)?$_POST['ids']:[];
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($i)=>$i>0)));
    if((string)($_POST['confirmed']??'')!=='1') {flash('danger','অ্যাকশন নিশ্চিত হয়নি।');redirect('volunteers');}
    try {
        if($action==='export_xlsx'||$action==='export_csv'){log_action(null,'bulk_export_download','format='.substr($action,7).', count='.count($ids));table_selected_export($ids,substr($action,7));}
        $result=table_action($pdo,$action,$ids);
        flash('success',$result['count'].'টি রেকর্ডে কাজ সম্পন্ন।'.($action==='archive'?' Soft Delete হয়েছে, Restore করা যাবে।':''));
    } catch(Throwable $e) {flash('danger','অ্যাকশন ব্যর্থ: '.$e->getMessage());}
    $query=[];parse_str((string)($_POST['return_query']??''),$query);
    $forward=[];foreach(['q','status','district','gender','source_type','project','category','date_from','date_to','per','p','sort','dir'] as $key) if(isset($query[$key])&&is_scalar($query[$key]))$forward[$key]=mb_safe_limit((string)$query[$key],120);
    redirect('volunteers',$forward);
}
if ($page === 'backup') {
    require_admin();
    log_action(null,'database_backup_download','sqlite online backup');
    $tmp = tempnam(sys_get_temp_dir(), 'pran_');
    if ($tmp === false) { http_response_code(500); exit('ব্যাকআপের জন্য টেম্প ফাইল তৈরি করা যায়নি।'); }
    @unlink($tmp);
    try {
        $safePath=str_replace("'", "''", $tmp);
        $pdo->exec("VACUUM INTO '$safePath'");
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="praan_backup_'.date('Y-m-d_H-i').'.sqlite"');
        header('Content-Length: '.filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp); @unlink($tmp); exit;
    } catch(Throwable $e) {
        @unlink($tmp); error_log('PRAAN Backup: '.$e->getMessage());
        http_response_code(500); exit('ব্যাকআপ তৈরি হয়নি; সার্ভারের SQLite অনুমতি পরীক্ষা করুন।');
    }
}
if ($page === 'export') {
    require_admin();
    log_action(null,'volunteer_export_download','format='.substr((string)($_GET['format']??''),0,8));
    if ((string)($_GET['format']??'')==='xlsx') export_xlsx();
    if ((string)($_GET['format']??'')==='csv') export_csv();
}
if ($page === 'report_download') {
    require_admin();
    $format=(string)($_GET['format']??'');$mode=(string)($_GET['mode']??'summary');
    if (!in_array($format,['xlsx','csv'],true) || !in_array($mode,['summary','detail'],true)) {
        http_response_code(400); exit('রিপোর্টের ধরন সঠিক নয়।');
    }
    $filters=analytics_filters($_GET);
    log_action(null,'report_export',$mode.' '.$format);
    if ($mode==='summary') report_summary_download($format,$filters);
    if ($format==='xlsx') export_xlsx($filters);
    export_csv($filters);
}
if ($page === 'report_print') { render_report_print_v110($pdo); exit; }
if($page==='template') { require_admin();template_csv(); }
if($page==='template_full') {
    require_admin();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="PRAAN_Complete_Import_Export_Template.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_values(export_headers()));fclose($out);exit;
}
if($page==='template_google') {
    require_admin();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="PRAAN_Google_Form_54_Columns.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,google_form_headers());fclose($out);exit;
}
if($page==='form_export') {
    require_admin();$format=(string)($_GET['format']??'xlsx');if(!in_array($format,['xlsx','csv'],true)) {http_response_code(400);exit('ভুল ফরম্যাট');}
    $filters=analytics_filters($_GET);log_action(null,'google_form_export',$format);
    $rows=google_export_rows($filters);
    header('Cache-Control: no-store');
    if($format==='xlsx'){$binary=xlsx_binary(google_form_headers(),$rows);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="praan_google_form_'.date('Y-m-d').'.xlsx"');header('Content-Length: '.strlen($binary));echo $binary;exit;}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="praan_google_form_'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,google_form_headers());foreach($rows as $row)fputcsv($out,array_map(fn($v)=>preg_match('/^[\s]*[=+\-@]/u',(string)$v)?"'".$v:$v,$row));fclose($out);exit;
}
if ($page === 'photo') {
    $v=fetch_volunteer((int)($_GET['id']??0));
    if(!$v || !can_view_volunteer($v) || !$v['photo_file']) {http_response_code(404); exit('ছবি নেই');}
    $file=PHOTO_DIR.'/'.basename((string)$v['photo_file']);
    if(!is_file($file)) {http_response_code(404);exit('ছবি নেই');}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
    if(!in_array($mime,['image/png','image/jpeg','image/webp'],true)) {http_response_code(403);exit('অবৈধ ফাইল');}
    header('Content-Type: '.$mime);header('Cache-Control: private, max-age=120');header('Content-Length: '.filesize($file));readfile($file);exit;
}
if ($page === 'print') {
    if(admin())log_action((int)($_GET['id']??0),'form_print_access');
    $v=fetch_volunteer((int)($_GET['id']??0));
    if(!$v || !can_view_volunteer($v)) {http_response_code(403);exit('অনুমতি নেই বা লিংক সঠিক নয়।');}
    printable_record($v);exit;
}
if ($page === 'view' || $page === 'submitted') {
    $v=fetch_volunteer((int)($_GET['id']??0));
    if(!$v || !can_view_volunteer($v)) {http_response_code(403);exit('অনুমতি নেই বা রসিদ লিংক ভুল।');}
    if ($page === 'submitted') {
        page_start('নিবন্ধন সম্পন্ন'); ?>
        <div class="receipt-wrap"><div class="receipt-icon"><i class="bi bi-check-lg"></i></div><h1>ধন্যবাদ! আপনার নিবন্ধন জমা হয়েছে</h1><p>আপনার তথ্য যাচাইয়ের অপেক্ষায় আছে। নিচের আইডি ও রসিদের লিংকটি সংরক্ষণ করুন।</p><div class="receipt-code"><?=e($v['volunteer_code'])?></div><div class="d-flex flex-wrap gap-2 justify-content-center"><a class="btn btn-brand" href="<?=e(url('view',['id'=>$v['id'],'token'=>(string)($_GET['token']??'')]))?>"><i class="bi bi-file-earmark-text"></i> নিজের তথ্য দেখুন</a><a class="btn btn-outline-brand" target="_blank" href="<?=e(url('print',['id'=>$v['id'],'token'=>(string)($_GET['token']??'')]))?>"><i class="bi bi-printer"></i> A4 ফর্ম প্রিন্ট</a></div><p class="small mt-4">এই লিংক যার কাছে থাকবে সে আপনার ফর্ম দেখতে পারবে। অন্যের সঙ্গে শেয়ার করবেন না।</p></div>
        <?php page_end();exit;
    }
    page_start('স্বেচ্ছাসেবক প্রোফাইল','volunteers',admin()); ?>
    <div class="record-container"><?php display_record($v); if(admin()): ?><div class="record-admin-note"><span>উৎস: <?=e($v['source_type'])?> · তৈরি: <?=e($v['created_at'])?></span><form method="post" action="<?=e(url('delete'))?>" onsubmit="return confirm('রেকর্ডটি আর্কাইভ করবেন?')"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($v['id'])?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-archive"></i> আর্কাইভ</button></form></div><?php endif;?></div>
    <?php page_end(admin());exit;
}
if ($page === 'new' || $page === 'edit' || $page === 'register') {
    if($page!=='register') require_admin();
    $v=null;
    if($page==='edit') { $v=fetch_volunteer((int)($_GET['id']??0)); if(!$v) {http_response_code(404);exit('রেকর্ড পাওয়া যায়নি');} }
    $isAdmin=admin() && $page!=='register';
    page_start($page==='edit'?'তথ্য সম্পাদনা':($isAdmin?'নতুন এন্ট্রি':'স্বেচ্ছাসেবক নিবন্ধন'),$isAdmin?'new':'register',$isAdmin);
    volunteer_form($v,$isAdmin);
    page_end($isAdmin);exit;
}
if ($page === 'login' || $page === 'setup') {
    if($page==='setup' && have_admin()) redirect('login');
    if($page==='login' && admin()) redirect('dashboard');
    site_head($page==='setup'?'প্রাথমিক সেটআপ':'অ্যাডমিন লগইন'); ?>
    <body class="auth-body"><div class="auth-container"><div class="auth-brand"><img src="assets/pran-logo.png" alt="PRAAN"><h1>Volunteer Management</h1><p>Secure administration · PRAAN Network</p></div><div class="auth-card"><div class="auth-title"><span class="auth-icon"><i class="bi bi-shield-lock-fill"></i></span><h2><?= $page==='setup'?'প্রথম অ্যাডমিন তৈরি করুন':'অ্যাডমিন লগইন' ?></h2><p><?= $page==='setup'?'প্রথমবার চালু করার সময় এই ধাপটি একবারই প্রয়োজন।':'আপনার অ্যাডমিন অ্যাকাউন্ট দিয়ে প্রবেশ করুন।' ?></p></div>
    <?php foreach(consume_flashes() as $f) echo '<div class="alert alert-danger">'.e($f['message']).'</div>'; ?>
    <form method="post" action="<?=e(url($page))?>"><?=csrf_field()?>
    <?php if($page==='setup'): ?><label class="form-label">আপনার নাম</label><input type="text" class="form-control mb-3" name="display_name" required minlength="2" placeholder="অ্যাডমিনের নাম"><?php endif; ?>
    <label class="form-label">ইউজারনেম</label><div class="input-icon mb-3"><i class="bi bi-person"></i><input class="form-control" type="text" name="username" autocomplete="username" minlength="4" required placeholder="admin_name"></div>
    <label class="form-label">পাসওয়ার্ড</label><div class="input-icon mb-3"><i class="bi bi-lock"></i><input class="form-control" type="password" name="password" <?= $page==='setup'?'minlength="12"':''?> required autocomplete="<?= $page==='setup'?'new-password':'current-password' ?>" placeholder="<?= $page==='setup'?'অন্তত ১২ অক্ষর':'আপনার পাসওয়ার্ড' ?>"></div>
    <?php if($page==='setup'):?><label class="form-label">পাসওয়ার্ড নিশ্চিত করুন</label><div class="input-icon mb-4"><i class="bi bi-shield-check"></i><input class="form-control" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required placeholder="একই পাসওয়ার্ড লিখুন"></div><?php endif;?>
    <button class="btn btn-brand w-100 py-3" type="submit"><?= $page==='setup'?'নিরাপদ অ্যাকাউন্ট তৈরি করুন':'লগইন করুন' ?> <i class="bi bi-arrow-right"></i></button></form><div class="auth-back"><a href="<?=e(url('home'))?>"><i class="bi bi-arrow-left"></i> পাবলিক পোর্টাল</a></div></div><div class="auth-bottom">PHP + SQLite · No MySQL · v<?=APP_VERSION?></div></div><?php site_scripts();echo '</body></html>';exit;
}
if ($page === 'quick') {
    require_admin();page_start('দ্রুত ডাটা এন্ট্রি','new',true);
    ?><div class="page-note"><i class="bi bi-lightning-charge"></i><div><strong>দ্রুত স্বেচ্ছাসেবক তথ্য এন্ট্রি</strong><p>প্রথমে প্রয়োজনীয় তথ্য এন্ট্রি করুন, পরে যেকোনো সময় <b>Edit</b> থেকে মূল ফর্মের সব ঘর পূরণ করতে পারবেন। কাগজের ফর্মের সম্মতির ঘরগুলো মিলিয়ে নিন।</p></div><a href="<?=e(url('new'))?>" class="btn btn-outline-brand btn-sm">সম্পূর্ণ ফর্ম</a></div>
    <form method="post" action="<?=e(url('save'))?>" enctype="multipart/form-data" class="card quick-card"><div class="card-body"><div class="d-flex justify-content-between align-items-center mb-4"><h3 class="m-0">কাগজের ফর্ম → ডিজিটাল রেকর্ড</h3><span class="badge text-bg-light">Quick Entry Mode</span></div><?=csrf_field()?><input type="hidden" name="quick_mode" value="1"><input type="hidden" name="entry_type" value="admin"><div class="field-grid">
    <?php $defs=all_fields();$defaults=array_replace(default_data(),$_SESSION['form_draft']??[]);unset($_SESSION['form_draft']); if(empty($defaults['registration_date'])) $defaults['registration_date']=date('Y-m-d');
    foreach(['form_no','project','registration_date','name_bn','name_en','gender','dob','blood_group','phone','alternate_phone','occupation','education','id_number','present_village','present_union','present_upazila','present_district','interests','special_skills','experience_summary','status','category'] as $key) render_input($key,$defs[$key],$defaults);
    ?></div><div class="photo-upload mt-3"><i class="bi bi-camera fs-3"></i><div><strong>ছবি (ঐচ্ছিক)</strong><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm mt-1"></div></div>
    <div class="text-end mt-4"><button type="submit" class="btn btn-brand px-4 py-3"><i class="bi bi-floppy"></i> সংরক্ষণ + পরের ফর্ম</button></div></div></form>
    <?php page_end(true);exit;
}
if ($page === 'dashboard') { render_dashboard_v110($pdo); exit; }
if ($page === 'volunteers') { render_volunteer_table($pdo); exit; }
if ($page === 'archived') {
    require_admin();
    $rows=$pdo->query('SELECT id,volunteer_code,name_bn,name_en,form_no,deleted_at FROM volunteers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 150')->fetchAll();
    page_start('আর্কাইভ','archived',true); ?>
    <div class="card dashboard-card"><div class="card-header"><h3 class="card-title">আর্কাইভকৃত রেকর্ড</h3><span class="card-label">RESTORE WHEN NEEDED</span></div><div class="card-body p-0"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>আইডি</th><th>নাম</th><th>ফর্ম নং</th><th>আর্কাইভের সময়</th><th>ফিরিয়ে আনুন</th></tr></thead><tbody><?php foreach($rows as $v):?><tr><td><?=e($v['volunteer_code'])?></td><td><?=e($v['name_bn']?:$v['name_en'])?></td><td><?=e($v['form_no'])?></td><td><?=e($v['deleted_at'])?></td><td><form method="post" action="<?=e(url('restore'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=e($v['id'])?>"><button type="submit" class="btn btn-outline-brand btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="5" class="text-center p-5">আর্কাইভে কোনো রেকর্ড নেই।</td></tr><?php endif;?></tbody></table></div></div></div>
    <?php page_end(true);exit;
}
if ($page === 'reports') { render_reports_v110($pdo); exit; }
if ($page === 'import') {
    require_admin();$pending=$_SESSION['import_pending']??null;
    if($pending && time()-(int)($pending['time']??0)>1200){@unlink((string)($pending['path']??''));unset($_SESSION['import_pending']);$pending=null;}
    page_start('Excel ও CSV Import','import',true); ?>
    <div class="pro-heading"><div><span class="eyebrow">IMPORT / EXPORT · SAME DATA DICTIONARY</span><h2>স্বেচ্ছাসেবক তথ্য আমদানি</h2><p>Google Forms Responses অথবা সাইট থেকে Export করা সম্পূর্ণ Excel/CSV ফাইল সরাসরি আমদানি করুন।</p></div><div class="pro-heading-actions"><a class="btn btn-outline-success" href="<?=e(url('form_export',['format'=>'xlsx']))?>"><i class="bi bi-file-earmark-excel"></i> Google Form Export</a><a class="btn btn-outline-brand" href="<?=e(url('template_full'))?>"><i class="bi bi-file-earmark-arrow-down"></i> Full Template</a></div></div>
    <div class="pro-import-grid"><div class="card pro-table-card"><div class="card-body p-4"><div class="pro-import-steps"><span>01 <b>ফাইল আপলোড</b></span><span>02 <b>Preview ও ডুপ্লিকেট যাচাই</b></span><span>03 <b>Confirm ও Import</b></span></div>
      <form method="post" enctype="multipart/form-data" action="<?=e(url('import_preview'))?>" class="mt-4"><?=csrf_field()?><label class="pro-upload-zone" for="pranImportFile"><i class="bi bi-cloud-arrow-up"></i><strong>Excel (.xlsx) অথবা CSV (.csv) নির্বাচন করুন</strong><span>সর্বোচ্চ ৮ MB · Google Forms 54 columns / PRAAN Complete Export</span><input id="pranImportFile" name="file" class="form-control mt-3" type="file" accept=".xlsx,.csv,.txt" required></label>
      <fieldset class="mt-3 mb-2 p-3 border rounded-3 bg-light"><legend class="fs-6 fw-semibold float-none w-auto px-2">বিদ্যমান রেকর্ডের জন্য কী করবেন?</legend>
      <label class="d-flex gap-2 align-items-start mb-2"><input type="radio" name="existing_mode" value="update" checked class="form-check-input mt-1"><span><strong>Update Existing + Import New</strong><small class="d-block text-muted">আগের Volunteer ID বা NID মিললে একই রেকর্ডে ফাইলের পূরণ করা তথ্য আপডেট; ছবি, প্রকাশ অনুমতি ও পুরোনো ID অক্ষত থাকবে।</small></span></label>
      <label class="d-flex gap-2 align-items-start"><input type="radio" name="existing_mode" value="skip" class="form-check-input mt-1"><span><strong>Only New Records</strong><small class="d-block text-muted">বিদ্যমান রেকর্ডে কোনো পরিবর্তন নয়, শুধু নতুনদের যুক্ত করুন।</small></span></label>
      </fieldset><button class="btn btn-brand mt-3" type="submit"><i class="bi bi-shield-check"></i> Import Preview দেখুন</button></form>
      <div class="pro-template-links"><a href="<?=e(url('template_google'))?>"><i class="bi bi-file-earmark-text"></i> Google Forms ৫৪-কলাম টেমপ্লেট</a><a href="<?=e(url('template_full'))?>"><i class="bi bi-file-earmark-spreadsheet"></i> সম্পূর্ণ ফিল্ড টেমপ্লেট</a></div></div></div>
      <div class="card pro-table-card"><div class="card-body p-4"><h3 class="h5 mb-3">নিরাপদ আমদানির নিয়ম</h3><div class="pro-import-tips"><p><i class="bi bi-check-circle"></i> ৫৪টি Google Forms কলাম হুবহু সমর্থিত; মূল না-বদলানো মান সংরক্ষিত।</p><p><i class="bi bi-check-circle"></i> প্রয়োজনীয় ফোনে শুরুর ০ ফিরিয়ে দেয়; Excel serial date ঠিক করে।</p><p><i class="bi bi-check-circle"></i> আগের Volunteer ID বা NID/জন্মনিবন্ধন মিললে একই রেকর্ড Update/Skip; একই নাম বা শেয়ার্ড ফোনে ব্লক নয়।</p><p><i class="bi bi-check-circle"></i> নতুন Import হওয়া প্রোফাইল পাবলিক তালিকায় স্বয়ংক্রিয়ভাবে প্রকাশ হয় না।</p><p><i class="bi bi-check-circle"></i> ডাটাবেইস পরিবর্তনের আগে Preview ও Confirm থাকবে।</p></div><div class="alert alert-warning small">মূল ফাইলে অনুপস্থিত ঠিকানা, সম্মতি বা অন্যান্য তথ্য অনুমান করে পূরণ করা হয় না। Import শেষে Edit-এ যাচাই করুন।</div></div></div></div>
    <?php if($pending):$stats=$pending['stats'];?><div class="card pro-table-card mt-3"><div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h3 class="m-0"><i class="bi bi-clipboard-check"></i> Import Preview — এখনও ডাটাবেইসে কিছু পরিবর্তন হয়নি</h3><span class="badge bg-info text-dark"><?=($pending['existing_mode']??'update')==='update'?'Update Existing + New':'Only New'?></span></div>
      <div class="pro-import-stats"><span>মোট <b><?=e($stats['total'])?></b></span><span class="text-success">নতুন <b><?=e($stats['added'])?></b></span><span class="text-primary">আপডেট <b><?=e($stats['updated'])?></b></span><span>বাদ <b><?=e($stats['skipped'])?></b></span><span class="text-danger">যাচাই প্রয়োজন <b><?=e($stats['review'])?></b></span><span>অসম্পূর্ণ <b><?=e($stats['invalid'])?></b></span></div>
      <div class="alert alert-info small mt-3">একই নাম বা একই ফোন নম্বরকে ডুপ্লিকেট ধরে বাতিল করা হয় না। পুরোনো Volunteer ID অথবা পরিচয়পত্র নম্বর মিললে একই ব্যক্তির তথ্য আপডেট করা হবে। একই তথ্য দুই ভিন্ন ব্যক্তির বলে মনে হলে সেই সারি যাচাইয়ের জন্য আলাদা থাকবে। <strong>বর্তমান Preview-তে ফাইলের সঙ্গে লাইভ ডাটাবেইস মিলিয়ে সিদ্ধান্ত দেখানো হয়েছে।</strong></div>
      <p class="small text-muted"><?=e($pending['format'])?> ফরম্যাট · Preview ২০ মিনিট বৈধ · এক ফাইলে সর্বোচ্চ ২৫০০ রেকর্ড · প্রয়োজনে ১৫০টি সারি পর্যন্ত নিচে দেখা যাবে।</p>
      <?php foreach($stats['warnings'] as $warning):?><p class="small text-warning"><i class="bi bi-exclamation-triangle"></i> <?=e($warning)?></p><?php endforeach;?>
      <div class="table-responsive border rounded-3 mb-3" style="max-height:450px;overflow:auto"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light" style="position:sticky;top:0;z-index:1"><tr><th>Excel Row</th><th>Volunteer ID</th><th>নাম</th><th>ফলাফল</th><th>কারণ</th></tr></thead><tbody>
      <?php foreach(($stats['rows']??[]) as $r): $status=$r['status']??'review';$styles=['new'=>'success','update'=>'primary','skipped'=>'secondary','review'=>'warning','invalid'=>'danger'];$labels=['new'=>'নতুন','update'=>'আপডেট','skipped'=>'বাদ','review'=>'যাচাই','invalid'=>'অসম্পূর্ণ']; ?><tr><td><?=e($r['line'])?></td><td><?=e($r['code']?:'—')?></td><td><?=e($r['name']?:'—')?></td><td><span class="badge bg-<?=e($styles[$status]??'secondary')?>"><?=e($labels[$status]??$status)?></span></td><td class="small"><?=e($r['reason'])?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php if(($stats['phone_warnings']??0)>0):?><p class="small text-warning"><i class="bi bi-info-circle"></i> <?=e($stats['phone_warnings'])?>টি নতুন সারিতে অন্য ব্যক্তির ফোন নম্বর পাওয়া গেছে—তবু Import বন্ধ হবে না। পরে সত্যতা যাচাই করুন।</p><?php endif;?>
      <div class="d-flex gap-2 flex-wrap"><form method="post" action="<?=e(url('import_commit'))?>"><?=csrf_field()?><button class="btn btn-brand" type="submit" onclick="return confirm('Preview যাচাই করেছেন? <?=e($stats['added'])?>টি নতুন রেকর্ড তৈরি হবে এবং <?=e($stats['updated'])?>টি বিদ্যমান রেকর্ডের পূরণ করা তথ্য আপডেট হবে। চালিয়ে যাবেন?')"><i class="bi bi-database-check"></i> Confirm & Import</button></form><form method="post" action="<?=e(url('import_cancel'))?>"><?=csrf_field()?><button type="submit" class="btn btn-outline-secondary">বাতিল করুন</button></form></div>
    </div></div><?php endif;?>
    <?php $last=$_SESSION['praan_import_last']??null; if(is_array($last)&&time()-(int)($last['at']??0)<3600):$ls=$last['stats'];?><div class="alert alert-success mt-3"><strong><i class="bi bi-check-circle"></i> সর্বশেষ Import সম্পন্ন:</strong> মোট <?=e($ls['total'])?> · নতুন <?=e($ls['added'])?> · আপডেট <?=e($ls['updated'])?> · বাদ <?=e($ls['skipped'])?> · ম্যানুয়াল যাচাই <?=e($ls['review'])?> · অসম্পূর্ণ <?=e($ls['invalid'])?>। <a href="<?=e(url('volunteers'))?>">তালিকা দেখুন</a></div><?php endif;?>
    <?php page_end(true);exit;
}
if ($page === 'backup') {
    require_admin();
    $tmp = tempnam(sys_get_temp_dir(), 'pran_');
    if ($tmp === false) { http_response_code(500); exit('ব্যাকআপের জন্য টেম্প ফাইল তৈরি করা যায়নি।'); }
    @unlink($tmp);
    try {
        $safePath=str_replace("'", "''", $tmp);
        $pdo->exec("VACUUM INTO '$safePath'");
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="praan_backup_'.date('Y-m-d_H-i').'.sqlite"');
        header('Content-Length: '.filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp); @unlink($tmp); exit;
    } catch(Throwable $e) {
        @unlink($tmp); error_log('PRAAN Backup: '.$e->getMessage());
        http_response_code(500); exit('ব্যাকআপ তৈরি হয়নি; সার্ভারের SQLite অনুমতি পরীক্ষা করুন।');
    }
}
if ($page === 'export') {
    require_admin();page_start('Excel এক্সপোর্ট','export',true);?>
    <div class="section-top"><div><span class="eyebrow">BACKUP & EXPORT</span><h2>সমস্ত স্বেচ্ছাসেবকের তথ্য ডাউনলোড</h2><p class="text-muted">মূল ফর্মের সব ফিল্ড, দক্ষতা, প্রশিক্ষণ, সম্মতি এবং অফিসের তথ্যসহ।</p></div></div>
    <div class="export-grid"><a class="export-card" href="<?=e(url('export',['format'=>'xlsx']))?>"><span class="export-icon excel"><i class="bi bi-file-earmark-excel"></i></span><strong>Excel Workbook (.xlsx)</strong><p>Microsoft Excel-এ খোলার উপযোগী প্রকৃত XLSX ফাইল। আলাদা লাইব্রেরি দরকার নেই।</p><span>ডাউনলোড <i class="bi bi-arrow-right"></i></span></a><a class="export-card" href="<?=e(url('export',['format'=>'csv']))?>"><span class="export-icon csv"><i class="bi bi-filetype-csv"></i></span><strong>CSV UTF-8 (.csv)</strong><p>Excel, Google Sheets বা অন্য সফটওয়্যারে ইমপোর্ট ও বিশ্লেষণ করতে পারবেন।</p><span>ডাউনলোড <i class="bi bi-arrow-right"></i></span></a></div>
    <div class="card pro-table-card mt-4"><div class="card-body p-4"><h3 class="h5"><i class="bi bi-file-earmark-spreadsheet"></i> Google Forms-এর একই ৫৪-কলাম ফরম্যাট</h3><p class="text-muted small">আপনার আপলোড করা Google Forms Responses Excel-এ যে কলামগুলো আছে, সেই একই ক্রম ও শিরোনামে Export হবে। নতুন Import-এ এই ফাইল সরাসরি ফিরিয়ে নেওয়া যাবে।</p><div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-success" href="<?=e(url('form_export',['format'=>'xlsx']))?>"><i class="bi bi-file-earmark-excel"></i> Google Forms XLSX</a><a class="btn btn-outline-brand" href="<?=e(url('form_export',['format'=>'csv']))?>"><i class="bi bi-filetype-csv"></i> Google Forms CSV</a><a class="btn btn-light" href="<?=e(url('template_google'))?>">Blank Template</a><a class="btn btn-brand" href="<?=e(url('import'))?>"><i class="bi bi-upload"></i> Import</a></div></div></div>
    <div class="notice-box mt-4"><i class="bi bi-shield-lock"></i><div><strong>ব্যক্তিগত তথ্যের সুরক্ষা</strong><p>Excel-এ NID, মোবাইল, জরুরি যোগাযোগ ও সংবেদনশীল তথ্য থাকতে পারে। ডাউনলোড করা ফাইল পাসওয়ার্ড-সুরক্ষিত স্থানে রাখুন; পাবলিক ডিরেক্টরিতে এসব তথ্য নেই।</p></div></div>
    <?php page_end(true);exit;
}
if ($page === 'activity') {
    require_admin();
    $logs=$pdo->query('SELECT l.*, v.volunteer_code, u.display_name FROM activity_log l LEFT JOIN volunteers v ON v.id=l.volunteer_id LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 120')->fetchAll();
    page_start('অ্যাক্টিভিটি লগ','activity',true); ?>
    <div class="card dashboard-card"><div class="card-header"><h3 class="card-title">সাম্প্রতিক কার্যক্রম</h3><span class="card-label">LAST 120 ACTIONS</span></div><div class="card-body p-0"><div class="table-responsive"><table class="table modern-table"><thead><tr><th>তারিখ</th><th>অ্যাকশন</th><th>স্বেচ্ছাসেবক ID</th><th>কর্মী</th><th>বিবরণ</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?=e($l['created_at'])?></td><td><?=e($l['action'])?></td><td><?=e($l['volunteer_code']?:'—')?></td><td><?=e($l['display_name']?:'পাবলিক')?></td><td><?=e($l['details'])?></td></tr><?php endforeach;if(!$logs):?><tr><td colspan="5" class="text-center p-4">এখনও কোনো কার্যক্রম নেই।</td></tr><?php endif;?></tbody></table></div></div></div>
    <?php page_end(true);exit;
}
if ($page === 'updates') {
    require_admin();
    render_update_center();
    exit;
}
if($page==='users'){praan_users_page();exit;}
if($page==='roles'){praan_roles_page();exit;}
if($page==='sessions'){praan_sessions_page();exit;}
if($page==='login_history'){praan_login_history_page();exit;}
if($page==='profile'){praan_profile_page();exit;}
if($page==='settings'){praan_settings_editor();exit;}
if($page==='modules'){praan_modules_page();exit;}
if($page==='modules_export'){praan_modules_export();}

if($page==='home') {
    $approved=(int)$pdo->query("SELECT COUNT(*) FROM volunteers WHERE status='সক্রিয়' AND deleted_at IS NULL")->fetchColumn();
    $public=(int)$pdo->query("SELECT COUNT(*) FROM volunteers WHERE status='সক্রিয়' AND public_visible=1 AND deleted_at IS NULL")->fetchColumn();
    $areas=(int)$pdo->query("SELECT COUNT(DISTINCT district) FROM volunteers WHERE status='সক্রিয়' AND public_visible=1 AND district != '' AND deleted_at IS NULL")->fetchColumn();
    page_start('পাবলিক হোম','home'); ?>
    <div class="hero <?=praan_enabled('background_enabled')&&is_file(praan_background_file())?'praan-hero-bg':''?>" style="--praan-hero-image:url('<?=e(url('background_asset'))?>')"><div class="hero-content"><span class="hero-kicker"><span></span> PRAAN VOLUNTEER NETWORK</span><h1>মানুষের পাশে দাঁড়াতে<br><em>আপনিও হতে পারেন</em><br>পরিবর্তনের অংশ।</h1><p>সমাজ, দুর্যোগ মোকাবিলা ও মানবিক সহায়তায় নিবেদিত তরুণ স্বেচ্ছাসেবকদের একটি সুসংগঠিত নেটওয়ার্ক। আজই আপনার স্বেচ্ছাসেবী প্রোফাইল তৈরি করুন।</p><div class="hero-buttons"><?php if(praan_enabled('public_registration_enabled')):?><a class="btn btn-brand btn-lg" href="<?=e(url('register'))?>">স্বেচ্ছাসেবক হিসেবে যোগ দিন <i class="bi bi-arrow-up-right"></i></a><?php endif;?><?php if(praan_enabled('public_directory_enabled')):?><a class="btn btn-hero-outline btn-lg" href="<?=e(url('directory'))?>">পাবলিক তালিকা দেখুন <i class="bi bi-arrow-right"></i></a><?php endif;?></div><div class="hero-trust"><i class="bi bi-shield-check"></i> নিরাপদ নিবন্ধন <span>·</span> <i class="bi bi-file-earmark-check"></i> প্রশিক্ষণ ও দক্ষতার প্রোফাইল</div></div><div class="hero-art"><div class="hero-art-ring ring-one"></div><div class="hero-art-ring ring-two"></div><div class="hero-people"><i class="bi bi-people-fill"></i></div><div class="floating-label f1"><i class="bi bi-heart-pulse"></i> মানবিক সহায়তা</div><div class="floating-label f2"><i class="bi bi-globe-central-south-asia"></i> কমিউনিটি</div><div class="floating-label f3"><i class="bi bi-check-circle-fill"></i> স্বেচ্ছাসেবা</div></div></div>
    <div class="public-stats"><div><span class="public-stat-icon"><i class="bi bi-people"></i></span><strong><?=number_format($approved)?></strong><small>সক্রিয় স্বেচ্ছাসেবক</small></div><div><span class="public-stat-icon"><i class="bi bi-person-badge"></i></span><strong><?=number_format($public)?></strong><small>প্রকাশে সম্মত প্রোফাইল</small></div><div><span class="public-stat-icon"><i class="bi bi-pin-map"></i></span><strong><?=number_format($areas)?></strong><small>জেলার অংশগ্রহণ</small></div></div>
    <div class="home-section"><div class="eyebrow">HOW IT WORKS</div><h2>স্বেচ্ছাসেবক হওয়ার সহজ ৩ ধাপ</h2><div class="how-grid"><div><span>01</span><i class="bi bi-pencil-square"></i><h3>অনলাইনে নিবন্ধন</h3><p>মূল ফর্মের সব তথ্য সহজে মোবাইল বা কম্পিউটার থেকে জমা দিন।</p></div><div><span>02</span><i class="bi bi-patch-check"></i><h3>অ্যাডমিন যাচাই</h3><p>প্রয়োজনীয় তথ্য, দক্ষতা ও সম্মতি যাচাই করে অনুমোদন দেওয়া হবে।</p></div><div><span>03</span><i class="bi bi-megaphone"></i><h3>মানবিক কাজে অংশগ্রহণ</h3><p>উপযুক্ত কার্যক্রম, টিম ও কর্মসূচির সঙ্গে যুক্ত হোন।</p></div></div></div>
    <div class="home-cta"><div><h2>সমাজের জন্য আপনার সময় ও দক্ষতা মূল্যবান।</h2><p>আপনার স্বেচ্ছাসেবী যাত্রা শুরু করুন।</p></div><?php if(praan_enabled('public_registration_enabled')):?><a href="<?=e(url('register'))?>" class="btn btn-light btn-lg">নিবন্ধন করুন <i class="bi bi-arrow-up-right"></i></a><?php endif;?></div>
    <?php page_end();exit;
}
if($page==='directory') {
    $search=trim((string)($_GET['q']??''));$term='%'.$search.'%';
    $query="SELECT id,volunteer_code,name_bn,name_en,district,category,project,payload FROM volunteers WHERE deleted_at IS NULL AND status='সক্রিয়' AND public_visible=1 AND (name_bn LIKE ? OR name_en LIKE ? OR district LIKE ? OR volunteer_code LIKE ?) ORDER BY id DESC LIMIT ".(int)praan_module_option('directory','max_cards');
    $s=$pdo->prepare($query);$s->execute([$term,$term,$term,$term]);$list=$s->fetchAll();
    page_start('স্বেচ্ছাসেবক ডিরেক্টরি','directory'); ?>
    <div class="directory-hero"><div class="eyebrow">OUR PEOPLE · OUR STRENGTH</div><h1>আমাদের স্বেচ্ছাসেবক পরিবার</h1><p>যেসব সক্রিয় স্বেচ্ছাসেবক সীমিত প্রোফাইল প্রকাশে আলাদাভাবে সম্মতি দিয়েছেন, শুধু তাঁদের তথ্য এখানে দেখা যায়।</p><form method="get" action="index.php" class="directory-search"><input type="hidden" name="page" value="directory"><i class="bi bi-search"></i><input type="search" name="q" value="<?=e($search)?>" placeholder="নাম, ID বা জেলা দিয়ে খুঁজুন..."><button type="submit">খুঁজুন</button></form></div>
    <div class="directory-count"><?=count($list)?>টি প্রোফাইল <?=count($list)===(int)praan_module_option('directory','max_cards')?'(নির্ধারিত সীমা পর্যন্ত)':''?></div><div class="directory-grid"><?php foreach($list as $v):$data=json_decode((string)$v['payload'],true)?:[];?><article class="directory-card"><div class="directory-avatar"><i class="bi bi-person-heart"></i></div><div><span class="directory-id"><?=e($v['volunteer_code'])?></span><h3><?=e($v['name_bn']?:$v['name_en'])?></h3><div class="directory-location"><i class="bi bi-geo-alt"></i> <?=e($v['district']?:'বাংলাদেশ')?></div><div class="directory-tags"><?php foreach(array_slice((array)($data['interests']??[]),0,2) as $interest):?><span><?=e($interest)?></span><?php endforeach;?></div></div><div class="directory-footer"><span><i class="bi bi-patch-check-fill"></i> যাচাইকৃত সক্রিয়</span><small><?=e($v['category']?:'স্বেচ্ছাসেবক')?></small></div></article><?php endforeach;?></div>
    <?php if(!$list):?><div class="directory-empty"><i class="bi bi-people"></i><h3>এখনও প্রকাশযোগ্য প্রোফাইল নেই</h3><p>অনুমোদন ও স্বেচ্ছাসেবকের প্রকাশের সম্মতি থাকলে এখানে দেখা যাবে।</p><a href="<?=e(url('register'))?>" class="btn btn-brand">নতুন নিবন্ধন</a></div><?php endif; ?>
    <?php page_end();exit;
}
praan_error_page(404, 'পৃষ্ঠা পাওয়া যায়নি।');
