<?php
/*
 * Agitech Seedlings: storage backend.
 * Keeps the catalogue, uploaded photos, orders, messages and subscribers on this server,
 * so every visitor sees the same site on every browser and device.
 * Upload this file next to index.html. Folders "data" and "uploads" are created automatically.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Africa/Nairobi');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_JSON = 12 * 1024 * 1024;   // largest request body
const MAX_IMG  = 8 * 1024 * 1024;    // largest single photo
const MAX_CAT  = 3 * 1024 * 1024;    // largest catalogue file
const INBOX_CAP = 5000;

$DATA = __DIR__ . '/data';
$UP   = __DIR__ . '/uploads';

function out($a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
set_exception_handler(function (Throwable $e) { out(['ok' => false, 'error' => 'Server error. Check that the folder is writable.'], 500); });

function ensure_dirs(): void {
    global $DATA, $UP;
    foreach ([$DATA, $UP] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0755, true)) out(['ok' => false, 'error' => 'Cannot create folder ' . basename($d) . '. Make the site folder writable.'], 500);
    }
    if (!is_file("$DATA/.htaccess")) {
        @file_put_contents("$DATA/.htaccess", "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents("$DATA/index.html", '');
    }
    if (!is_file("$UP/.htaccess")) {
        @file_put_contents("$UP/.htaccess", "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$\">\nRequire all denied\n</FilesMatch>\n");
        @file_put_contents("$UP/index.html", '');
    }
    if (!is_writable($DATA) || !is_writable($UP)) out(['ok' => false, 'error' => 'The data and uploads folders are not writable.'], 500);
}

function rjson(string $f, $default) {
    if (!is_file($f)) return $default;
    $j = json_decode((string)file_get_contents($f), true);
    return $j === null ? $default : $j;
}
function wjson(string $f, $d): bool {
    $t = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    return rename($t, $f);
}
function locked(callable $fn) {
    global $DATA;
    $fp = fopen("$DATA/.lock", 'c');
    flock($fp, LOCK_EX);
    try { return $fn(); } finally { flock($fp, LOCK_UN); fclose($fp); }
}
function body(): array {
    $raw = file_get_contents('php://input', false, null, 0, MAX_JSON + 1);
    if ($raw === false || strlen($raw) > MAX_JSON) out(['ok' => false, 'error' => 'Request too large'], 413);
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function need_post(): void { if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'error' => 'POST required'], 405); }
function clean($v, int $max = 500): string {
    $s = is_scalar($v) ? trim((string)$v) : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}

/* ---------- rate limiting and auth ---------- */
function rate_ok(string $bucket, int $max, int $window): bool {
    global $DATA;
    return locked(function () use ($bucket, $max, $window, $DATA) {
        $f = "$DATA/rate.json"; $now = time();
        $r = rjson($f, []);
        foreach ($r as $k => $list) { $r[$k] = array_values(array_filter($list, fn($t) => $t > $now - 3600)); if (!$r[$k]) unset($r[$k]); }
        $k = $bucket . '|' . ($_SERVER['REMOTE_ADDR'] ?? '?');
        $recent = array_filter($r[$k] ?? [], fn($t) => $t > $now - $window);
        if (count($recent) >= $max) { wjson($f, $r); return false; }
        $r[$k][] = $now; wjson($f, $r);
        return true;
    });
}
function rate_clear(string $bucket): void {
    global $DATA;
    locked(function () use ($bucket, $DATA) {
        $f = "$DATA/rate.json"; $r = rjson($f, []);
        unset($r[$bucket . '|' . ($_SERVER['REMOTE_ADDR'] ?? '?')]); wjson($f, $r);
    });
}
function auth_file(): string { global $DATA; return "$DATA/auth.json"; }
function new_token(): string {
    $tok = bin2hex(random_bytes(32));
    locked(function () use ($tok) {
        $a = rjson(auth_file(), []); $now = time();
        $t = array_filter($a['tokens'] ?? [], fn($e) => $e > $now);
        $t[hash('sha256', $tok)] = $now + 12 * 3600;
        $a['tokens'] = $t; wjson(auth_file(), $a);
    });
    return $tok;
}
function is_admin(): bool {
    $tok = $_SERVER['HTTP_X_AUTH'] ?? '';
    if (!is_string($tok) || strlen($tok) < 32) return false;
    $a = rjson(auth_file(), []);
    $h = hash('sha256', $tok);
    foreach (($a['tokens'] ?? []) as $k => $exp) if (hash_equals((string)$k, $h) && $exp > time()) return true;
    return false;
}
function need_admin(): void { if (!is_admin()) out(['ok' => false, 'error' => 'Please sign in again', 'auth' => false], 401); }

