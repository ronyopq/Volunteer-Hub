<?php
declare(strict_types=1);
/** Read-only self-test for v1.6 identity primitives; no database needed. */
require_once dirname(__DIR__).'/src/identity.php';
require_once dirname(__DIR__).'/src/authz.php';
function check(bool $ok,string $label): void {if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "OK: $label\n";}
$ids=[];for($i=0;$i<200;$i++){$u=praan_uid('USR');check(praan_uid_valid($u,'USR'),'uid #'.$i);$ids[]=$u;}
check(count(array_unique($ids))===200,'unique IDs');
check(!praan_uid_valid('VOL-01234567890123456789012345','USR'),'prefix is isolated');
check(count(praan_permissions())>=17,'granular permission registry');
foreach(praan_role_presets() as $slug=>$role)check(is_array($role[1]),'role '.$slug.' is configured');
check(praan_route_permission('update_install')==='updates.manage','updater route privilege');
check(praan_route_permission('delete')==='volunteers.archive','archive route privilege');
check(praan_route_permission('login')===null,'login public route');
check(praan_route_permission('user_save')==='users.manage','user mutation privilege');
check(praan_route_permission('roles')==='roles.manage','roles page privilege');
check(praan_mask_ip('192.168.44.123')==='192.168.44.0/24','ipv4 privacy masking');
check(praan_str_length('প্রাণ')===5,'unicode name length');
echo "All identity self-tests passed.\n";
