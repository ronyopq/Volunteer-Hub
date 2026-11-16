<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../src/bootstrap.php';
function prod_assert(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);}echo 'PASS: '.$message."\n";}
prod_assert(APP_VERSION==='2.2.0','v2.0.0 constant');
prod_assert(isset(praan_permissions()['security.view']),'security read permission');
prod_assert(isset(praan_permissions()['security.manage']),'security management permission');
foreach(['security_center','privacy_desk','privacy_create','privacy_resolve','security_cleanup'] as $route){
    prod_assert(praan_route_permission($route)!==null,'authorized '.$route);
}
prod_assert(in_array('security.manage',praan_role_presets()['super_admin'][1],true),'super admin security management');
prod_assert(!in_array('security.manage',praan_role_presets()['viewer'][1],true),'viewer cannot change security');
prod_assert(function_exists('praan_production_migrate'),'additive migration registered');
prod_assert(function_exists('praan_production_cleanup'),'retention handler registered');
prod_assert(function_exists('praan_privacy_action'),'privacy workflow registered');
prod_assert(function_exists('praan_login_username_locked'),'login username throttling registered');
prod_assert(praan_uid_valid(praan_uid('PVR'),'PVR'),'privacy UID valid');
prod_assert(!praan_uid_valid('PVR-INVALID','PVR'),'invalid UID rejected');
echo "All production self-tests passed.\n";
