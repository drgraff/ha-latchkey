<?php
/**
 * Set a Latchkey page's PIN (stored only as a hash). Run over SSH:
 *   echo "neighbours 123456" | php /path/to/latchkey/setpin.php
 * Reading the PIN from stdin keeps it out of your shell history and process list.
 */
[$page, $pin] = array_pad(preg_split('/\s+/', trim((string)fgets(STDIN))), 2, '');
if (!preg_match('/^\d{4,8}$/', $pin)) { fwrite(STDERR, "usage: echo '<page> <pin>' | php setpin.php   (PIN = 4-8 digits)\n"); exit(1); }
$f = __DIR__ . '/config.php';
$cfg = require $f;
if (!isset($cfg['pages'][$page])) { fwrite(STDERR, "unknown page '$page' (have: " . implode(', ', array_keys($cfg['pages'])) . ")\n"); exit(1); }
$cfg['pages'][$page]['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
// Note: this rewrites config.php from its values, so comments in it are not kept.
file_put_contents($f, "<?php\n// Latchkey settings - see config.example.php for what each value means.\n// Set PINs with setpin.php.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
chmod($f, 0600);
$chk = require $f;
echo "$page PIN updated: " . (password_verify($pin, $chk['pages'][$page]['pin_hash']) ? 'verified' : 'VERIFY FAILED') . "\n";
