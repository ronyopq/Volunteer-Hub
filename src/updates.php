<?php
declare(strict_types=1);
require_once __DIR__ . '/update_engine.php';
/** Public GitHub release metadata only. No code is downloaded/executed or installed. */
function valid_github_repository(string $repo): bool {
    return (bool)preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$~D', $repo);
}
function check_github_latest_release(string $repo): array {
    if (!valid_github_repository($repo)) return ['error'=>'Repository ঠিকানা সঠিক নয়।'];
    if (!function_exists('curl_init')) return ['error'=>'PHP cURL extension সক্রিয় নেই। হোস্টিংয়ে cURL চালু করুন।'];
    $url = 'https://api.github.com/repos/'.$repo.'/releases/latest';
    $ch = curl_init($url);
    if ($ch === false) return ['error'=>'Release checker চালু করা যায়নি।'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>9,
        CURLOPT_USERAGENT=>'PRAAN-Volunteer-Update-Checker/'.APP_VERSION,
        CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_MAXREDIRS=>0,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['error'=>'GitHub-এ সংযোগ হয়নি: '.mb_safe_limit($error,100)];
    if ($status !== 200) {
        return ['error'=>$status === 404 ? 'এই repository-তে প্রকাশিত Release পাওয়া যায়নি।' : 'GitHub API যাচাই করা যায়নি (HTTP '.$status.').'];
    }
    if (strlen($body) > 150000) return ['error'=>'Release response অস্বাভাবিক বড়।'];
    $data = json_decode($body,true);
    if (!is_array($data) || !isset($data['tag_name']) || !is_string($data['tag_name'])) return ['error'=>'GitHub Release-এর তথ্য অসঙ্গত।'];
    $tag = $data['tag_name'];
    if (!preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/D', $tag, $m)) return ['error'=>'Release tag semantic version নয় (যেমন v1.2.0)।'];
    $version = $m[1];
    return [
        'version'=>$version,
        'tag'=>$tag,
        'newer'=>version_compare($version, APP_VERSION, '>'),
        'notes'=>mb_safe_limit((string)($data['body'] ?? ''), 3500),
        'published_at'=>mb_safe_limit((string)($data['published_at'] ?? ''), 30),
        'url'=>'https://github.com/'.$repo.'/releases/tag/'.rawurlencode($tag),
        'assets'=>array_reduce(is_array($data['assets']??null)?$data['assets']:[],static function(array $carry,mixed $item):array {
            if(is_array($item)&&isset($item['name'],$item['browser_download_url']) && is_string($item['name']) && is_string($item['browser_download_url'])) $carry[$item['name']]=$item;
            return $carry;
        },[]),
    ];
}
function update_checklist(): array {
    return [
        ['PHP 8.1+', version_compare(PHP_VERSION,'8.1.0','>='), PHP_VERSION],
        ['PDO SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite')?'সক্রিয়':'সক্রিয় নয়'],
        ['Fileinfo (ছবি যাচাই)', extension_loaded('fileinfo'),extension_loaded('fileinfo')?'সক্রিয়':'সক্রিয় নয়'],
        ['zlib (Excel এক্সপোর্ট)', extension_loaded('zlib'),extension_loaded('zlib')?'সক্রিয়':'সক্রিয় নয়'],
        ['cURL (GitHub যাচাই)', extension_loaded('curl'),extension_loaded('curl')?'সক্রিয়':'ঐচ্ছিক · নিষ্ক্রিয়'],
        ['Storage লেখার অনুমতি', is_writable(dirname(DATA_PATH)),is_writable(dirname(DATA_PATH))?'লেখা যাচ্ছে':'ফোল্ডারের permission পরীক্ষা করুন'],
    ];
}
function render_update_center(): void {
    require_admin();
    $repo=trim(setting('github_repo','ronyopq/Volunteer-Hub'));
    $manualCheck=(string)($_GET['check']??'')==='1';
    $githubStatus=praan_github_cached_status();
    $release=$repo!==''&&($githubStatus['repo']??'')===$repo ? ($githubStatus['release']??null) : null;
    $backups=um_backup_names();$maintenance=um_maintenance();$checks=um_prerequisites();$transaction=praan_tx_read();
    $assetNames=is_array($release['assets']??null)?array_keys($release['assets']):[];
    $expected=empty($release['version'])||!version_compare((string)$release['version'],APP_VERSION,'>')?[]:['pran-update-v'.$release['version'].'.zip','pran-update-v'.$release['version'].'.zip.sha256','pran-update-v'.$release['version'].'.zip.sig'];
    $signedReady=count($expected)===3 && count(array_diff($expected,$assetNames))===0;
    $canInstall=praan_is_super() && !(bool)array_filter($checks,fn($c)=>!$c[1]) && $signedReady;
    $notes=is_file(UM_ROOT.'/CHANGELOG.md')?(string)file_get_contents(UM_ROOT.'/CHANGELOG.md'):'';
    $notes=mb_safe_limit($notes,4200);
    $gitHead=$githubStatus['head']['sha']??null;
    page_start('Site Update Center','updates',true);
    ?>
    <div class="updates-hero">
       <div><span class="eyebrow">SIGNED UPDATES · SAFE BACKUP · RESTORE</span><h2>PRAAN নিরাপদ আপডেট কেন্দ্র</h2><p>নতুন Release জানা, ডাটাবেইস ও ছবিসহ যাচাইকৃত ব্যাকআপ, Maintenance Mode, নিরাপদ One-Click Install এবং কোড Rollback।</p></div>
       <div class="updates-version"><small>ইনস্টল করা সংস্করণ</small><strong>v<?=e(APP_VERSION)?></strong><span><?=!empty($maintenance['enabled'])?'Maintenance ON':'System running'?></span></div>
    </div>
    <?php if(!empty($maintenance['enabled'])):?><div class="alert alert-warning mt-3"><i class="bi bi-cone-striped"></i> <strong>Maintenance Mode চালু।</strong> পাবলিক নিবন্ধন বন্ধ। প্রয়োজন হলে এখান থেকে বন্ধ করুন।</div><?php endif;?>
    <div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-outline-brand" href="<?=e(url('system_doctor'))?>"><i class="bi bi-heart-pulse"></i> System Doctor / Health Check</a><a class="btn btn-outline-secondary" href="<?=e(url('system_doctor_export'))?>"><i class="bi bi-download"></i> Diagnostics JSON</a></div>
    <?php if(in_array((string)($transaction['state']??''),['installing','rollback_failed','migration_failed'],true)):?>
      <div class="alert alert-danger mt-3"><strong>Interrupted Update detected.</strong> Backup: <?=e($transaction['backup']??'—')?> · State: <?=e($transaction['state']??'')?><br>Recovery চালানোর আগে বর্তমান কোড ও ডাটার পৃথক কপি রাখুন।
      <form method="post" action="<?=e(url('update_recover'))?>" class="row g-2 align-items-end mt-2" onsubmit="return confirm('আপনি কি Verified Restore করতে চান?')"><?=csrf_field()?>
        <div class="col-md-4"><label class="form-label">Super Admin Password</label><input type="password" name="password" class="form-control" required autocomplete="current-password"></div>
        <div class="col-md-4"><label class="form-label">RECOVER লিখুন</label><input name="confirmation" class="form-control" required pattern="RECOVER"></div>
        <div class="col-md-4"><button class="btn btn-danger" type="submit" <?=praan_is_super()?'':'disabled'?>>Recover Previous Code</button></div>
      </form></div>
    <?php endif;?>
    <div class="updates-grid mt-3">
      <section class="card dashboard-card"><div class="card-header"><h3 class="card-title"><i class="bi bi-github"></i> ১. GitHub Release ও Notification</h3></div><div class="card-body">
          <p class="text-muted small">Public GitHub repository দিন। Dashboard প্রতি ১২ ঘণ্টায় (সর্বোচ্চ) নতুন স্থিতিশীল সংস্করণ যাচাই করবে এবং আপডেট থাকলে নোটিফিকেশন দেখাবে।</p>
          <form method="post" action="<?=e(url('update_repo_save'))?>" class="updates-repo-form"><?=csrf_field()?>
             <label class="form-label" for="repoValue">GitHub owner/repository</label><input id="repoValue" class="form-control" name="github_repo" value="<?=e($repo)?>" placeholder="ronyopq/Volunteer-Hub" autocomplete="off" maxlength="145">
             <button class="btn btn-brand" type="submit">সংরক্ষণ</button>
          </form>
          <a class="btn btn-outline-brand btn-sm mt-3" href="<?=e(url('updates',['check'=>'1']))?>"><i class="bi bi-arrow-clockwise"></i> এখন যাচাই</a>
          <?php if(!empty($githubStatus['checked_at'])):?><div class="small text-muted mt-2">সর্বশেষ পরীক্ষা: <?=e(date('d M Y, h:i A',(int)$githubStatus['checked_at']))?> · main commit: <code><?=e(is_string($gitHead)?substr($gitHead,0,10):'অজানা')?></code></div><?php endif;?>
          <?php if($repo!==''):?><a class="btn btn-outline-secondary btn-sm mt-3" href="<?=e('https://github.com/'.$repo.'/releases')?>" target="_blank" rel="noopener noreferrer">Release History</a><?php endif;?>
          <?php if($release):?><div class="alert <?=isset($release['error'])?'alert-warning':'alert-info'?> mt-3 mb-0">
            <?php if(isset($release['error'])):?><?=e($release['error'])?><?php else:?><?=!empty($release['newer'])?'নতুন সংস্করণ পাওয়া গেছে: v':'সর্বশেষ প্রকাশিত সংস্করণ: v'?><?=e($release['version'])?><small class="d-block mt-1">প্রকাশ: <?=e($release['published_at'])?></small><?php endif;?>
          </div><?php endif;?>
      </div></section>
      <section class="card dashboard-card"><div class="card-header"><h3 class="card-title"><i class="bi bi-shield-check"></i> ২. Server Preflight</h3></div><div class="card-body"><div class="updates-checks">
        <?php foreach($checks as [$label,$ok,$detail]):?><div><i class="bi <?=$ok?'bi-check-circle-fill text-success':'bi-exclamation-triangle text-warning'?>"></i><span><?=e($label)?></span><small><?=e($detail)?></small></div><?php endforeach;?>
      </div><p class="small text-muted mt-3">Signed One-Click Update কেবল সব শর্ত পূরণ হলেই চালু হবে। cPanel-এ PHP zip, sodium, curl এবং PDO SQLite সক্রিয় থাকতে হবে।</p>
      </div></section>
    </div>
    <div class="updates-grid mt-3">
      <section class="card dashboard-card"><div class="card-header"><h3 class="card-title"><i class="bi bi-key"></i> ৩. Release Signing Key</h3></div><div class="card-body">
      <?php if(um_key()):?><div class="alert alert-success">Trusted Ed25519 Public Key সেট করা আছে। Fingerprint: <code><?=e(substr(hash('sha256',um_key()),0,20))?></code></div><p class="text-muted small">Key বদলাতে হলে সরাসরি cPanel-এ <code>storage/update_signer.pub</code> পরিবর্তন করুন। আপডেট নিজে কোনো নতুন key অনুমোদন করতে পারে না।</p>
      <?php else:?><p class="text-muted small">আপনার অফিসলাইন release signer দিয়ে তৈরি public key এখানে একবার যুক্ত করুন। এটি ছাড়া দূরবর্তী ZIP ইনস্টল করা যাবে না। <strong>Private/secret key কখনো সার্ভারে আপলোড করবেন না।</strong></p>
        <form method="post" action="<?=e(url('update_key_save'))?>"><?=csrf_field()?>
          <label class="form-label">Ed25519 Public Key (base64)</label><textarea class="form-control mb-2" name="public_key" rows="2" required placeholder="Base64 encoded 32-byte public key"></textarea>
          <label class="form-label">বর্তমান অ্যাডমিন পাসওয়ার্ড</label><input class="form-control mb-2" name="password" type="password" required autocomplete="current-password">
          <button type="submit" class="btn btn-outline-brand btn-sm">Trusted Key সংরক্ষণ</button>
        </form>
      <?php endif;?>
      </div></section>
      <section class="card dashboard-card"><div class="card-header"><h3 class="card-title"><i class="bi bi-cone-striped"></i> ৪. Maintenance Mode</h3></div><div class="card-body">
        <p>চালু করলে পাবলিক নিবন্ধন, তথ্য সম্পাদনা ও CSV Import সাময়িকভাবে বন্ধ হবে। অ্যাডমিন লগইন ও আপডেট ফাংশন খোলা থাকবে।</p>
        <form method="post" action="<?=e(url('update_maintenance'))?>"><?=csrf_field()?>
          <input type="hidden" name="enabled" value="<?=!empty($maintenance['enabled'])?'0':'1'?>">
          <label class="form-label">অ্যাডমিন পাসওয়ার্ড</label><input type="password" name="password" class="form-control mb-2" required autocomplete="current-password">
          <button type="submit" class="btn <?=!empty($maintenance['enabled'])?'btn-success':'btn-outline-warning'?>"><?=!empty($maintenance['enabled'])?'Maintenance বন্ধ করুন':'Maintenance চালু করুন'?></button>
        </form>
      </div></section>
    </div>
    <div class="updates-grid mt-3">
      <section class="card dashboard-card"><div class="card-header"><h3 class="card-title"><i class="bi bi-cloud-download"></i> ৫. Verified Backup & Restore</h3></div><div class="card-body">
        <p>SQLite-এর live-safe snapshot, ছবি এবং বর্তমান প্রোগ্রাম ফাইল এক ZIP-এ সংরক্ষিত হবে। ZIP-এর প্রতিটি ফাইলের SHA-256 ও SQLite integrity_check যাচাই করা হয়।</p>
        <form method="post" action="<?=e(url('update_backup_create'))?>"><?=csrf_field()?><button type="submit" class="btn btn-brand"><i class="bi bi-database-down"></i> নতুন Verified Backup</button></form>
        <p class="small text-muted mt-2">ব্যাকআপ কেবল সার্ভারের সুরক্ষিত <code>storage/update_backups/</code> ফোল্ডারে থাকে; প্রয়োজন হলে নিচ থেকে নামিয়ে অফলাইনে রাখুন।</p>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Snapshot</th><th>আকার</th><th>কাজ</th></tr></thead><tbody>
        <?php foreach($backups as $b):?><tr><td><code><?=e($b['name'])?></code></td><td><?=e(number_format($b['size']/1048576,2))?> MB</td><td class="d-flex flex-wrap gap-1">
            <a class="btn btn-outline-secondary btn-sm" href="<?=e(url('update_backup_download',['name'=>$b['name']]))?>"><i class="bi bi-download"></i> Download</a>
            <form method="post" action="<?=e(url('update_backup_verify'))?>"><?=csrf_field()?><input type="hidden" name="name" value="<?=e($b['name'])?>"><button class="btn btn-outline-brand btn-sm" type="submit">Verify</button></form>
        </td></tr><?php endforeach;if(!$backups):?><tr><td colspan="3" class="text-muted">এখনও কোনো ব্যাকআপ নেই।</td></tr><?php endif;?></tbody></table></div>
      </div></section>
      <section class="card dashboard-card"><div class="card-header"><h3 class="card-title"><i class="bi bi-arrow-repeat"></i> ৬. One-Click Signed Update</h3></div><div class="card-body">
        <p>GitHub-এর নির্দিষ্ট ZIP + SHA-256 + Ed25519 signature তিনটি মিলিয়ে যাচাই করে তবেই ইনস্টল শুরু হবে। তার আগে স্বয়ংক্রিয়ভাবে Verified Backup ও Maintenance Mode চালু হবে।</p>
        <p class="small text-muted">Release assets: <code>pran-update-vX.Y.Z.zip</code>, <code>.zip.sha256</code>, <code>.zip.sig</code></p>
        <form id="praanSignedUpdateForm" method="post" action="<?=e(url('update_install'))?>" onsubmit="return confirm('Signed release যাচাই, backup এবং update শুরু করবেন?')"><?=csrf_field()?>
            <label class="form-label">অ্যাডমিন পাসওয়ার্ড</label><input type="password" name="password" class="form-control mb-2" required autocomplete="current-password">
            <label class="form-label">নিশ্চিত করতে UPDATE লিখুন</label><input name="confirmation" class="form-control mb-2" pattern="UPDATE" required placeholder="UPDATE">
            <button type="submit" class="btn btn-brand" <?=$canInstall?'':'disabled'?>><i class="bi bi-arrow-clockwise"></i> Verify + Backup + Install</button>
        </form>
        <div class="update-live" id="praanUpdateLive" hidden aria-live="polite"><div class="d-flex justify-content-between"><span class="update-live-message">Update প্রস্তুত হচ্ছে</span><span id="praanUpdatePct">0%</span></div><div class="update-live-bar"><span id="praanUpdateFill"></span></div><div class="update-live-details" id="praanUpdateDetails">Progress poll চালু হচ্ছে...</div></div>
        <?php if(!$canInstall):?><div class="alert alert-warning small mt-3 mb-0">Super Admin, সব Server Preflight শর্ত এবং নতুন Release-এর তিনটি <strong>signed asset</strong> পাওয়া গেলে বোতাম চালু হবে। কেবল Git commit থাকলে নিরাপদ One-Click Install সম্ভব নয়।</div><?php endif;?>
      </div></section>
    </div>
    <section class="card dashboard-card mt-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-database-gear"></i> Additive Database Migrations</h3></div><div class="card-body">
      <p>নতুন SQL Migration শুধুমাত্র <strong>CREATE TABLE, CREATE INDEX, ADD COLUMN</strong> ধরনের non-destructive পরিবর্তন করতে পারবে। প্রতিটি Migration SHA-256 সহ history-তে থাকবে। কোনো ধাপে সমস্যা হলে SQLite Transaction rollback হবে।</p>
      <?php $catalog=[];$catalogError=null;try{$catalog=praan_migration_catalog();}catch(Throwable $e){$catalogError=$e->getMessage();}?>
      <?php if($catalogError!==null):?><div class="alert alert-danger"><?=e($catalogError)?></div><?php else:?><p class="small text-muted">Package Migration Files: <?=count($catalog)?> · <code><?=e(implode(', ',array_keys($catalog)))?></code></p><?php endif;?>
      <form method="post" action="<?=e(url('update_migrate'))?>" class="d-flex flex-wrap align-items-end gap-2" onsubmit="return confirm('Backup তৈরি করে additive migration চালু করবেন?')"><?=csrf_field()?><label>Super Admin Password<input class="form-control mt-1" type="password" name="password" required autocomplete="current-password"></label><button type="submit" class="btn btn-outline-brand" <?=praan_is_super()?'':'disabled'?>>Backup + Validate + Migrate</button></form>
      <p class="small text-muted mt-2 mb-0">নতুন সংস্করণ ইনস্টল করার পর প্রয়োজন হলে এই ধাপটি চালান। একই Migration দ্বিতীয়বার কার্যকর হবে না।</p>
    </div></section>
    <section class="card dashboard-card mt-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-clock-history"></i> ৭. Safe Code Rollback</h3></div><div class="card-body">
      <p>Rollback করলে আগের **কোড ফাইল** ফিরে আসবে। বর্তমান SQLite রেকর্ড ও ছবি <strong>অক্ষত থাকবে</strong>; নতুন নিবন্ধন হারাবে না। পূর্ববর্তী সংস্করণ ডাটাবেইস schema সমর্থন করে কি না তা নিশ্চিত করুন।</p>
      <form method="post" action="<?=e(url('update_rollback'))?>" onsubmit="return confirm('আপনি কি নিশ্চিত? এই অপারেশন আগের কোড ফিরিয়ে আনবে।')"><?=csrf_field()?>
        <div class="row g-2"><div class="col-lg-5"><label class="form-label">Verified Backup নির্বাচন</label><select name="name" class="form-select" required><option value="">Backup নির্বাচন করুন</option><?php foreach($backups as $b):?><option value="<?=e($b['name'])?>"><?=e($b['name'])?></option><?php endforeach;?></select></div>
        <div class="col-lg-4"><label class="form-label">অ্যাডমিন পাসওয়ার্ড</label><input type="password" name="password" class="form-control" autocomplete="current-password" required></div>
        <div class="col-lg-3"><label class="form-label">ROLLBACK লিখুন</label><input name="confirmation" class="form-control" pattern="ROLLBACK" required placeholder="ROLLBACK"></div></div>
        <button class="btn btn-outline-danger mt-3" type="submit" <?=empty($maintenance['enabled'])?'disabled':''?>><i class="bi bi-arrow-counterclockwise"></i> Code Rollback</button>
      </form><p class="small text-muted mt-2 mb-0">Rollback-এর আগে Maintenance Mode চালু থাকতে হবে। সার্ভারের বাইরে আলাদা ডাটা ব্যাকআপ রাখুন।</p>
    </div></section>
    <section class="card dashboard-card mt-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-journal-check"></i> ৮. Update History</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table modern-table mb-0"><thead><tr><th>সময় (UTC)</th><th>কর্মকর্তা ID</th><th>ঘটনা</th><th>বিবরণ</th></tr></thead><tbody>
      <?php foreach(um_history() as $h):?><tr><td><?=e($h['time']??'')?></td><td><?=e($h['user_id']??'—')?></td><td><?=e($h['action']??'')?></td><td><?=e($h['detail']??'')?></td></tr><?php endforeach;if(!um_history()):?><tr><td colspan="4" class="text-muted p-3">এখনো কোনো Update Event নেই।</td></tr><?php endif;?></tbody></table></div></div></section>
    <section class="card dashboard-card mt-3"><div class="card-header"><h3 class="card-title"><i class="bi bi-journal-text"></i> Local Changelog</h3></div><div class="card-body"><pre class="updates-changelog"><?=e($notes)?></pre></div></section>
    <script>
    (()=>{
      const form=document.getElementById('praanSignedUpdateForm');
      if(!form || !window.fetch)return;
      const box=document.getElementById('praanUpdateLive'),fill=document.getElementById('praanUpdateFill'),pct=document.getElementById('praanUpdatePct'),details=document.getElementById('praanUpdateDetails'),message=box?.querySelector('.update-live-message');
      form.addEventListener('submit',async(event)=>{
        // Respect cancellation from the inline confirmation handler.
        if(event.defaultPrevented)return;
        event.preventDefault();
        if(!box || !fill)return;
        box.hidden=false;form.querySelector('button[type=submit]').disabled=true;
        message.textContent='Signed Update শুরু হচ্ছে';
        const payload=new FormData(form);payload.set('async_update','1');
        let active=true;
        const poll=async()=>{
          if(!active)return;
          try{
            const response=await fetch(<?=json_encode(url('update_progress'))?>,{credentials:'same-origin',cache:'no-store'});
            if(!response.ok)return;
            const data=await response.json();
            const n=Math.max(0,Math.min(100,Number(data.percent)||0));
            fill.style.width=n+'%';pct.textContent=n+'%';message.textContent=data.message||'Update চলছে';details.textContent='Step: '+(data.step||'')+' · '+(data.state||'');
          }catch(_e){/* Poll failures never interrupt the update itself. */}
        };
        const ticker=setInterval(poll,1300);await poll();
        try{
          const result=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{'Accept':'application/json'}});
          const data=await result.json();active=false;clearInterval(ticker);await poll();
          if(!data.ok)throw new Error(data.message||'Update সফল হয়নি');
          fill.style.width='100%';pct.textContent='100%';message.textContent='Update সফল';details.textContent=data.message||'নতুন সংস্করণ Reload করুন';
          window.setTimeout(()=>window.location.reload(),1500);
        }catch(err){active=false;clearInterval(ticker);message.textContent='Update ব্যর্থ';details.textContent=(err instanceof Error?err.message:String(err))+' — প্রয়োজনে Recovery করুন';form.querySelector('button[type=submit]').disabled=false;}
      });
    })();
    </script>
    <?php page_end(true);
}

