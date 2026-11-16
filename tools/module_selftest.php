<?php
declare(strict_types=1);
/** Static tests without PDO SQLite; safe on development machines. */
require_once dirname(__DIR__).'/src/identity.php';
require_once dirname(__DIR__).'/src/module_manager.php';
function pm_test(bool $ok,string $what): void {if(!$ok){fwrite(STDERR,"FAIL: $what\n");exit(1);}echo "PASS: $what\n";}
$registry=praan_module_catalog();
pm_test(count($registry)===8,'trusted module count');
pm_test(count(array_filter($registry,fn($m)=>$m['core']))===3,'locked core modules');
try{praan_module_set_state('identity_core',false,'production');pm_test(false,'core disable should fail');}catch(RuntimeException $e){pm_test(str_contains($e->getMessage(),'Core Module'),'core module cannot be disabled');}
$seen=[];foreach($registry as $id=>$manifest){
    pm_test(is_string($manifest['title'])&&$manifest['title']!=='','module '.$id.' manifest');
    foreach($manifest['routes'] as $route){pm_test(!isset($seen[$route]),'unique route: '.$route);$seen[$route]=$id;}
    foreach($manifest['requires'] as $dep)pm_test(isset($registry[$dep]),'dependency '.$dep.' exists');
}
pm_test(praan_module_route_owner('import_preview')==='import','import route protected');
pm_test(praan_module_route_owner('media_chunk')==='media','media route protected');
pm_test(praan_module_route_owner('report_download')==='reports','report route protected');
pm_test(praan_module_route_owner('directory')==='directory','public directory route protected');
pm_test(praan_module_route_owner('download_docx')==='documents','DOCX route protected');
pm_test(praan_module_route_owner('dashboard')===null,'dashboard remains core');
require_once dirname(__DIR__).'/src/authz.php';
pm_test(praan_route_permission('modules')==='modules.view','read permission');
pm_test(praan_route_permission('module_state')==='modules.manage','write permission');
pm_test(!in_array('modules.manage',praan_role_presets()['admin'][1],true),'administrator cannot toggle modules by default');
pm_test(in_array('modules.manage',praan_role_presets()['super_admin'][1],true),'super admin manages modules');
echo "All module registry self-tests passed.\n";
