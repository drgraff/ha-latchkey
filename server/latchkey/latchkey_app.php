<?php
/**
 * Latchkey - a PIN-protected web button for Home Assistant, hosted anywhere.
 *
 * This file is the shared app. It lives OUTSIDE your web root, next to
 * config.php. Each public page is a tiny stub (see public_html/example-page)
 * that sets $LATCHKEY_PAGE and requires this file.
 *
 * Flow: visitor enters PIN -> this server checks it (hashed, with lockout) ->
 * this server POSTs to your Home Assistant webhook, adding a shared secret the
 * browser never sees -> the Latchkey blueprint in HA checks the secret and runs
 * your action.
 *
 * https://github.com/drgraff/ha-latchkey  (MIT licence)
 */
declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
$pc = $cfg['pages'][$LATCHKEY_PAGE ?? ''] ?? null;          // this page's settings
if (!$pc) { http_response_code(404); exit; }
$text = array_merge($cfg['text'] ?? [], $pc['text'] ?? []);   // page text can override site text
$showStatus = !empty($pc['show_status']);

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

/** POST an event to the Home Assistant webhook, adding the shared secret. */
function latchkey_call_ha(array $cfg, array $payload): bool {
    $c = curl_init($cfg['ha_webhook_url']);
    curl_setopt_array($c, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload + ['secret' => $cfg['secret']]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $code === 200;
}

/** The presence line, from the status Home Assistant pushes to status.php. */
function latchkey_home_status(array $cfg, array $text): string {
    $f = __DIR__ . '/status.json';
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $stale = ($cfg['status_stale_hours'] ?? 3) * 3600;
    if (!is_array($s) || ($s['updated'] ?? 0) < time() - $stale || (!$s['someone_home'] && !$s['last_home'])) {
        return $text['status_unknown'];
    }
    if ($s['someone_home'] || $s['last_home'] > time() - ($cfg['status_window_hours'] ?? 24) * 3600) {
        return $text['status_recent'];
    }
    return $text['status_away'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $in = json_decode((string)file_get_contents('php://input'), true);
    $pin = preg_replace('/\D/', '', (string)(is_array($in) ? ($in['pin'] ?? '') : ''));
    $action = is_array($in) && ($in['action'] ?? '') === 'status' ? 'status' : 'press';
    if ($action === 'status' && !$showStatus) {        // this page has no status check
        echo json_encode(['ok' => false, 'error' => 'not_available']);
        exit;
    }

    // One state file: wrong-PIN tries and lockouts per page, press cooldown shared.
    $fh = fopen(__DIR__ . '/state.json', 'c+');
    flock($fh, LOCK_EX);
    $all = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    $st = $all['pages'][$LATCHKEY_PAGE] ?? [];
    $now = time();
    $lastPress = $all['last_press'] ?? 0;
    $st['fails'] = array_values(array_filter($st['fails'] ?? [], fn($t) => $t > $now - $cfg['fail_window']));
    $home = fn() => $showStatus ? latchkey_home_status($cfg, $text) : '';

    if (($st['locked_until'] ?? 0) > $now) {
        $resp = ['ok' => false, 'error' => 'locked', 'minutes' => (int)ceil(($st['locked_until'] - $now) / 60)];
    } elseif ($pin === '' || !password_verify($pin, $pc['pin_hash'])) {
        $st['fails'][] = $now;
        if (count($st['fails']) >= $cfg['max_fails']) {
            $st['locked_until'] = $now + $cfg['lockout'];
            $st['fails'] = [];
            latchkey_call_ha($cfg, ['event' => 'lockout', 'page' => $LATCHKEY_PAGE]);
            $resp = ['ok' => false, 'error' => 'locked', 'minutes' => (int)($cfg['lockout'] / 60)];
        } else {
            $resp = ['ok' => false, 'error' => 'wrong_pin', 'left' => $cfg['max_fails'] - count($st['fails'])];
        }
    } elseif ($action === 'status') {
        $st['fails'] = [];                              // correct PIN, check only - nothing is operated
        $resp = ['ok' => true, 'status_only' => true, 'home' => $home()];
    } elseif ($lastPress > $now - $cfg['cooldown']) {
        $resp = ['ok' => false, 'error' => 'too_soon', 'seconds' => $lastPress + $cfg['cooldown'] - $now, 'home' => $home()];
    } else {
        $st['fails'] = [];
        if (latchkey_call_ha($cfg, ['event' => 'press', 'page' => $LATCHKEY_PAGE])) {
            $lastPress = $now;
            $resp = ['ok' => true, 'seconds' => $cfg['cooldown'], 'home' => $home()];
        } else {
            $resp = ['ok' => false, 'error' => 'unreachable'];
        }
    }
    $all['last_press'] = $lastPress;
    $all['pages'][$LATCHKEY_PAGE] = $st;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($all));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    echo json_encode($resp);
    exit;
}

$h = fn(string $k) => htmlspecialchars($text[$k] ?? '', ENT_QUOTES);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h('title') ?></title>
<style>
  :root { --bg:#f4f1ec; --card:#fff; --ink:#1f2328; --muted:#5f6670; --accent:#2f6f4f; --accent-ink:#fff; --err:#a3302a; --line:#d9d4cc; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#16181b; --card:#212429; --ink:#eceff2; --muted:#a2a9b1; --accent:#4f9e76; --accent-ink:#0d1a13; --err:#ff8a80; --line:#33373d; }
  }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px;
         background:var(--bg); color:var(--ink); font:16px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { width:100%; max-width:380px; background:var(--card); border:1px solid var(--line); border-radius:16px; padding:28px 22px; }
  h1 { margin:0 0 20px; font-size:1.5rem; }
  label { display:block; font-weight:600; margin-bottom:6px; }
  input[type=password] { width:100%; font-size:1.6rem; letter-spacing:.35em; text-align:center; padding:12px; border-radius:10px;
         border:1px solid var(--line); background:var(--bg); color:var(--ink); }
  .remember { display:flex; gap:8px; align-items:center; margin:12px 0 20px; color:var(--muted); font-size:.95rem; font-weight:400; }
  button { width:100%; padding:18px; font-size:1.2rem; font-weight:700; border:0; border-radius:12px; background:var(--accent); color:var(--accent-ink); cursor:pointer; }
  button:disabled { opacity:.55; cursor:default; }
  button.secondary { background:transparent; color:var(--accent); border:2px solid var(--accent); margin-bottom:12px; font-size:1.05rem; padding:14px; }
  #status { min-height:1.5em; margin:16px 0 0; text-align:center; font-weight:600; }
  #status.err { color:var(--err); }
  #home { margin:6px 0 0; text-align:center; font-size:1.05rem; font-weight:600; min-height:1.4em; }
  .note { margin:18px 0 0; color:var(--muted); font-size:.85rem; text-align:center; }
</style>
</head>
<body>
<main>
  <h1><?= $h('title') ?></h1>
  <label for="pin">PIN</label>
  <input id="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="8">
  <label class="remember"><input id="remember" type="checkbox"> Remember PIN on this device</label>
<?php if ($showStatus): ?>
  <button id="check" class="secondary"><?= $h('check_button') ?></button>
<?php endif; ?>
  <button id="go"><?= $h('button') ?></button>
  <p id="status" role="status"></p>
  <p id="home"></p>
<?php if (!empty($text['note'])): ?>
  <p class="note"><?= $h('note') ?></p>
<?php endif; ?>
</main>
<script>
(function () {
  var T = <?= json_encode(['button' => $text['button'] ?? '', 'done' => $text['done'] ?? 'Done.']) ?>;
  var pin = document.getElementById('pin'), remember = document.getElementById('remember'),
      go = document.getElementById('go'), status = document.getElementById('status'), home = document.getElementById('home'),
      check = document.getElementById('check') || { disabled: false, addEventListener: function () {} };
  try { var saved = localStorage.getItem('latchkeyPin'); if (saved) { pin.value = saved; remember.checked = true; } } catch (e) {}
  function say(msg, err) { status.textContent = msg; status.className = err ? 'err' : ''; }
  function cooldown(s) {
    go.disabled = true;
    var t = setInterval(function () {
      s--; if (s <= 0) { clearInterval(t); go.disabled = false; go.textContent = T.button; }
      else { go.textContent = 'Wait ' + s + ' s'; }
    }, 1000);
    go.textContent = 'Wait ' + s + ' s';
  }
  function send(action) {
    var p = pin.value.replace(/\D/g, '');
    if (!p) { say('Enter the PIN first.', true); pin.focus(); return; }
    var btn = action === 'status' ? check : go;
    btn.disabled = true; say(action === 'status' ? 'Checking…' : 'Sending…');
    fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pin: p, action: action }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        home.textContent = d.home || '';
        if (d.ok || d.error === 'too_soon') {
          try { if (remember.checked) localStorage.setItem('latchkeyPin', p); else localStorage.removeItem('latchkeyPin'); } catch (e) {}
        }
        if (d.ok && d.status_only) { check.disabled = false; say(''); return; }
        if (d.ok) { check.disabled = false; say(T.done); cooldown(d.seconds || 10); return; }
        btn.disabled = false;
        if (d.error === 'wrong_pin') say('Wrong PIN. ' + d.left + ' tries left.', true);
        else if (d.error === 'locked') say('Too many wrong PINs. Try again in ' + d.minutes + ' min.', true);
        else if (d.error === 'too_soon') { say('Just pressed - wait a moment.', true); if (btn === go) cooldown(d.seconds); }
        else say("Couldn't reach the house. Please try again.", true);
      })
      .catch(function () { btn.disabled = false; say("Couldn't connect. Check your internet and try again.", true); });
  }
  check.addEventListener('click', function () { send('status'); });
  go.addEventListener('click', function () { send('press'); });
})();
</script>
</body>
</html>