/** Accessible only to administrators with update-management permission. No credentials or volunteer data exposed. */
function render_system_doctor(PDO $pdo): void {
    require_admin();praan_require('updates.manage');
    $report=praan_doctor($pdo);
    $tx=praan_tx_read();$recent=um_history(6);
    page_start('System Doctor','system_doctor',true);
    ?>
    <div class="doctor-hero"><div><span class="eyebrow">PRAAN · SECURITY & OPERATIONS</span><h2><i class="bi bi-heart-pulse"></i> System Doctor</h2><p>PHP, SQLite, Storage, Backup, PWA ও Update Recovery-এর বর্তমান অবস্থা। সংবেদনশীল স্বেচ্ছাসেবকের তথ্য এখানে প্রকাশ হয় না।</p></div><div><strong>v<?=e(APP_VERSION)?></strong><small>System Diagnostics</small></div></div>
    <div class="doctor-metrics mt-3"><div><small>সফল পরীক্ষা</small><strong><?=e($report['passed'])?></strong></div><div><small>সমস্যা</small><strong class="text-danger"><?=e($report['failed'])?></strong></div><div><small>ঐচ্ছিক সতর্কতা</small><strong class="text-warning"><?=e($report['warnings'])?></strong></div><div><small>সর্বশেষ পরীক্ষা (UTC)</small><strong class="doctor-time"><?=e(substr($report['timestamp'],11,8))?></strong></div></div>
    <?php if(in_array((string)($tx['state']??''),['installing','rollback_failed','migration_failed'],true)):?><div class="alert alert-danger mt-3"><strong>Interrupted Update শনাক্ত হয়েছে।</strong> আপডেটের Transaction Journal অসমাপ্ত। Maintenance চালু রেখে Verified Restore করুন অথবা cPanel Backup ব্যবহার করুন। <a href="<?=e(url('updates'))?>">Update Center</a></div><?php endif;?>
    <div class="d-flex flex-wrap gap-2 my-3"><a class="btn btn-brand" href="<?=e(url('system_doctor'))?>"><i class="bi bi-arrow-clockwise"></i> আবার পরীক্ষা</a><a class="btn btn-outline-secondary" href="<?=e(url('system_doctor_export'))?>"><i class="bi bi-download"></i> Diagnostic JSON</a><a class="btn btn-outline-secondary" href="<?=e(url('updates'))?>"><i class="bi bi-shield-check"></i> Update Center</a></div>
    <?php foreach(['Server','Database','Storage','Security','Recovery','PWA'] as $category):?>
        <section class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title"><?=e($category)?></h3></div><div class="card-body p-0"><div class="doctor-checks">
        <?php foreach($report['checks'] as $item):if($item['group']!==$category)continue;
            $severity=$item['ok']?'ok':($item['kind']==='optional'?'warn':'fail');?>
            <div class="doctor-check"><span class="doctor-icon <?=$severity?>"><i class="bi <?=$item['ok']?'bi-check-lg':($severity==='warn'?'bi-exclamation-lg':'bi-x-lg')?>"></i></span><div><strong><?=e($item['name'])?></strong><small><?=e($item['detail'])?></small></div><span class="doctor-status <?=$severity?>"><?=$item['ok']?'OK':($severity==='warn'?'ADVISORY':'ACTION NEEDED')?></span></div>
        <?php endforeach;?></div></div></section>
    <?php endforeach;?>
    <section class="card dashboard-card mb-3"><div class="card-header"><h3 class="card-title">Recent Update Activity</h3></div><div class="card-body"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>UTC সময়</th><th>Event</th><th>বিবরণ</th></tr></thead><tbody>
    <?php foreach($recent as $event):?><tr><td><?=e($event['time']??'')?></td><td><code><?=e($event['action']??'')?></code></td><td><?=e($event['detail']??'')?></td></tr><?php endforeach;if(!$recent):?><tr><td colspan="3" class="text-muted">এখনো কোনো event নেই।</td></tr><?php endif;?></tbody></table></div></div></section>
    <p class="small text-muted">দ্রষ্টব্য: HTTPS শনাক্তকরণ reverse proxy-তে ভুল সতর্কতা দিতে পারে। .htaccess উপস্থিতি মানেই নিরাপত্তা নিশ্চিত নয়; storage URL সত্যিই 403/404 দেয় কি না লাইভ সার্ভারে যাচাই করুন।</p>
    <?php page_end(true);
}
