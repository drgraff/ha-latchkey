<?php
/**
 * Receives household presence pushed by Home Assistant (rest_command
 * latchkey_status, see homeassistant/snippets). Accepts only requests carrying
 * the shared secret in the X-Latchkey-Secret header; anything else gets 404.
 * Required by the public stub public_html/<your-page>/status.php.
 */
declare(strict_types=1);
$cfg = require __DIR__ . '/config.php';
header('X-Robots-Tag: noindex, nofollow');
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals($cfg['secret'], (string)($_SERVER['HTTP_X_LATCHKEY_SECRET'] ?? ''))) {
    http_response_code(404);
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); exit; }
file_put_contents(__DIR__ . '/status.json', json_encode([
    'someone_home' => (bool)($in['someone_home'] ?? false),
    'last_home'    => (int)($in['last_home'] ?? 0),
    'updated'      => time(),
]), LOCK_EX);
header('Content-Type: application/json');
echo '{"ok":true}';
