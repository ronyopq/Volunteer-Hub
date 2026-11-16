<?php
declare(strict_types=1);
/**
 * OFFLINE CLI ONLY. NEVER place a private key inside a web-accessible project.
 *
 * php tools/release_signer.php keygen /absolute/private/output
 * php tools/release_signer.php package /absolute/private/output/secret.key /absolute/release/output
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/update_engine.php';
function fail(string $message): never {fwrite(STDERR,"ERROR: $message\n");exit(1);}
if(!function_exists('sodium_crypto_sign_keypair'))fail('PHP sodium extension required.');
$task=$argv[1]??'';
if($task==='keygen'){
    $dir=(string)($argv[2]??'');
    if($dir===''||!str_starts_with($dir,'/'))fail('Use an absolute path OUTSIDE the web project for secret keys.');
    $realProject=realpath(__DIR__.'/..');
    if(str_starts_with($dir,$realProject))fail('Private key cannot be saved in the website folder.');
    if(!is_dir($dir)&&!@mkdir($dir,0700,true))fail('Cannot create key directory.');
    if(is_file($dir.'/secret.key'))fail('A secret key already exists. Aborting to avoid overwriting the trusted signer.');
    $kp=sodium_crypto_sign_keypair();
    $secret=base64_encode(sodium_crypto_sign_secretkey($kp));
    $pub=base64_encode(sodium_crypto_sign_publickey($kp));
    if(file_put_contents($dir.'/secret.key',$secret."\n",LOCK_EX)===false)fail('Failed to save private key.');
    chmod($dir.'/secret.key',0600);
    file_put_contents($dir.'/public.key',$pub."\n",LOCK_EX);
    echo "Created offline signing keys.\nPublic Key (paste into Update Center):\n$pub\n";
    exit;
}
if($task==='package'){
    if(!class_exists('ZipArchive'))fail('PHP Zip extension required to package signed releases.');
    $secretPath=(string)($argv[2]??'');$out=(string)($argv[3]??'');
    if(!is_file($secretPath)||$out==='')fail('Usage: package /path/outside/web/secret.key /path/to/dist');
    $private=base64_decode(trim((string)file_get_contents($secretPath)),true);
    if(!is_string($private)||strlen($private)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)fail('Invalid secret key.');
    if(!is_dir($out)&&!@mkdir($out,0750,true))fail('Cannot create release directory.');
    $name='pran-update-v'.APP_VERSION.'.zip';$path=rtrim($out,'/').'/'.$name;
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)fail('Cannot create release ZIP.');
    $listed=[];
    foreach(um_code_paths() as $rel){
        $file=UM_ROOT.'/'.$rel;
        if(!is_file($file)||!um_valid_code_path($rel))fail('Invalid source file: '.$rel);
        if(filesize($file)>UM_MAX_FILE)fail('File too large for update: '.$rel);
        if(!$zip->addFile($file,$rel))fail('Failed to archive: '.$rel);
        $listed[$rel]=hash_file('sha256',$file);
    }
    $manifest=['format'=>'PRAN-UPDATE-1','version'=>APP_VERSION,'created_at'=>gmdate('c'),'files'=>$listed];
    $zip->addFromString('update-manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    if(!$zip->close())fail('Cannot finalize ZIP.');
    if(filesize($path)>UM_MAX_ARCHIVE)fail('Release ZIP exceeds 25 MiB limit.');
    $sha=hash_file('sha256',$path);
    $signature=sodium_crypto_sign_detached("PRAN-UPDATE\0".hex2bin($sha),$private);
    file_put_contents($path.'.sha256',$sha.'  '.$name."\n",LOCK_EX);
    file_put_contents($path.'.sig',base64_encode($signature)."\n",LOCK_EX);
    echo "Signed release ready:\n $path\n $path.sha256\n $path.sig\nSHA-256: $sha\nPublish all three files in a public GitHub Release tagged v".APP_VERSION."\n";
    exit;
}
fwrite(STDERR,"Offline release signer\nUsage:\n php tools/release_signer.php keygen /absolute/private/directory\n php tools/release_signer.php package /absolute/private/directory/secret.key /absolute/dist\n");exit(2);