/* ---------- images ---------- */
function save_image(string $bin): ?string {
    global $UP;
    if (strlen($bin) > MAX_IMG || strlen($bin) < 100) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info) return null;
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2]] ?? null;
    if (!$ext) return null;
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents("$UP/$name", $bin) === false) return null;
    return 'uploads/' . $name;
}
function convert_uris($v, int &$n) {
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = convert_uris($x, $n); return $v; }
    if (is_string($v) && strncmp($v, 'data:image/', 11) === 0) {
        if (preg_match('#^data:image/(png|jpe?g|webp|gif);base64,(.+)$#s', $v, $m)) {
            $bin = base64_decode($m[2], true);
            if ($bin !== false && ($u = save_image($bin))) { $n++; return $u; }
        }
        return '';
    }
    return $v;
}

/* ---------- routing ---------- */
ensure_dirs();
$a = $_GET['a'] ?? '';
$dataFile = "$DATA/data.json";
$inboxFile = "$DATA/inbox.json";
function inbox(): array { global $inboxFile; $i = rjson($inboxFile, []); foreach (['orders', 'subs', 'messages'] as $k) if (!isset($i[$k]) || !is_array($i[$k])) $i[$k] = []; return $i; }

switch ($a) {
case 'data': {
    $d = rjson($dataFile, null);
    out(['ok' => true, 'srv' => 'agitech', 'configured' => is_file(auth_file()), 'rev' => (int)($d['rev'] ?? 0), 'data' => $d['data'] ?? null]);
}
case 'setup': {
    need_post();
    $b = body(); $pw = (string)($b['password'] ?? '');
    if (strlen($pw) < 8) out(['ok' => false, 'error' => 'Use at least 8 characters'], 400);
    $res = locked(function () use ($pw) {
        if (is_file(auth_file())) return false;
        wjson(auth_file(), ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'tokens' => []]);
        return true;
    });
    if (!$res) out(['ok' => false, 'error' => 'Admin password is already set'], 403);
    out(['ok' => true, 'token' => new_token()]);
}
case 'login': {
    need_post();
    if (!rate_ok('login', 8, 900)) out(['ok' => false, 'error' => 'Too many attempts. Try again in 15 minutes.'], 429);
    $b = body(); $pw = (string)($b['password'] ?? '');
    $au = rjson(auth_file(), []);
    if (!isset($au['hash']) || !password_verify($pw, $au['hash'])) { usleep(600000); out(['ok' => false, 'error' => 'Incorrect password'], 401); }
    rate_clear('login');
    out(['ok' => true, 'token' => new_token()]);
}
case 'logout': {
    need_post();
    $tok = $_SERVER['HTTP_X_AUTH'] ?? '';
    if ($tok) locked(function () use ($tok) {
        $au = rjson(auth_file(), []); unset($au['tokens'][hash('sha256', $tok)]); wjson(auth_file(), $au);
    });
    out(['ok' => true]);
}
case 'setpass': {
    need_post(); need_admin();
    $b = body(); $cur = (string)($b['current'] ?? ''); $pw = (string)($b['password'] ?? '');
    if (strlen($pw) < 8) out(['ok' => false, 'error' => 'Use at least 8 characters'], 400);
    $au = rjson(auth_file(), []);
    if (!isset($au['hash']) || !password_verify($cur, $au['hash'])) out(['ok' => false, 'error' => 'Current password is wrong'], 403);
    locked(function () use ($pw) { $au = rjson(auth_file(), []); $au['hash'] = password_hash($pw, PASSWORD_DEFAULT); $au['tokens'] = []; wjson(auth_file(), $au); });
    out(['ok' => true, 'token' => new_token()]);
}
case 'save': {
    need_post(); need_admin();
    $b = body();
    if (!isset($b['data']) || !is_array($b['data'])) out(['ok' => false, 'error' => 'No data'], 400);
    $rev = (int)($b['rev'] ?? 0);
    $n = 0;
    $in = convert_uris($b['data'], $n);
    $clean = [];
    foreach (['banners', 'products', 'events', 'gallery', 'faq'] as $k) $clean[$k] = (isset($in[$k]) && is_array($in[$k])) ? array_values($in[$k]) : [];
    $clean['settings'] = (isset($in['settings']) && is_array($in['settings'])) ? $in['settings'] : [];
    unset($clean['settings']['adminPass']);
    $res = locked(function () use ($clean, $rev, $dataFile) {
        $cur = rjson($dataFile, null);
        $curRev = (int)($cur['rev'] ?? 0);
        if ($cur !== null && $curRev !== $rev) return ['conflict' => true];
        $payload = ['rev' => $curRev + 1, 'updated' => date('c'), 'data' => $clean];
        if (strlen((string)json_encode($payload)) > MAX_CAT) return ['toolarge' => true];
        if (!wjson($dataFile, $payload)) return ['fail' => true];
        return ['rev' => $curRev + 1];
    });
    if (!empty($res['conflict'])) out(['ok' => false, 'conflict' => true, 'error' => 'Someone else changed the site. Reload to continue.'], 409);
    if (!empty($res['toolarge'])) out(['ok' => false, 'error' => 'Catalogue is too large'], 413);
    if (!empty($res['fail'])) out(['ok' => false, 'error' => 'Could not write data'], 500);
    $r = ['ok' => true, 'rev' => $res['rev']];
    if ($n > 0) { $r['converted'] = $n; $r['data'] = $clean; }
    out($r);
}
case 'upload': {
    need_post(); need_admin();
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) out(['ok' => false, 'error' => 'No file received. The photo may be larger than the server allows.'], 400);
    if (($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => 'Upload failed (code ' . (int)$_FILES['file']['error'] . ')'], 400);
    $bin = file_get_contents($_FILES['file']['tmp_name']);
    $u = $bin === false ? null : save_image($bin);
    if (!$u) out(['ok' => false, 'error' => 'Please upload a JPG, PNG, WebP or GIF picture under 8 MB'], 415);
    out(['ok' => true, 'url' => $u]);
}
case 'inbox': {
    need_admin();
    out(['ok' => true] + inbox());
}
case 'inbox_op': {
    need_post(); need_admin();
    $b = body(); $op = (string)($b['op'] ?? ''); $col = (string)($b['col'] ?? 'orders'); $id = (string)($b['id'] ?? '');
    if (!in_array($col, ['orders', 'subs', 'messages'], true)) out(['ok' => false, 'error' => 'Bad request'], 400);
    locked(function () use ($op, $col, $id, $b, $inboxFile) {
        $i = inbox();
        if ($op === 'del') $i[$col] = array_values(array_filter($i[$col], fn($x) => ($x['id'] ?? '') !== $id));
        elseif ($op === 'status' && in_array($b['status'] ?? '', ['New', 'Confirmed', 'Delivered', 'Cancelled'], true))
            foreach ($i['orders'] as &$o) if (($o['id'] ?? '') === $id) $o['status'] = $b['status'];
        wjson($inboxFile, $i);
    });
    out(['ok' => true]);
}
case 'order': {
    need_post();
    if (!rate_ok('order', 20, 3600)) out(['ok' => false, 'error' => 'Too many orders from this connection. Please call us.'], 429);
    $b = body();
    $name = clean($b['name'] ?? '', 80); $phone = clean($b['phone'] ?? '', 30);
    $county = clean($b['county'] ?? '', 60); $town = clean($b['town'] ?? '', 80);
    if ($name === '' || $phone === '' || $county === '' || $town === '') out(['ok' => false, 'error' => 'Please fill in all required fields'], 400);
    $catalog = [];
    foreach ((rjson($dataFile, [])['data']['products'] ?? []) as $p) if (isset($p['id'])) $catalog[(string)$p['id']] = $p;
    $items = []; $total = 0;
    foreach (array_slice((array)($b['items'] ?? []), 0, 60) as $it) {
        $id = clean($it['id'] ?? '', 40); $q = (int)($it['qty'] ?? 0);
        if ($id === '' || $q < 1 || $q > 1000000) continue;
        $p = $catalog[$id] ?? null;
        $nm = $p ? clean($p['name'] ?? '', 120) : clean($it['name'] ?? '', 120);
        $pr = $p ? (float)($p['price'] ?? 0) : max(0, min(1000000, (float)($it['price'] ?? 0)));
        $items[] = ['id' => $id, 'name' => $nm, 'qty' => $q, 'price' => $pr];
        $total += $pr * $q;
    }
    if (!$items) out(['ok' => false, 'error' => 'Your cart is empty'], 400);
    $o = ['id' => 'AG-' . strtoupper(bin2hex(random_bytes(3))), 'date' => date('Y-m-d'), 'time' => date('H:i'), 'name' => $name, 'phone' => $phone, 'county' => $county, 'town' => $town,
          'deliver' => clean($b['deliver'] ?? '', 20), 'pay' => clean($b['pay'] ?? '', 60), 'notes' => clean($b['notes'] ?? '', 600), 'items' => $items, 'total' => $total, 'status' => 'New'];
    locked(function () use ($o, $inboxFile) { $i = inbox(); array_unshift($i['orders'], $o); $i['orders'] = array_slice($i['orders'], 0, INBOX_CAP); wjson($inboxFile, $i); });
    out(['ok' => true, 'order' => $o]);
}
case 'sub': {
    need_post();
    if (!rate_ok('sub', 15, 3600)) out(['ok' => false, 'error' => 'Too many requests'], 429);
    $em = clean(body()['email'] ?? '', 120);
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'Please enter a valid email address'], 400);
    locked(function () use ($em, $inboxFile) {
        $i = inbox();
        foreach ($i['subs'] as $s) if (strcasecmp($s['email'] ?? '', $em) === 0) return;
        array_unshift($i['subs'], ['id' => 's' . bin2hex(random_bytes(4)), 'email' => $em, 'date' => date('Y-m-d')]);
        $i['subs'] = array_slice($i['subs'], 0, INBOX_CAP); wjson($inboxFile, $i);
    });
    out(['ok' => true]);
}
case 'msg': {
    need_post();
    if (!rate_ok('msg', 15, 3600)) out(['ok' => false, 'error' => 'Too many messages. Please call us.'], 429);
    $b = body();
    $m = ['id' => 'm' . bin2hex(random_bytes(4)), 'name' => clean($b['name'] ?? '', 80), 'contact' => clean($b['contact'] ?? '', 120), 'text' => clean($b['text'] ?? '', 1500), 'date' => date('Y-m-d')];
    if ($m['name'] === '' || $m['text'] === '') out(['ok' => false, 'error' => 'Please fill in your name and message'], 400);
    locked(function () use ($m, $inboxFile) { $i = inbox(); array_unshift($i['messages'], $m); $i['messages'] = array_slice($i['messages'], 0, INBOX_CAP); wjson($inboxFile, $i); });
    out(['ok' => true]);
}
default:
    out(['ok' => false, 'error' => 'Unknown request'], 404);
}
