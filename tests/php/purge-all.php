<?php

// php tests/php/purge-all.php — no WordPress needed.
// purge_all() empties the FastCGI PAGE cache only: forced = now (manual actions),
// otherwise once at the end of the request (automatic hooks, Elementor CSS wipe).

define('ABSPATH', __DIR__ . '/');

$failures = 0;
function check_purge($label, $actual, $expected)
{
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL {$label}: got [" . var_export($actual, true) . "], expected [" . var_export($expected, true) . "]\n");
        return;
    }
    echo "OK {$label}\n";
}

// Minimal WordPress hook API: priorities, did_action, callbacks snapshotted at start.
$hooks = array();
$did = array();
function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
{
    global $hooks;
    $hooks[$hook][] = array($priority, $callback);
}
function do_action($hook, ...$args)
{
    global $hooks, $did;
    $did[$hook] = (isset($did[$hook]) ? $did[$hook] : 0) + 1;
    $callbacks = isset($hooks[$hook]) ? $hooks[$hook] : array();
    usort($callbacks, function ($a, $b) { return $a[0] - $b[0]; });
    foreach ($callbacks as $callback) {
        call_user_func_array($callback[1], $args);
    }
}
function did_action($hook)
{
    global $did;
    return isset($did[$hook]) ? $did[$hook] : 0;
}
function reset_request()
{
    global $hooks, $did, $after_purge;
    $hooks = array();
    $did = array();
    $after_purge = 0;
    add_action('rt_nginx_helper_after_fastcgi_purge_all', function () {
        global $after_purge;
        $after_purge++;
    });
}
function __($text) { return $text; }
class WP_CLI_Command {}
class WP_CLI { public static function success($message) {} }

// /tmp/pagespeed only exists in the pod.
set_error_handler(function ($errno, $message) {
    return strpos($message, 'touch(') === 0;
});

require_once __DIR__ . '/../../admin/class-purger.php';
require_once __DIR__ . '/../../admin/class-fastcgi-purger.php';
require_once __DIR__ . '/../../class-nginx-helper-wp-cli-command.php';

class Recording_FastCGI_Purger extends FastCGI_Purger
{
    public $urls = array();
    protected function do_remote_get(string $url, array $args = array())
    {
        $this->urls[] = $url;
    }
}

$nginx_helper_admin = (object) array('options' => array('enable_purge' => 1, 'enable_log' => 0));

reset_request();
$purger = new Recording_FastCGI_Purger();
$purger->purge_all(true);
check_purge('forced: page cache purged immediately', $purger->urls, array('http://localhost/purge-all'));
check_purge('forced: Cloudflare hook fired', $after_purge, 1);

reset_request();
$purger = new Recording_FastCGI_Purger();
$purger->purge_all();
$purger->purge_all('');  // do_action('rt_nginx_helper_purge_all') passes ''
check_purge('automatic: nothing purged during the request', $purger->urls, array());
do_action('shutdown');
check_purge('automatic: one purge at the end of the request', $purger->urls, array('http://localhost/purge-all'));
check_purge('automatic: Cloudflare hook fired once', $after_purge, 1);

// A second change in the next request (new PHP request = new purger) is never dropped:
// no cross-request throttle shared between workers.
reset_request();
$purger = new Recording_FastCGI_Purger();
$purger->purge_all();
do_action('shutdown');
check_purge('next request right after: purged again', $purger->urls, array('http://localhost/purge-all'));

reset_request();
$purger = new Recording_FastCGI_Purger();
add_action('shutdown', function () use ($purger) { $purger->purge_all(); }, 10);
do_action('shutdown');
check_purge('called during shutdown: purged immediately', $purger->urls, array('http://localhost/purge-all'));

// Elementor deletes its CSS once per updated plugin during an automatic update.
reset_request();
$purger = new Recording_FastCGI_Purger();
$purger->purge_on_elementor_files_cleared();
$purger->purge_on_elementor_files_cleared();
check_purge('Elementor: nothing purged while the update runs', $purger->urls, array());
do_action('shutdown');
check_purge('Elementor: one purge at the end of the request', $purger->urls, array('http://localhost/purge-all'));

reset_request();
$nginx_helper_admin->options['enable_purge'] = 0;
$purger = new Recording_FastCGI_Purger();
$purger->purge_on_elementor_files_cleared();
do_action('shutdown');
check_purge('Elementor: purge disabled in settings, nothing purged', $purger->urls, array());
$nginx_helper_admin->options['enable_purge'] = 1;

reset_request();
$nginx_purger = new Recording_FastCGI_Purger();
(new Nginx_Helper_WP_CLI_Command())->purge_all(array(), array());
check_purge('WP-CLI purge-all: purged before reporting success', $nginx_purger->urls, array('http://localhost/purge-all'));

// Code caches stay out of page purges (only the manual Faaaster hard flush resets them).
$calls = array();
$tokens = token_get_all(file_get_contents(__DIR__ . '/../../admin/class-fastcgi-purger.php'));
foreach ($tokens as $i => $token) {
    if (is_array($token) && T_STRING === $token[0] && in_array($token[1], array('opcache_reset', 'wp_cache_flush'), true)) {
        $calls[] = $token[1];
    }
}
check_purge('no opcache_reset / wp_cache_flush call in the FastCGI purger', $calls, array());

$registration = file_get_contents(__DIR__ . '/../../includes/class-nginx-helper.php');
check_purge(
    'hook registered on elementor/core/files/clear_cache',
    strpos($registration, "add_action( 'elementor/core/files/clear_cache', \$nginx_purger, 'purge_on_elementor_files_cleared' )") !== false,
    true
);

exit($failures === 0 ? 0 : 1);
