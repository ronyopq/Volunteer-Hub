<?php
declare(strict_types=1);

require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/media_upload.php';
date_default_timezone_set('Asia/Dhaka');
const APP_VERSION = '1.9.0';
const DATA_PATH = __DIR__ . '/../storage/pran.sqlite';
const PHOTO_DIR = __DIR__ . '/../storage/photos';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('pran_session');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

function db(): PDO {
    static $connection = null;
    if ($connection !== null) return $connection;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('<!doctype html><meta charset="utf-8"><style>body{font:17px Arial;padding:45px;max-width:750px;margin:auto}</style><h1>SQLite Extension প্রয়োজন</h1><p>এই PHP সার্ভারে <code>pdo_sqlite</code> চালু নেই। PHP 8.1+ এবং PDO SQLite extension চালু করে আবার চেষ্টা করুন। আলাদা MySQL বা Composer লাগবে না।</p>');
    }
    if (!is_dir(dirname(DATA_PATH)) && !@mkdir(dirname(DATA_PATH), 0750, true)) {
        http_response_code(503);
        exit('<!doctype html><html lang="bn"><meta charset="utf-8"><title>Storage permission</title><body style="font:18px sans-serif;padding:30px"><h2>ডাটাবেইস ফোল্ডার তৈরি করা যাচ্ছে না</h2><p>হোস্টিংয়ের File Manager থেকে storage/ ফোল্ডারের write permission যাচাই করুন।</p></body></html>');
    }
    try {
        $connection = new PDO('sqlite:' . DATA_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $exception) {
        error_log('PRAN SQLite connect: ' . $exception->getMessage());
        http_response_code(503);
        exit('<!doctype html><html lang="bn"><meta charset="utf-8"><title>SQLite unavailable</title><body style="font:18px sans-serif;padding:30px"><h2>SQLite ডাটাবেইস খোলা যাচ্ছে না</h2><p>storage/ ফোল্ডারে লেখার অনুমতি এবং pdo_sqlite সক্রিয় আছে কি না যাচাই করুন।</p></body></html>');
    }
    $connection->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    $connection->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        display_name TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS volunteers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        volunteer_code TEXT UNIQUE,
        form_no TEXT,
        name_bn TEXT,
        name_en TEXT,
        phone TEXT,
        district TEXT,
        gender TEXT,
        blood_group TEXT,
        project TEXT,
        status TEXT NOT NULL DEFAULT 'অপেক্ষমাণ',
        category TEXT,
        public_visible INTEGER NOT NULL DEFAULT 0,
        source_type TEXT NOT NULL DEFAULT 'public',
        registered_on TEXT,
        photo_file TEXT,
        payload TEXT NOT NULL,
        receipt_hash TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT,
        FOREIGN KEY(created_by) REFERENCES users(id)
    );
    CREATE INDEX IF NOT EXISTS idx_volunteer_lookup ON volunteers(status, public_visible, deleted_at);
    CREATE INDEX IF NOT EXISTS idx_volunteer_district ON volunteers(district, deleted_at);
    CREATE INDEX IF NOT EXISTS idx_volunteer_phone ON volunteers(phone);
    CREATE INDEX IF NOT EXISTS idx_volunteer_date ON volunteers(created_at);
    CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        volunteer_id INTEGER,
        action TEXT NOT NULL,
        details TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS rate_limits (
        key_hash TEXT PRIMARY KEY,
        action TEXT NOT NULL,
        window_started INTEGER NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    SQL);

    // Additive migrations only: never rename/drop existing volunteer fields or receipts.
    foreach (['volunteers'=>['uid'=>'TEXT','draft_uid'=>'TEXT'], 'users'=>['uid'=>'TEXT']] as $table=>$columns) {
        $known = $connection->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        $names = array_column($known, 'name');
        foreach($columns as $name=>$type) if (!in_array($name,$names,true)) {
            try {$connection->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);}
            catch(PDOException $e){ if(!str_contains($e->getMessage(),'duplicate column'))throw $e; }
        }
    }
    $connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_vol_uid ON volunteers(uid); CREATE UNIQUE INDEX IF NOT EXISTS idx_vol_draft_uid ON volunteers(draft_uid); CREATE UNIQUE INDEX IF NOT EXISTS idx_users_uid ON users(uid);');
    foreach (['volunteers'=>'VOL', 'users'=>'USR'] as $table=>$prefix) {
        $missing=$connection->query('SELECT id FROM '.$table.' WHERE uid IS NULL LIMIT 300')->fetchAll(PDO::FETCH_COLUMN);
        $update=$connection->prepare('UPDATE '.$table.' SET uid=? WHERE id=? AND uid IS NULL');
        foreach($missing as $item)$update->execute([praan_uid($prefix),(int)$item]);
    }
    $connection->exec("INSERT OR IGNORE INTO settings(key,value) VALUES ('accent','#168b8b'),('organization','পার্টিসিপেটরি রিসার্চ অ্যান্ড অ্যাকশন নেটওয়ার্ক - প্রান')");
    media_migrate($connection);
    praan_auth_migrate($connection);
    praan_module_migrate($connection);
    return $connection;
}
function setting(string $name, string $fallback = ''): string {
    $q = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $q->execute([$name]);
    $result = $q->fetchColumn();
    return $result === false ? $fallback : (string)$result;
}
function set_setting(string $name, string $value): void {
    db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$name,$value]);
}
function e(mixed $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page, array $params = []): string { return 'index.php?' . http_build_query(array_merge(['page'=>$page], $params), '', '&', PHP_QUERY_RFC3986); }
function redirect(string $page, array $params = []): never { header('Location: ' . url($page, $params), true, 303); exit; }
function admin(): bool { return isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0; }
function require_admin(): void { if (!admin()) redirect('login'); }
function admin_id(): ?int { return admin() ? (int)$_SESSION['admin_id'] : null; }
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('সেশন মেয়াদ শেষ বা নিরাপত্তা টোকেন সঠিক নয়। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
    }
}
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type' => $type, 'message' => $message]; }
function consume_flashes(): array { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }
function have_admin(): bool { return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }
function ip_throttle(string $action, int $limit, int $period = 3600): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $hash = hash('sha256', $action . '|' . $ip);
    $now = time();
    $pdo = db();
    $q = $pdo->prepare('SELECT window_started, attempts FROM rate_limits WHERE key_hash=?');
    $q->execute([$hash]); $old = $q->fetch();
    $attempts = $old && ($now - (int)$old['window_started']) < $period ? (int)$old['attempts'] : 0;
    if ($attempts >= $limit) return false;
    $started = $attempts === 0 ? $now : (int)$old['window_started'];
    $pdo->prepare('INSERT INTO rate_limits(key_hash,action,window_started,attempts) VALUES(?,?,?,?) ON CONFLICT(key_hash) DO UPDATE SET window_started=excluded.window_started,attempts=excluded.attempts')->execute([$hash,$action,$started,$attempts+1]);
    return true;
}
function fetch_volunteer(int $id): ?array {
    $q = db()->prepare('SELECT * FROM volunteers WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $q->execute([$id]); $r = $q->fetch();
    if (!$r) return null;
    $payload = json_decode((string)$r['payload'], true);
    $r['data'] = array_replace(default_data(), is_array($payload) ? $payload : []);
    return $r;
}
function can_view_volunteer(array $v): bool {
    if (admin()) return praan_can('volunteers.view');
    $token = (string)($_GET['token'] ?? '');
    return strlen($token) === 48 && !empty($v['receipt_hash']) && hash_equals((string)$v['receipt_hash'], hash('sha256', $token));
}
function log_action(?int $volunteerId, string $action, string $details = ''): void {
    db()->prepare('INSERT INTO activity_log(user_id,volunteer_id,action,details) VALUES(?,?,?,?)')->execute([admin_id(),$volunteerId,$action,$details]);
}
function save_volunteer(array $data, ?array $old, string $source, int $visible, string $photo, string $receiptHash = ''): int {
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $fields = [
        'form_no' => (string)$data['form_no'], 'name_bn' => (string)$data['name_bn'], 'name_en' => (string)$data['name_en'],
        'phone' => (string)$data['phone'], 'district' => (string)$data['present_district'],
        'gender' => (string)$data['gender'], 'blood_group' => (string)$data['blood_group'],
        'project' => (string)$data['project'], 'status' => (string)($data['status'] ?: 'অপেক্ষমাণ'),
        'category' => (string)$data['category'], 'public_visible' => $visible, 'source_type' => $source,
        'registered_on' => (string)($data['registration_date'] ?: date('Y-m-d')),
        'photo_file' => $photo, 'payload' => $payload,
    ];
    $pdo = db();
    if ($old) {
        $set = implode(', ', array_map(fn($field) => "$field=:$field", array_keys($fields)));
        $q = $pdo->prepare("UPDATE volunteers SET $set, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND deleted_at IS NULL");
        if(empty($old['draft_uid']) && praan_uid_valid((string)($data['draft_uid']??''),'DRF')) $fields['draft_uid']=(string)$data['draft_uid'];
        $fields['id'] = (int)$old['id']; $q->execute($fields);
        log_action((int)$old['id'], 'edit');
        return (int)$old['id'];
    }
    $fields['uid'] = praan_uid('VOL');
    $fields['draft_uid'] = praan_uid_valid((string)($data['draft_uid']??''),'DRF') ? (string)$data['draft_uid'] : null;
    $fields['receipt_hash'] = $receiptHash;
    $fields['created_by'] = admin_id();
    $keys = array_keys($fields);
    $q = $pdo->prepare('INSERT INTO volunteers (' . implode(',', $keys) . ') VALUES (:' . implode(',:', $keys) . ')');
    $q->execute($fields);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE volunteers SET volunteer_code=? WHERE id=?')->execute([$fields['uid'], $id]);
    log_action($id, 'created', $source);
    return $id;
}
function store_photo(?array $uploaded, string $previous = ''): string {
    if (!$uploaded || (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $previous;
    if ((int)$uploaded['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('ছবি আপলোড সম্ভব হয়নি।');
    if ((int)$uploaded['size'] > (int)setting('max_photo_mb','2') * 1024 * 1024) throw new RuntimeException('ছবির সাইজ অনুমোদিত সীমার বাইরে।');
    $temp = (string)$uploaded['tmp_name'];
    if (!is_uploaded_file($temp)) throw new RuntimeException('অবৈধ ফাইল আপলোড।');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temp);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
    if (!$ext || getimagesize($temp) === false) throw new RuntimeException('শুধু JPG, PNG বা WebP ছবি আপলোড করুন।');
    // Prefer the same EXIF-cleaning/auto-rotation pipeline as resumable uploads.
    // Without GD, preserve the legacy direct upload fallback.
    if(extension_loaded('gd')) return media_convert($temp,hash_file('sha256',$temp));
    if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0750, true);
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($temp, PHOTO_DIR . '/' . $filename)) throw new RuntimeException('ছবি সেভ করা যায়নি।');
    return $filename;
}
function display_value(mixed $value): string {
    if (is_array($value)) return implode(', ', array_map(fn($x) => is_array($x) ? implode(' | ', $x) : (string)$x, $value));
    return trim((string)($value ?? ''));
}
function age_from_date(string $dob): string {
    if (!valid_date($dob)) return '';
    $date = new DateTimeImmutable($dob);
    $now = new DateTimeImmutable('today');
    return $date > $now ? '' : (string)$date->diff($now)->y;
}

require_once __DIR__ . '/authz.php';
require_once __DIR__ . '/module_manager.php';
