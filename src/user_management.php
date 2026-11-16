<?php
declare(strict_types=1);
require_once __DIR__.'/views.php';

function praan_manageable_user(int $id): array {
    $q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$target=$q->fetch();
    if(!$target)throw new RuntimeException('ইউজার পাওয়া যায়নি।');
    if(praan_has_super($id)&&!praan_is_super())throw new RuntimeException('Super Admin পরিবর্তনের অনুমতি নেই।');
    return $target;
}
function praan_validate_assigned_roles(array $raw): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$raw),fn($v)=>$v>0)));
    if(!$ids||count($ids)>8)throw new RuntimeException('অন্তত একটি বৈধ রোল নির্বাচন করুন।');
    $q=db()->prepare('SELECT r.id,r.slug,r.name,r.deleted_at FROM roles r WHERE r.id=?');
    foreach($ids as $id){
        $q->execute([$id]);$r=$q->fetch();if(!$r||$r['deleted_at']!==null)throw new RuntimeException('রোলটি বৈধ নয়।');
        if($r['slug']==='super_admin'&&!praan_is_super())throw new RuntimeException('Super Admin দেওয়ার অনুমতি নেই।');
        if(!praan_is_super()){
            $p=db()->prepare('SELECT permission FROM role_permissions WHERE role_id=?');$p->execute([$id]);
            foreach($p->fetchAll(PDO::FETCH_COLUMN) as $permission)if(!praan_can((string)$permission))throw new RuntimeException('নিজের অনুমতির বাইরে রোল প্রদান করা যাবে না।');
        }
    }return $ids;
}
function praan_user_role_write(int $userId,array $ids): void {
    $pdo=db();$pdo->prepare('DELETE FROM user_roles WHERE user_id=?')->execute([$userId]);
    $q=$pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)');foreach($ids as $roleId)$q->execute([$userId,$roleId]);
}
function praan_user_overrides_write(int $id,array $posted): void {
    $pdo=db();$pdo->prepare('DELETE FROM user_permission_overrides WHERE user_id=?')->execute([$id]);
    $q=$pdo->prepare('INSERT INTO user_permission_overrides(user_id,permission,allowed) VALUES(?,?,?)');
    foreach(praan_permissions() as $key=>$label){
        $value=(string)($posted[$key]??'inherit');
        if(!in_array($value,['allow','deny'],true))continue;
        if($value==='allow'&&!praan_is_super()&&!praan_can($key))throw new RuntimeException('নিজের অনুমতির বাইরে Override দেওয়া যাবে না।');
        $q->execute([$id,$key,$value==='allow'?1:0]);
    }
}
function praan_user_save_post(): void {
    praan_require('users.manage');verify_csrf();
    um_require_password((string)($_POST['actor_password']??''));
    $id=(int)($_POST['id']??0);$pdo=db();
    $name=trim((string)($_POST['display_name']??''));$username=trim((string)($_POST['username']??''));
    $password=(string)($_POST['new_password']??'');$roles=praan_validate_assigned_roles((array)($_POST['roles']??[]));
    if(praan_str_length($name)<2||praan_str_length($name)>100)throw new RuntimeException('২–১০০ অক্ষরের নাম দিন।');
    if(!preg_match('/^[A-Za-z0-9_.-]{4,60}$/D',$username))throw new RuntimeException('ইউজারনেম ৪–৬০ অক্ষরের ইংরেজি অক্ষর/সংখ্যা হতে হবে।');
    if($id){
        $target=praan_manageable_user($id);
        if($id===(int)admin_id()&&!in_array('super_admin',array_map(function($roleId){$q=db()->prepare('SELECT slug FROM roles WHERE id=?');$q->execute([$roleId]);return $q->fetchColumn();},$roles),true)&&praan_has_super($id)&&praan_active_super_count()<=1)throw new RuntimeException('সর্বশেষ Super Admin-এর রোল পরিবর্তন করা যাবে না।');
    } elseif(strlen($password)<12)throw new RuntimeException('নতুন অ্যাকাউন্টের জন্য অন্তত ১২ অক্ষরের পাসওয়ার্ড দিন।');
    if($password!==''&&strlen($password)<12)throw new RuntimeException('পাসওয়ার্ড অন্তত ১২ অক্ষরের হতে হবে।');
    if($id===(int)admin_id() && $password!=='')throw new RuntimeException('নিজের পাসওয়ার্ড My Profile থেকে পরিবর্তন করুন।');
    $hasSuper=false;foreach($roles as $roleId){$q=$pdo->prepare('SELECT slug FROM roles WHERE id=?');$q->execute([$roleId]);if($q->fetchColumn()==='super_admin')$hasSuper=true;}
    if($id && praan_has_super($id) && !$hasSuper && praan_active_super_count()<=1)throw new RuntimeException('সর্বশেষ Super Admin-এর রোল সরানো যাবে না।');
    if($id && praan_has_super($id) && !praan_is_super())throw new RuntimeException('Super Admin এডিট করা যাবে না।');
    $pdo->beginTransaction();try{
        if($id){
            $q=$pdo->prepare('UPDATE users SET username=?,display_name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$q->execute([$username,$name,$id]);
            if($password!==''){$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);$pdo->prepare('UPDATE user_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL')->execute([time(),$id]);}
        }else{
            $q=$pdo->prepare('INSERT INTO users(uid,username,display_name,password_hash,status) VALUES(?,?,?,?,?)');
            $q->execute([praan_uid('USR'),$username,$name,password_hash($password,PASSWORD_DEFAULT),'active']);$id=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE users SET display_code=? WHERE id=?')->execute([praan_next_user_code($pdo),$id]);
        }
        praan_user_role_write($id,$roles);
        if(praan_is_super())praan_user_overrides_write($id,(array)($_POST['overrides']??[]));
        $pdo->commit();
        log_action(null,'user_saved','user_id='.$id);
        flash('success','ইউজার এবং রোল সংরক্ষণ করা হয়েছে।');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function praan_user_state_post(): void {
    praan_require('users.manage');verify_csrf();um_require_password((string)($_POST['actor_password']??''));
    $id=(int)($_POST['id']??0);$action=(string)($_POST['action']??'');
    $u=praan_manageable_user($id);
    if(!in_array($action,['activate','disable','archive','restore'],true))throw new RuntimeException('অবৈধ অ্যাকশন।');
    if(in_array($action,['disable','archive'],true))praan_safe_actor_target($id,true);
    if(in_array($action,['activate','restore'],true) && praan_has_super($id) && !praan_is_super())throw new RuntimeException('Super Admin সক্রিয় করার অনুমতি নেই।');
    $pdo=db();
    if($action==='archive'){
        $pdo->prepare("UPDATE users SET deleted_at=CURRENT_TIMESTAMP,status='disabled',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
    } elseif($action==='restore'){
        $pdo->prepare("UPDATE users SET deleted_at=NULL,status='disabled',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
    } else {
        if($u['deleted_at']!==null)throw new RuntimeException('প্রথমে Archived ইউজার Restore করুন।');
        $pdo->prepare('UPDATE users SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$action==='activate'?'active':'disabled',$id]);
    }
    if($action!=='activate')$pdo->prepare('UPDATE user_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL')->execute([time(),$id]);
    log_action(null,'user_'.$action,'user_id='.$id);flash('success','ইউজারের স্ট্যাটাস পরিবর্তন হয়েছে।');
}
function praan_reset_user_password(): void {
    praan_require('users.manage');verify_csrf();um_require_password((string)($_POST['actor_password']??''));
    $id=(int)($_POST['id']??0);praan_manageable_user($id);
    if($id===(int)admin_id())throw new RuntimeException('নিজের পাসওয়ার্ড Profile পৃষ্ঠা থেকে বদলান।');
    $pass=(string)($_POST['new_password']??'');if(strlen($pass)<12)throw new RuntimeException('অন্তত ১২ অক্ষরের পাসওয়ার্ড দিন।');
    $pdo=db();$pdo->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$id]);
    $pdo->prepare('UPDATE user_sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL')->execute([time(),$id]);
    log_action(null,'user_password_reset','user_id='.$id);flash('success','পাসওয়ার্ড পরিবর্তন হয়েছে এবং পুরোনো সেশন বাতিল।');
}
function praan_role_save_post(): void {
    praan_require('roles.manage');verify_csrf();um_require_password((string)($_POST['actor_password']??''));
    $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));
    $slug=trim((string)($_POST['slug']??''));$requested=(array)($_POST['permissions']??[]);
    if(praan_str_length($name)<3||praan_str_length($name)>80||!preg_match('/^[a-z][a-z0-9_]{2,45}$/D',$slug))throw new RuntimeException('রোলের নাম/slug সঠিক নয়।');
    $allowed=array_keys(praan_permissions());$perms=array_values(array_unique(array_filter($requested,fn($p)=>is_string($p)&&in_array($p,$allowed,true))));
    if(!$perms)throw new RuntimeException('অন্তত একটি Permission দিন।');
    foreach($perms as $p)if(!praan_is_super()&&!praan_can($p))throw new RuntimeException('নিজের অনুমতির বাইরে Permission দেওয়া যাবে না।');
    $pdo=db();if($id){$q=$pdo->prepare('SELECT * FROM roles WHERE id=?');$q->execute([$id]);$role=$q->fetch();if(!$role||$role['deleted_at']!==null||$role['is_system'])throw new RuntimeException('সিস্টেম রোল সম্পাদনা করা যাবে না।');}
    $pdo->beginTransaction();try{
        if($id){$pdo->prepare('UPDATE roles SET slug=?,name=? WHERE id=?')->execute([$slug,$name,$id]);$pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$id]);}
        else{$pdo->prepare('INSERT INTO roles(uid,slug,name,is_system) VALUES(?,?,?,0)')->execute([praan_uid('ROL'),$slug,$name]);$id=(int)$pdo->lastInsertId();}
        $q=$pdo->prepare('INSERT INTO role_permissions(role_id,permission) VALUES(?,?)');foreach($perms as $p)$q->execute([$id,$p]);
        $pdo->commit();log_action(null,'role_saved','role_id='.$id);flash('success','কাস্টম রোল সংরক্ষণ হয়েছে।');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function praan_role_delete_post(): void {
    praan_require('roles.manage');verify_csrf();um_require_password((string)($_POST['actor_password']??''));
    $id=(int)($_POST['id']??0);$q=db()->prepare('SELECT * FROM roles WHERE id=?');$q->execute([$id]);$r=$q->fetch();
    if(!$r||$r['is_system'])throw new RuntimeException('সিস্টেম রোল সরানো যাবে না।');
    $q=db()->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('এই রোলে ইউজার আছে। আগে ইউজারদের অন্য রোল দিন।');
    db()->prepare('UPDATE roles SET deleted_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
    log_action(null,'role_archived','role_id='.$id);flash('success','রোল আর্কাইভ হয়েছে।');
}
function praan_session_revoke_post(bool $otherUsers=true): void {
    praan_require($otherUsers?'sessions.manage':'dashboard.view');verify_csrf();
    $uid=trim((string)($_POST['uid']??''));if(!praan_uid_valid($uid,'SES'))throw new RuntimeException('Session UID সঠিক নয়।');
    $q=db()->prepare('SELECT user_id FROM user_sessions WHERE uid=?');$q->execute([$uid]);$target=$q->fetchColumn();
    if(!$target)throw new RuntimeException('সেশন পাওয়া যায়নি।');
    if(!$otherUsers&&(int)$target!==(int)admin_id())throw new RuntimeException('শুধু নিজের সেশন বন্ধ করতে পারবেন।');
    if($otherUsers&&praan_has_super((int)$target)&&!praan_is_super())throw new RuntimeException('Super Admin-এর সেশন বন্ধ করার অনুমতি নেই।');
    db()->prepare('UPDATE user_sessions SET revoked_at=? WHERE uid=? AND revoked_at IS NULL')->execute([time(),$uid]);
    log_action(null,'session_revoked','session_uid='.$uid);flash('success','সেশন বন্ধ করা হয়েছে।');
}
function praan_profile_save_post(): void {
    praan_require('dashboard.view');verify_csrf();um_require_password((string)($_POST['actor_password']??''));
    $name=trim((string)($_POST['display_name']??''));if(praan_str_length($name)<2||praan_str_length($name)>100)throw new RuntimeException('নাম ২–১০০ অক্ষর দিন।');
    db()->prepare('UPDATE users SET display_name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,admin_id()]);
    $_SESSION['admin_name']=$name;log_action(null,'profile_changed');flash('success','প্রোফাইল আপডেট হয়েছে।');
}
function praan_profile_password_post(): void {
    praan_require('dashboard.view');verify_csrf();um_require_password((string)($_POST['current_password']??''));
    $new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['password_confirmation']??'');
    if(strlen($new)<12||!hash_equals($new,$confirm))throw new RuntimeException('১২+ অক্ষরের নতুন পাসওয়ার্ড দিন এবং নিশ্চিত করুন।');
    db()->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),admin_id()]);
    db()->prepare('UPDATE user_sessions SET revoked_at=? WHERE user_id=? AND uid<>? AND revoked_at IS NULL')->execute([time(),admin_id(),(string)($_SESSION['praan_v16_session']??'')]);
    log_action(null,'password_changed');flash('success','পাসওয়ার্ড বদলেছে, অন্য ডিভাইসের সেশন বন্ধ হয়েছে।');
}

