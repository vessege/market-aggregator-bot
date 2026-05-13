<?php
declare(strict_types=1);

/**
 * Refresh the Uzum anonymous access token.
 *
 * Uzum's web client issues short-lived (~6h) JWTs from
 *   POST https://id.uzum.uz/api/auth/token
 * with an empty `Authorization: Bearer` header. The response carries a
 * fresh `access_token=...` cookie that can then authenticate calls to
 * https://graphql.uzum.uz/.
 *
 * This script:
 *   1. Calls the token endpoint with the same headers a real browser
 *      sends (curl from a server is otherwise rejected as "insufficient
 *      headers").
 *   2. Parses Set-Cookie to extract the JWT.
 *   3. Writes UZUM_AUTH_TOKEN and UZUM_AUTH_TYPE to the project's .env
 *      file in-place.
 *
 * Schedule via cron every 5 hours, e.g.:
 *   17 0,5,10,15,20 * * * /usr/bin/php /www/.../bin/refresh-uzum-token.php >> storage/logs/uzum-token.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Logger;

Bootstrap::init();

$envPath = realpath(__DIR__ . '//../.env') ?: (__DIR__ . '//../.env');
$envPath = (string) $envPath;

function emit(string $level, string $msg, array $ctx = []): void {
    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $msg";
    if ($ctx !== []) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    echo $line . PHP_EOL;
    if (strtolower($level) === 'error') {
        Logger::error('uzum-token', $msg, $ctx);
    } else {
        Logger::info('uzum-token', $msg, $ctx);
    }
}

emit('INFO', 'refresh start');

$ch = curl_init('https://id.uzum.uz/api/auth/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '', // empty body, only headers matter
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer',
        'Content-Type: application/json',
        'Referer: https://uzum.uz/',
        'Accept-Language: uz',
        'sec-ch-ua-platform: "Linux"',
        'sec-ch-ua: "Chromium";v="133", "Not(A:Brand";v="99"',
        'sec-ch-ua-mobile: ?0',
    ],
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$raw  = curl_exec($ch);
$info = curl_getinfo($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($raw === false || $err) {
    emit('ERROR', 'curl failed', ['err' => $err]);
    exit(2);
}
$code = (int) ($info['http_code'] ?? 0);
emit('INFO', 'auth response', ['http_code' => $code]);

if ($code !== 204 && $code !== 200) {
    emit('ERROR', 'unexpected status', ['http_code' => $code, 'body' => substr($raw, 0, 500)]);
    exit(3);
}

$headerSize = (int) ($info['header_size'] ?? 0);
$rawHeaders = substr($raw, 0, $headerSize);

$jwt = null;
foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
    if (preg_match('/^Set-Cookie:\s*access_token=([^;]+)/i', $line, $m)) {
        $jwt = urldecode($m[1]);
        break;
    }
}

if (!$jwt) {
    emit('ERROR', 'access_token cookie not found in response');
    exit(4);
}

// Sanity-check: JWT must have two dots and decode to a JSON with `exp`.
if (substr_count($jwt, '.') !== 2) {
    emit('ERROR', 'access_token is not a JWT', ['preview' => substr($jwt, 0, 40)]);
    exit(5);
}
$parts   = explode('.', $jwt);
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)) ?: '', true);
$exp     = is_array($payload) ? (int) ($payload['exp'] ?? 0) : 0;
$iat     = is_array($payload) ? (int) ($payload['iat'] ?? 0) : 0;
$ttl     = $exp - time();
emit('INFO', 'jwt decoded', [
    'iat'      => $iat ? date('c', $iat) : null,
    'exp'      => $exp ? date('c', $exp) : null,
    'ttl_min'  => $ttl > 0 ? intdiv($ttl, 60) : 0,
    'jwt_size' => strlen($jwt),
]);

if (!is_file($envPath)) {
    emit('ERROR', '.env not found at expected path', ['path' => $envPath]);
    exit(6);
}

$envBefore = (string) file_get_contents($envPath);
$envAfter  = $envBefore;

/**
 * Replace (or append) a KEY=VALUE line in the .env file. Values are
 * written without surrounding quotes — that matches the existing
 * convention of the project's .env.example.
 */
$setKey = static function (string $contents, string $key, string $value): string {
    $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
    $line    = $key . '=' . $value;
    if (preg_match($pattern, $contents)) {
        return (string) preg_replace($pattern, $line, $contents, 1);
    }
    $sep = (str_ends_with($contents, "\n") || $contents === '') ? '' : "\n";
    return $contents . $sep . $line . "\n";
};

$envAfter = $setKey($envAfter, 'UZUM_AUTH_TOKEN', $jwt);
$envAfter = $setKey($envAfter, 'UZUM_AUTH_TYPE', 'Bearer');

// Write atomically through a temp file so we never leave a half-written .env.
$tmp = $envPath . '.tmp.' . getmypid();
if (file_put_contents($tmp, $envAfter, LOCK_EX) === false) {
    emit('ERROR', 'failed to write tmp file', ['path' => $tmp]);
    exit(7);
}
@chmod($tmp, 0640);
if (!@rename($tmp, $envPath)) {
    @unlink($tmp);
    emit('ERROR', 'failed to rename tmp -> .env', ['path' => $envPath]);
    exit(8);
}

emit('INFO', '.env updated; new token live on next request');
exit(0);
