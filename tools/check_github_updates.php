<?php
/** Optional cPanel cron; execute from PHP CLI only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once __DIR__.'/../src/views.php';
require_once __DIR__.'/../src/updates.php';
try{
    $status=praan_github_refresh(db(),false);
    echo 'PRAAN GitHub checked at '.gmdate('c').' | repo='.($status['repo']??'none').' | commit='.substr((string)($status['head']['sha']??''),0,12)."\n";
}catch(Throwable $e){fwrite(STDERR,'PRAAN GitHub check failed: '.get_class($e)."\n");exit(1);}
