<?php
namespace SwimlogStandalone;

/** WordPress is the sole identity and session authority. */
final class Auth {
    const VERSION = '0.1.0-dev';
    const STATE_COOKIE = '__Host-swimlog-state';
    const TTL = 120;

    public static function random() { return bin2hex(random_bytes(32)); }
    public static function valid($value) { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value); }
    public static function requireCapability($owner) {
        return $owner > 0 && $owner === (int) get_current_user_id()
            && current_user_can('swimlog_view_own_results');
    }
    public static function cookieUser($cookie = '') {
        $parsed = wp_parse_auth_cookie($cookie, 'logged_in');
        // Core allows a POST grace period. This application deliberately does not.
        if (!$parsed || (int) $parsed['expiration'] <= time()) return 0;
        return (int) wp_validate_auth_cookie($cookie, 'logged_in');
    }
    public static function cookie($name, $value, $expires = 0) {
        if (!setcookie($name, $value, array('expires' => $expires, 'path' => '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax'))) {
            throw new \RuntimeException('Unable to set authentication cookie.');
        }
    }
    private static function key() { return hash('sha256', wp_salt('auth') . '|swimlog-handoff-v1', true); }
    private static function seal($data) {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt(json_encode($data), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'swimlog-handoff-v1');
        if (false === $encrypted) throw new \RuntimeException('Handoff encryption failed.');
        return base64_encode($iv . $tag . $encrypted);
    }
    private static function open($data) {
        $raw = base64_decode($data, true);
        if (false === $raw || strlen($raw) < 29) return null;
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'swimlog-handoff-v1');
        return false === $plain ? null : json_decode($plain, true);
    }
    public static function issue($challenge, $cookie) {
        $owner = self::cookieUser($cookie);
        if (!self::valid($challenge) || !self::requireCapability($owner)) throw new \RuntimeException('Unauthorized handoff.');
        $code = self::random(); $key = 'swimlog_sso_' . hash('sha256', $code);
        $payload = self::seal(array('challenge' => $challenge, 'cookie' => $cookie, 'owner' => $owner, 'expires' => time() + self::TTL));
        if (!add_option($key, $payload, '', false)) throw new \RuntimeException('Unable to store handoff.');
        // Cleanup is independent of successful browser return. No new tables.
        if (!wp_schedule_single_event(time() + self::TTL + 60, 'swimlog_sso_cleanup', array($key))) {
            delete_option($key); throw new \RuntimeException('Unable to schedule handoff cleanup.');
        }
        return $code;
    }
    public static function redeem($code, $verifier) {
        if (!self::valid($code) || !self::valid($verifier)) throw new \RuntimeException('Invalid handoff.');
        $key = 'swimlog_sso_' . hash('sha256', $code);
        $payload = get_option($key, null);
        $data = is_string($payload) ? self::open($payload) : null;
        if (!is_array($data) || $data['expires'] <= time() || !hash_equals($data['challenge'], hash('sha256', $verifier))) throw new \RuntimeException('Expired or invalid handoff.');
        $owner = self::cookieUser($data['cookie']);
        if (!$owner || $owner !== (int) $data['owner'] || !user_can($owner, 'swimlog_view_own_results')) throw new \RuntimeException('Session is no longer authorized.');
        // DELETE's affected-row result is the atomic claim. Exactly one request wins,
        // even if two requests have already read the same cached encrypted payload.
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $payload));
        wp_cache_delete($key, 'options');
        if (1 !== $deleted) throw new \RuntimeException('Handoff already used.');
        wp_clear_scheduled_hook('swimlog_sso_cleanup', array($key));
        return $data['cookie'];
    }
    public static function cleanup($key) {
        if (is_string($key) && preg_match('/^swimlog_sso_[a-f0-9]{64}$/D', $key)) delete_option($key);
    }
    public static function loginFailure($origin) {
        self::headers();
        header('Location: ' . self::origin($origin) . '/?login_error=1', true, 303);
        exit;
    }
    public static function headers() {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
    }
    public static function origin($value) {
        $p = parse_url($value);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '', array('', '/'), true) || (isset($p['port']) && $p['port'] !== 443)) throw new \RuntimeException('Invalid configured origin.');
        return 'https://' . strtolower($p['host']);
    }
}