function praan_users_top(string $heading,string $active): void {
    page_start($heading,$active,true);
    $links=[['users','bi-people','ইউজার'],['roles','bi-shield-lock','রোল ও পারমিশন'],['sessions','bi-laptop','অ্যাক্টিভ সেশন'],['login_history','bi-clock-history','লগইন হিস্ট্রি'],['profile','bi-person-circle','আমার প্রোফাইল']];
    echo '<div class="praan-access-tabs">';foreach($links as [$key,$icon,$label])if(($key==='roles'&&!praan_can('roles.manage'))||($key==='users'&&!praan_can('users.view'))||($key==='sessions'&&!praan_can('users.view'))||($key==='login_history'&&!praan_can('audit.view')))continue;
    else echo '<a href="'.e(url($key)).'" class="'.($key===$active?'active':'').'"><i class="bi '.e($icon).'"></i> '.e($label).'</a>';
    echo '</div>';
}
function praan_role_dropdown(array $assigned=[],bool $multiple=true): void {
    $roles=db()->query('SELECT id,slug,name,is_system FROM roles WHERE deleted_at IS NULL ORDER BY is_system DESC, name')->fetchAll();
    foreach($roles as $r){if($r['slug']==='super_admin'&&!praan_is_super())continue;
        if(!praan_is_super()){$q=db()->prepare('SELECT permission FROM role_permissions WHERE role_id=?');$q->execute([$r['id']]);$all=true;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $perm)if(!praan_can($perm))$all=false;if(!$all)continue;}
        echo '<label class="praan-role-check"><input type="checkbox" name="roles[]" value="'.(int)$r['id'].'" '.(in_array((int)$r['id'],$assigned,true)?'checked':'').'> '.e($r['name']).' <small>'.e($r['slug']).'</small></label>';
    }
}
function praan_users_page(): void {
    praan_require('users.view');$pdo=db();
    $q=trim((string)($_GET['q']??''));$archived=(string)($_GET['archived']??'')==='1';
    $stmt=$pdo->prepare('SELECT u.id,u.uid,u.display_code,u.username,u.display_name,u.status,u.deleted_at,u.created_at,u.last_login_at, GROUP_CONCAT(r.name, char(44)||char(32)) AS role_names FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE '.($archived?'u.deleted_at IS NOT NULL':'u.deleted_at IS NULL').' AND (u.username LIKE :q OR u.display_name LIKE :q2) GROUP BY u.id ORDER BY u.id DESC LIMIT 150');
    $stmt->execute(['q'=>'%'.$q.'%','q2'=>'%'.$q.'%']);$users=$stmt->fetchAll();
    praan_users_top('User Management','users');
    echo '<div class="praan-access-hero"><div><span class="eyebrow">IDENTITY & ACCESS</span><h2>ইউজার ম্যানেজমেন্ট</h2><p>অ্যাকাউন্ট, কাস্টম রোল, অনুমতি ও সেশন নিয়ন্ত্রণ করুন।</p></div><i class="bi bi-people-fill"></i></div>';
    echo '<div class="card dashboard-card"><div class="card-body"><form class="d-flex flex-wrap gap-2 mb-3" method="get"><input type="hidden" name="page" value="users"><input class="form-control" style="max-width:340px" type="search" name="q" value="'.e($q).'" placeholder="নাম বা ইউজারনেম"><select class="form-select" style="max-width:170px" name="archived"><option value="0">Active / Disabled</option><option value="1" '.($archived?'selected':'').'>Archived</option></select><button class="btn btn-brand">খুঁজুন</button>';
    if(praan_can('users.manage'))echo '<button type="button" class="btn btn-outline-brand ms-auto" data-bs-toggle="modal" data-bs-target="#praanNewUser"><i class="bi bi-person-plus"></i> নতুন ইউজার</button>';
    echo '</form><div class="table-responsive"><table class="table align-middle modern-table"><thead><tr><th>নাম ও UID</th><th>রোল</th><th>স্ট্যাটাস</th><th>শেষ লগইন</th><th>অ্যাকশন</th></tr></thead><tbody>';
    foreach($users as $u){
        $status=$u['deleted_at']!==null?'Archived':($u['status']==='active'?'Active':'Disabled');
        echo '<tr><td><strong>'.e($u['display_name']).'</strong><small class="d-block text-muted">@'.e($u['username']).' · '.e($u['display_code']??'USR-').'</small></td><td>'.e($u['role_names']??'No role').'</td><td><span class="badge '.($status==='Active'?'text-bg-success':'text-bg-secondary').'">'.e($status).'</span></td><td>'.e($u['last_login_at']?:'—').'</td><td>';
        if(praan_can('users.manage')&&(!praan_has_super((int)$u['id'])||praan_is_super())) echo '<a class="btn btn-sm btn-outline-brand" href="'.e(url('users',['edit'=>$u['id'],'q'=>$q])).'">Edit</a>';
        echo '</td></tr>';
    }
    if(!$users)echo '<tr><td colspan="5" class="text-center text-muted p-4">কোনো ইউজার পাওয়া যায়নি।</td></tr>';
    echo '</tbody></table></div></div></div>';
    if(praan_can('users.manage')){
        $id=(int)($_GET['edit']??0);$editing=null;$assigned=[];$overrides=[];
        if($id){$query=$pdo->prepare('SELECT * FROM users WHERE id=?');$query->execute([$id]);$editing=$query->fetch();if($editing&&praan_has_super($id)&&!praan_is_super())$editing=null;
            if($editing){$query=$pdo->prepare('SELECT role_id FROM user_roles WHERE user_id=?');$query->execute([$id]);$assigned=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
                $query=$pdo->prepare('SELECT permission,allowed FROM user_permission_overrides WHERE user_id=?');$query->execute([$id]);$overrides=$query->fetchAll(PDO::FETCH_KEY_PAIR);}
        }
        echo '<div class="modal fade" id="praanNewUser" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">নতুন ইউজার</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">';
        praan_user_form(null,[],[]);echo '</div></div></div></div>';
        if($editing){echo '<div class="card dashboard-card mt-3" id="userEdit"><div class="card-header"><h3 class="card-title">'.e($editing['display_name']).' — এডিট ও পারমিশন</h3></div><div class="card-body">';praan_user_form($editing,$assigned,$overrides);
            if((int)$editing['id']!==(int)admin_id()){echo '<div class="praan-access-danger"><h5>Account Security &amp; Lifecycle</h5>';
                foreach(['activate'=>'Activate','disable'=>'Disable','archive'=>'Soft Delete','restore'=>'Restore'] as $action=>$title) {
                    echo '<form method="post" action="'.e(url('user_state')).'" onsubmit="return confirm(\'এই পরিবর্তন নিশ্চিত করবেন?\')">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$editing['id'].'"><input type="hidden" name="action" value="'.e($action).'"><input class="form-control form-control-sm" type="password" name="actor_password" placeholder="আপনার পাসওয়ার্ড" required><button class="btn btn-sm '.($action==='archive'?'btn-outline-danger':'btn-outline-secondary').'">'.e($title).'</button></form>';
                }
                echo '<form method="post" action="'.e(url('user_password')).'">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$editing['id'].'"><label>Reset Password (12+ characters)</label><input class="form-control mb-2" type="password" name="new_password" minlength="12" autocomplete="new-password" required><input class="form-control mb-2" type="password" name="actor_password" autocomplete="current-password" placeholder="আপনার পাসওয়ার্ড" required><button class="btn btn-warning btn-sm">Reset &amp; Revoke Sessions</button></form></div>';
            }
            echo '</div></div>';
        }
    }
    echo '<p class="text-muted small mt-3">সকল পরিবর্তন Audit Log-এ সংরক্ষিত হয়। Archive করলে অ্যাকাউন্ট ও তার সেশন বন্ধ হয়, স্বেচ্ছাসেবক ডাটা অপরিবর্তিত থাকে।</p>';
    page_end(true);
}
function praan_user_form(?array $u,array $assigned,array $overrides): void {
    echo '<form method="post" action="'.e(url('user_save')).'">'.csrf_field().'<input type="hidden" name="id" value="'.(int)($u['id']??0).'"><div class="row g-3"><div class="col-md-6"><label class="form-label">Display Name</label><input class="form-control" name="display_name" required minlength="2" maxlength="100" value="'.e($u['display_name']??'').'"></div><div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" required minlength="4" maxlength="60" value="'.e($u['username']??'').'"></div><div class="col-12"><label class="form-label">'.($u?'New Password (ঐচ্ছিক)':'Password (অন্তত ১২ অক্ষর)').'</label><input type="password" class="form-control" name="new_password" minlength="12" autocomplete="new-password" '.($u?'':'required').'></div><div class="col-12"><strong>Roles</strong><div class="praan-role-options">';praan_role_dropdown($assigned);echo '</div></div>';
    if(praan_is_super()){
        echo '<div class="col-12"><details><summary>Permission Overrides (Optional)</summary><p class="small text-muted">Role permissions-এর ওপরে Allow / Deny / Inherit প্রয়োগ হবে। Super Admin সব permission পান।</p><div class="praan-perm-grid">';
        foreach(praan_permissions() as $key=>$title){$v=array_key_exists($key,$overrides)?((int)$overrides[$key]===1?'allow':'deny'):'inherit';
            echo '<label><span>'.e($title).'<small>'.e($key).'</small></span><select name="overrides['.e($key).']" class="form-select form-select-sm"><option value="inherit" '.($v==='inherit'?'selected':'').'>Inherit</option><option value="allow" '.($v==='allow'?'selected':'').'>Allow</option><option value="deny" '.($v==='deny'?'selected':'').'>Deny</option></select></label>';
        }echo '</div></details></div>';
    }
    echo '<div class="col-12"><label class="form-label">আপনার বর্তমান পাসওয়ার্ড (Confirmation)</label><input class="form-control" type="password" name="actor_password" autocomplete="current-password" required></div></div><div class="mt-3"><button type="submit" class="btn btn-brand"><i class="bi bi-check2-circle"></i> Save User</button></div></form>';
}
function praan_roles_page(): void {
    praan_require('roles.manage');$pdo=db();$roles=$pdo->query('SELECT r.*,COUNT(DISTINCT ur.user_id) AS user_count FROM roles r LEFT JOIN user_roles ur ON ur.role_id=r.id WHERE r.deleted_at IS NULL GROUP BY r.id ORDER BY r.is_system DESC,r.name')->fetchAll();
    $edit=(int)($_GET['edit']??0);$current=null;$permissions=[];
    foreach($roles as $r)if((int)$r['id']===$edit&&!$r['is_system'])$current=$r;
    if($current){$q=$pdo->prepare('SELECT permission FROM role_permissions WHERE role_id=?');$q->execute([$edit]);$permissions=$q->fetchAll(PDO::FETCH_COLUMN);}
    praan_users_top('Roles & Permissions','roles');
    echo '<div class="praan-access-hero"><div><span class="eyebrow">GRANULAR AUTHORIZATION</span><h2>Custom Roles & Permissions</h2><p>প্রস্তুত করা ৫টি System Role অপরিবর্তনীয়। নতুন রোল ও ১৭টি Permission থেকে প্রয়োজনমতো নির্বাচন করুন।</p></div><i class="bi bi-shield-check"></i></div>';
    echo '<div class="praan-role-cards">';foreach($roles as $r){
        echo '<div class="card dashboard-card"><div class="card-body"><div class="d-flex justify-content-between"><strong>'.e($r['name']).'</strong><span class="badge '.($r['is_system']?'text-bg-secondary':'text-bg-info').'">'.($r['is_system']?'System':'Custom').'</span></div><code>'.e($r['slug']).'</code><p class="text-muted mt-2 mb-2">'.(int)$r['user_count'].' user(s)</p>';
        if(!$r['is_system'])echo '<a class="btn btn-outline-brand btn-sm" href="'.e(url('roles',['edit'=>$r['id']])).'">Edit</a>';
        if(!$r['is_system'] && (int)$r['user_count']===0)echo '<form class="d-inline" method="post" action="'.e(url('role_delete')).'" onsubmit="return confirm(\'এই কাস্টম রোল আর্কাইভ করবেন?\')">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><input class="form-control form-control-sm d-inline-block" style="width:140px" type="password" name="actor_password" placeholder="পাসওয়ার্ড" required><button class="btn btn-sm btn-outline-danger">Archive</button></form>';
        echo '</div></div>';
    }echo '</div>';
    echo '<div class="card dashboard-card mt-3"><div class="card-header"><h3 class="card-title">'.($current?'কাস্টম রোল সম্পাদনা':'নতুন কাস্টম রোল').'</h3></div><div class="card-body"><form method="post" action="'.e(url('role_save')).'">'.csrf_field().'<input type="hidden" name="id" value="'.(int)($current['id']??0).'"><div class="row g-3"><div class="col-md-6"><label class="form-label">Role Name</label><input class="form-control" name="name" minlength="3" maxlength="80" required value="'.e($current['name']??'').'"></div><div class="col-md-6"><label class="form-label">Slug (english)</label><input class="form-control" name="slug" required pattern="[a-z][a-z0-9_]{2,45}" value="'.e($current['slug']??'').'"></div></div><h5 class="mt-3">Permissions</h5><div class="praan-perm-list">';
    foreach(praan_permissions() as $key=>$label){if(!praan_is_super()&&!praan_can($key))continue;echo '<label><input type="checkbox" name="permissions[]" value="'.e($key).'" '.(in_array($key,$permissions,true)?'checked':'').'> <span>'.e($label).'<small>'.e($key).'</small></span></label>';}
    echo '</div><label class="form-label mt-3">আপনার পাসওয়ার্ড</label><input class="form-control" type="password" name="actor_password" autocomplete="current-password" required><button class="btn btn-brand mt-3"><i class="bi bi-save"></i> Save Custom Role</button></form></div></div>';
    page_end(true);
}
function praan_sessions_page(): void {
    praan_require('users.view');
    $q=db()->query("SELECT s.*,u.username,u.display_name FROM user_sessions s JOIN users u ON u.id=s.user_id ORDER BY s.created_at DESC LIMIT 100");$items=$q->fetchAll();
    praan_users_top('Sessions & Devices','sessions');
    echo '<div class="card dashboard-card"><div class="card-header"><h3 class="card-title">Active Sessions & Devices</h3></div><div class="card-body table-responsive"><table class="table modern-table align-middle"><thead><tr><th>ইউজার</th><th>ডিভাইস / IP (Masked)</th><th>শেষ ব্যবহার</th><th>স্ট্যাটাস</th><th>অ্যাকশন</th></tr></thead><tbody>';
    foreach($items as $s){$active=$s['revoked_at']===null&&(int)$s['expires_at']>time();
        echo '<tr><td><strong>'.e($s['display_name']).'</strong><small class="d-block">@'.e($s['username']).'</small></td><td><small class="d-block">'.e(mb_safe_limit((string)$s['user_agent'],95)).'</small><code>'.e($s['ip_masked']).'</code></td><td>'.e(date('Y-m-d H:i',(int)$s['last_seen_at'])).'</td><td><span class="badge '.($active?'text-bg-success':'text-bg-secondary').'">'.($active?'Active':'Revoked/Expired').'</span></td><td>';
        if($active && (praan_can('sessions.manage')&&(!praan_has_super((int)$s['user_id'])||praan_is_super())||(int)$s['user_id']===(int)admin_id())){
            echo '<form method="post" action="'.e(url((int)$s['user_id']===(int)admin_id()?'my_session_revoke':'session_revoke')).'" onsubmit="return confirm(\'এই সেশন বন্ধ করবেন?\')">'.csrf_field().'<input type="hidden" name="uid" value="'.e($s['uid']).'"><button class="btn btn-sm btn-outline-danger">Revoke</button></form>';
        }echo '</td></tr>';
    }echo '</tbody></table></div></div>';page_end(true);
}
function praan_login_history_page(): void {
    praan_require('audit.view');
    $rows=db()->query('SELECT l.*,u.display_name FROM login_history l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 150')->fetchAll();
    praan_users_top('Login History','login_history');
    echo '<div class="card dashboard-card"><div class="card-header"><h3 class="card-title">Login History (last 150)</h3></div><div class="card-body table-responsive"><table class="table modern-table"><thead><tr><th>সময়</th><th>অ্যাকাউন্ট</th><th>ফলাফল</th><th>Masked IP</th><th>ডিভাইস</th></tr></thead><tbody>';
    foreach($rows as $r)echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['display_name']?:$r['attempted_username']).'</td><td><span class="badge '.($r['outcome']==='success'?'text-bg-success':'text-bg-warning').'">'.e($r['outcome']).'</span></td><td><code>'.e($r['ip_masked']).'</code></td><td>'.e(mb_safe_limit((string)$r['user_agent'],100)).'</td></tr>';
    echo '</tbody></table></div></div>';page_end(true);
}
function praan_profile_page(): void {
    praan_require('dashboard.view');$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([admin_id()]);$u=$q->fetch();
    praan_users_top('My Profile','profile');
    echo '<div class="praan-profile-grid"><div class="card dashboard-card"><div class="card-header"><h3 class="card-title">Account & Profile</h3></div><div class="card-body"><p><code>'.e($u['display_code']??'USR-').'</code> <span class="badge text-bg-success">'.e($u['status']).'</span></p><p><strong>@'.e($u['username']).'</strong></p><form method="post" action="'.e(url('profile_save')).'">'.csrf_field().'<label class="form-label">Display Name</label><input class="form-control mb-3" name="display_name" maxlength="100" required value="'.e($u['display_name']).'"><label class="form-label">Current Password</label><input class="form-control mb-3" type="password" name="actor_password" required autocomplete="current-password"><button class="btn btn-brand">Save Profile</button></form></div></div><div class="card dashboard-card"><div class="card-header"><h3 class="card-title">Change Password</h3></div><div class="card-body"><form method="post" action="'.e(url('profile_password')).'">'.csrf_field().'<label class="form-label">Current Password</label><input class="form-control mb-3" type="password" name="current_password" required autocomplete="current-password"><label class="form-label">New Password (12+)</label><input class="form-control mb-3" type="password" name="new_password" minlength="12" required autocomplete="new-password"><label class="form-label">Confirm New Password</label><input class="form-control mb-3" type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"><button class="btn btn-outline-brand">Change Password &amp; Revoke Other Sessions</button></form></div></div></div>';
    $q=db()->prepare('SELECT uid,user_agent,ip_masked,last_seen_at,expires_at,revoked_at FROM user_sessions WHERE user_id=? ORDER BY created_at DESC LIMIT 15');
    $q->execute([admin_id()]);$sessions=$q->fetchAll();
    echo '<div class="card dashboard-card mt-3"><div class="card-header"><h3 class="card-title">আমার ডিভাইস ও সেশন</h3></div><div class="card-body table-responsive"><table class="table modern-table align-middle"><thead><tr><th>ডিভাইস</th><th>শেষ ব্যবহার</th><th>অবস্থা</th><th>অ্যাকশন</th></tr></thead><tbody>';
    foreach($sessions as $session){$active=$session['revoked_at']===null&&(int)$session['expires_at']>time();
        echo '<tr><td><small>'.e(mb_safe_limit((string)$session['user_agent'],90)).'</small><code class="d-block">'.e($session['ip_masked']).'</code></td><td>'.e(date('Y-m-d H:i',(int)$session['last_seen_at'])).'</td><td>'.($active?'Active':'Closed').'</td><td>';
        if($active)echo '<form method="post" action="'.e(url('my_session_revoke')).'" onsubmit="return confirm(\'এই ডিভাইসের সেশন শেষ করবেন?\')">'.csrf_field().'<input type="hidden" name="uid" value="'.e($session['uid']).'"><button class="btn btn-outline-danger btn-sm">Revoke</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    page_end(true);
}
