<?php
declare(strict_types=1);
/** Safe, non-verbose errors. Private diagnostic log; never reveal stack traces to visitors. */
@ini_set('display_errors','0');
@ini_set('log_errors','1');
error_reporting(E_ALL);
function praan_error_reference(?Throwable $error=null): string {
    $id='ERR-'.strtoupper(bin2hex(random_bytes(5)));
    $entry=['reference'=>$id,'at'=>gmdate('c'),'method'=>substr((string)($_SERVER['REQUEST_METHOD']??''),0,8)];
    if($error){$entry['type']=get_class($error);$entry['file']=basename($error->getFile());$entry['line']=$error->getLine();$entry['message']=substr(preg_replace('/[\r\n\t]+/',' ',(string)$error->getMessage()),0,400);}
    $directory=__DIR__.'/../storage';
    if(is_dir($directory)&&is_writable($directory)){
        @file_put_contents($directory.'/runtime-errors.jsonl',json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n",FILE_APPEND|LOCK_EX);
    }
    error_log('PRAAN error '.$id.' '.($entry['type']??'http').' '.($entry['file']??'').':'.($entry['line']??''));
    return $id;
}
function praan_error_page(int $status=500,string $description=''): never {
    $status=in_array($status,[400,401,403,404,405,429,500,503],true)?$status:500;
    $labels=[400=>['অনুরোধটি সঠিক নয়','আবার তথ্য যাচাই করে চেষ্টা করুন।'],401=>['লগইন প্রয়োজন','এই পৃষ্ঠাটি দেখতে লগইন করুন।'],403=>['প্রবেশের অনুমতি নেই','এই কাজের জন্য আপনার অ্যাকাউন্টে অনুমতি নেই।'],404=>['পৃষ্ঠা পাওয়া যায়নি','লিংকটি সঠিক কি না যাচাই করুন।'],405=>['অনুমোদিত পদ্ধতি নয়','এই পদ্ধতিতে অনুরোধ গ্রহণ করা হয় না।'],429=>['অনেকবার চেষ্টা হয়েছে','কিছুক্ষণ পরে আবার চেষ্টা করুন।'],500=>['সার্ভারে একটি সমস্যা হয়েছে','অনুগ্রহ করে পরে চেষ্টা করুন অথবা সাপোর্টে জানান।'],503=>['সাময়িক সমস্যা বা রক্ষণাবেক্ষণ','সেবা কিছুক্ষণের জন্য অনুপলব্ধ।']];
    $title=$labels[$status][0];$message=$labels[$status][1];
    if($description!==''&&($status<500||$status===503))$message=$description;
    $reference=$status>=500?praan_error_reference():'';
    if(!headers_sent()){http_response_code($status);header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex');}
    // When application/json is expected, do not replace a failed AJAX request with HTML.
    $accept=(string)($_SERVER['HTTP_ACCEPT']??'');
    if(str_contains($accept,'application/json')){
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$status,'message'=>$message,'reference'=>$reference],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    $safeTitle=htmlspecialchars($title,ENT_QUOTES,'UTF-8');$safeMessage=htmlspecialchars($message,ENT_QUOTES,'UTF-8');
    $refHtml=$reference!==''?'<p class="ref">Error Reference: <code>'.$reference.'</code></p>':'';
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'.$status.' · PRAAN</title><link rel="icon" href="assets/favicon-32.png"><style>body{background:#ecf4f6;color:#18344b;font:16px/1.65 system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}.card{max-width:640px;text-align:center;border-radius:24px;background:#fff;padding:40px 32px;box-shadow:0 15px 48px #11344315}.brand{color:#147d85;letter-spacing:.16em;font-weight:800}.code{font-size:clamp(3.5rem,12vw,6.5rem);font-weight:900;color:#173d56;line-height:1;margin:18px}.card h1{font-size:1.5rem;margin:14px 0}.card p{color:#52687a}.ref{font-size:.85rem;color:#697f8a}.ref code{background:#edf2f4;padding:5px 8px;border-radius:5px;user-select:all}.btn{background:#117f85;color:white;padding:11px 20px;border-radius:12px;display:inline-block;text-decoration:none;margin:16px 5px 0}.btn.secondary{background:#ecf2f4;color:#24546a}</style></head><body><main class="card"><div class="brand">PRAAN VOLUNTEER HUB</div><div class="code">'.$status.'</div><h1>'.$safeTitle.'</h1><p>'.$safeMessage.'</p>'.$refHtml.'<a class="btn" href="index.php?page=home">হোমে ফিরুন</a><a class="btn secondary" href="javascript:history.back()">আগের পৃষ্ঠা</a><p><small>সমস্যা থেকে গেলে Reference ID-টি সাপোর্টে জানান।</small></p></main></body></html>';exit;
}
set_exception_handler(static function(Throwable $e):void {
    while(ob_get_level()>0) @ob_end_clean();
    $reference=praan_error_reference($e);
    // Prevent duplicate error references: render a friendly page after private logging.
    http_response_code(500);header('Cache-Control: no-store, private');
    echo '<!doctype html><html lang="bn"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PRAAN · 500</title><body style="font:17px system-ui;background:#edf4f6;color:#17374e;text-align:center;padding:12vh 5%"><h1>একটি অপ্রত্যাশিত সমস্যা হয়েছে</h1><p>প্রযুক্তিগত ত্রুটিটি ব্যক্তিগত সার্ভার লগে সংরক্ষিত হয়েছে। সাপোর্টে এই Reference জানান: <code>'.$reference.'</code></p><a href="index.php?page=home">PRAAN হোম</a></body></html>';
});
register_shutdown_function(static function():void {
    $last=error_get_last();
    if(!$last||!in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
    if(headers_sent())return;
    while(ob_get_level()>0)@ob_end_clean();
    $reference=praan_error_reference(new Error('PHP fatal at '.basename($last['file']??'').':'.($last['line']??0)));
    http_response_code(500);
    echo '<!doctype html><html lang="bn"><meta charset="utf-8"><title>PRAAN · 500</title><body style="font:17px system-ui;text-align:center;padding:12vh 5%"><h1>সার্ভারে ত্রুটি হয়েছে</h1><p>Error Reference: <code>'.$reference.'</code> — সাপোর্টে জানান।</p><a href="index.php?page=home">PRAAN হোম</a></body></html>';
});
