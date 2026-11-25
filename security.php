<?php
declare(strict_types=1);

function envValue(string $name, string $default = '', array $aliases = []): string {
    foreach (array_merge([$name], $aliases) as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') return $value;
    }
    return $default;
}
function production(): bool { return envValue('TF_ENV', 'development') === 'production' || envValue('VERCEL') === '1'; }
function urlOrigin(string $url, bool $originOnly = false): ?string {
    // Browser origins contain scheme, host and effective port, never an app path.
    if ($url === '' || preg_match('/[\s\\\\]/', $url)) return null;
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http','https'], true)) return null;
    if ($originOnly && !in_array($parts['path'] ?? '', ['', '/'], true)) return null;
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if ($port < 1 || $port > 65535) return null;
    return $scheme.'://'.strtolower($parts['host']).':'.$port;
}
function requestOriginAllowed(string $origin): bool {
    $appUrl = trim(envValue('TF_APP_URL'));
    // Preserve development and non-browser clients; CSRF is still checked by the API.
    if ($origin === '' || $appUrl === '') return true;
    $expected = urlOrigin($appUrl);
    return $expected !== null && urlOrigin($origin, true) === $expected;
}
function secureRequest(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (envValue('TF_TRUST_PROXY') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function securityHeaders(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
    if (secureRequest()) header('Strict-Transport-Security: max-age=31536000');
    if (production()) ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
final class ServiceConfigurationException extends RuntimeException {}

function validateEnvironment(): void {
    if (!production()) return;
    if (PHP_VERSION_ID < 80200) throw new ServiceConfigurationException('Production requires a supported PHP version (8.2 or later).');
    foreach (['TF_APP_KEY','TF_APP_URL'] as $key) {
        if (envValue($key) === '') throw new ServiceConfigurationException('Production environment is missing '.$key.'.');
    }
    $c=require __DIR__.'/config.php';
    foreach (['host','database','username','password'] as $key) if ($c[$key]==='') throw new ServiceConfigurationException('Production database setting is missing: '.$key.'.');
    if (strlen(envValue('TF_APP_KEY')) < 32 || strtolower($c['username']) === 'root' || !str_starts_with(envValue('TF_APP_URL'), 'https://')) {
        throw new ServiceConfigurationException('Production requires a strong TF_APP_KEY, a dedicated database user and an HTTPS TF_APP_URL.');
    }
}
function setupAllowed(): bool {
    return !production() && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
}
function strongPassword(string $password): void {
    demand(mb_strlen($password) >= 15 && strlen($password) <= 72 && !str_contains($password, "\0"), 'Use a password of at least 15 characters and at most 72 bytes.');
    demand(!preg_match('/^(.)\1+$/us', $password) && !in_array(strtolower($password), ['password123456789','123456789012345','qwertyuiopasdfgh','letmeinletmeinletmein'], true), 'Choose a less predictable password or a unique passphrase.');
}
function passwordValue(array $data, string $key = 'password'): string {
    demand(isset($data[$key]) && is_string($data[$key]) && strlen($data[$key])<=128 && !str_contains($data[$key],"\0"),'Enter a valid password.');
    return $data[$key];
}
function rateLimit(PDO $db, string $scope, string $identity, int $limit, int $seconds, bool $cooldown = false): void {
    // Executed before the mutation transaction: failed requests consume attempts too.
    $key = hash('sha256', $scope . ':' . $identity);
    $bucket = $cooldown ? time() : intdiv(time(), $seconds) * $seconds;
    if ($cooldown) query($db,'INSERT INTO rate_limits(bucket_key,window_start,hits,expires_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE hits=IF(expires_at<=?,1,hits+1),window_start=IF(expires_at<=?,VALUES(window_start),window_start),expires_at=IF(expires_at<=?,VALUES(expires_at),expires_at)',[$key,$bucket,$bucket+$seconds,time(),time(),time()]);
    else query($db, 'INSERT INTO rate_limits(bucket_key,window_start,hits,expires_at) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE hits=IF(window_start=VALUES(window_start),hits+1,1),window_start=VALUES(window_start),expires_at=VALUES(expires_at)', [$key,$bucket,$bucket+$seconds]);
    $counter=one($db,'SELECT hits,expires_at FROM rate_limits WHERE bucket_key=?',[$key]); $hits=(int)$counter['hits'];
    if ($hits > $limit) header('Retry-After: ' . max(1, (int)$counter['expires_at']-time()));
    demand($hits <= $limit, 'Too many attempts. Please try again later.', 429);
    if (random_int(1,100) === 1) query($db,'DELETE FROM rate_limits WHERE expires_at < ? LIMIT 1000',[time()]);
}
function startSecureSession(): void {
    if (production() || envValue('TF_SESSION_STORE')==='database') {
        require_once __DIR__.'/sessions.php';
        session_set_save_handler(new DatabaseSessions(db()),true);
        ini_set('session.gc_maxlifetime','1800');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>secureRequest() || production(),'path'=>'/']);
    session_start();
    if (isset($_SESSION['uid']) && (time()-($_SESSION['last_seen']??0)>1800 || time()-($_SESSION['signed_in']??0)>28800)) {
        $_SESSION=[]; session_regenerate_id(true);
    }
    $_SESSION['last_seen']=time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function signIn(int $uid, bool $admin = false, int $version = 1): void {
    $_SESSION=[]; session_regenerate_id(true);
    $_SESSION['uid']=$uid; $_SESSION['admin_login']=$admin; $_SESSION['auth_version']=$version; $_SESSION['signed_in']=time(); $_SESSION['last_seen']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32));
}
function reauthenticate(PDO $db, array $u, array $data, string $message = 'Confirm your admin password to continue.'): void {
    $hash=query($db,'SELECT password_hash FROM users WHERE id=?',[$u['id']])->fetchColumn();
    demand(password_verify(passwordValue($data,'admin_password'),$hash),$message,403);
}
function verifyAccountCode(array &$challenge, array $data): void {
    demand(($challenge['expires']??0)>time() && ($challenge['attempts']??5)<5,'Code expired or too many attempts. Start again to request a new code.');
    $challenge['attempts']++;
    $code=field($data,'code',6);
    demand((bool)preg_match('/^[0-9]{6}$/',$code) && hash_equals($challenge['code_hash'],hash_hmac('sha256',$code,envValue('TF_APP_KEY'))),'Invalid or expired verification code.');
}
