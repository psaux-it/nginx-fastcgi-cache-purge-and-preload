<?php
/**
 * Standalone test (no WordPress): php test-wget-compat.php <wget|wget2> [print]
 *
 *  wget  : proves nppp_wget_build_command() == the v2.1.9 hard-coded command strings (byte for byte)
 *  wget2 : prints the generated wget2 commands for the same inputs
 */
define( 'ABSPATH', '/' );
define( 'NPPP_HEADER_ACCEPT', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,' . '*' . '/' . '*;q=0.8' );

$flavor = $argv[1] ?? 'wget';
function apply_filters( $tag, $v ) { return $v; }
$GLOBALS['opt'] = [];
function update_option( $k, $v ) { $GLOBALS['opt'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opt'][ $k ] ?? $d; }
function nppp_get_wget_compatibility() {
    global $flavor;
    return $flavor === 'wget2' ? [ 'ok' => true, 'reason' => 'ok', 'flavor' => 'wget2' ] : [ 'ok' => true, 'reason' => 'ok', 'flavor' => 'wget' ];
}
require __DIR__ . '/../includes/compat-wget.php';

$defaults = include __DIR__ . '/../includes/preload-defaults.php';
$in = [
    'safexec_path' => '/usr/bin/safexec',
    'tmp_path'     => '/var/cache/nginx/tmp',
    'fdomain'      => 'https://example.com/',
    'domain_list'  => 'example.com,www.example.com',
    'ua'           => 'Mozilla/5.0 (X11; Linux x86_64) Test',
    'mobile_ua'    => 'Mozilla/5.0 (Linux; Android 10; Mobile) Test',
    'rate'         => 5120,
    'wait'         => 2,
    'rt'           => 60,
    'regex'        => $defaults['reject_regex'],
    'ext'          => $defaults['reject_extension'],
    'url_args'     => "'https://example.com/a/' 'https://example.com/b/?x=1'",
    'page'         => 'https://example.com/post-1/',
];

function cases( array $in, string $use_proxy, string $http_proxy, bool $sfx ): array {
    $https_proxy = $http_proxy;
    $safexec_path = $in['safexec_path'];
    $use_safexec = $sfx;
    $tmp_path = $in['tmp_path'];
    $domain_list = $in['domain_list'];
    $nginx_cache_read_timeout = $in['rt'];
    $nginx_cache_limit_rate = $in['rate'];
    $nginx_cache_wait = $in['wait'];
    $nginx_cache_reject_regex = $in['regex'];
    $nginx_cache_reject_extension = $in['ext'];
    $NPPP_DYNAMIC_USER_AGENT = $in['ua'];
    $fdomain = $in['fdomain'];
    $nppp_mobile_ua = $in['mobile_ua'];
    $current_page_url = $in['page'];
    $url_shell_arguments = $in['url_args'];
    $valid_url_args = [ "'https://example.com/a/'", "'https://example.com/b/?x=1'" ];
    $out = [];

    /* ---- ORIGINAL v2.1.9 strings (verbatim) ---- */
    $out['crawl']['old'] =
        ($use_safexec ? escapeshellarg($safexec_path) . ' ' : '') .
        'nohup wget ' .
        '--no-verbose --recursive -l inf --no-config --no-cookies --delete-after ' .
        '--no-dns-cache --no-check-certificate --prefer-family=IPv4 --retry-on-http-error=503,429 --waitretry=10 ' .
        '--dns-timeout=10 --connect-timeout=5 --read-timeout=' . $nginx_cache_read_timeout . ' --tries=2 --ignore-case ' .
        '-e robots=off ' .
        '-e ' . escapeshellarg('use_proxy=' . $use_proxy) . ' ' .
        '-e ' . escapeshellarg('http_proxy='  . $http_proxy) . ' ' .
        '-e ' . escapeshellarg('https_proxy=' . $https_proxy) . ' ' .
        '-P ' . escapeshellarg($use_safexec ? '/tmp' : $tmp_path) . ' ' .
        '--limit-rate=' . ((int)$nginx_cache_limit_rate) . 'k ' .
        '--wait=' . ((int)$nginx_cache_wait) . ' ' .
        '--reject-regex=' . escapeshellarg($nginx_cache_reject_regex) . ' ' .
        '--reject=' . escapeshellarg($nginx_cache_reject_extension) . ' ' .
        '--domains=' . escapeshellarg($domain_list) . ' ' .
        '--header=' . escapeshellarg(NPPP_HEADER_ACCEPT) . ' ' .
        '--user-agent=' . escapeshellarg($NPPP_DYNAMIC_USER_AGENT) . ' ' .
        '-- ' .
        escapeshellarg($fdomain);

    $out['probe']['old'] =
        'exec ' .
        ($use_safexec ? escapeshellarg($safexec_path) . ' ' : '') .
        'wget ' .
        '--quiet --no-cache --no-config --no-cookies -O /dev/null ' .
        '--no-dns-cache --no-check-certificate --prefer-family=IPv4 --retry-on-http-error=503,429 --waitretry=10 ' .
        '--dns-timeout=10 --connect-timeout=5 --read-timeout=60 --tries=2 --ignore-case ' .
        '-e robots=off ' .
        '-e ' . escapeshellarg('use_proxy=' . $use_proxy) . ' ' .
        '-e ' . escapeshellarg('http_proxy='  . $http_proxy) . ' ' .
        '-e ' . escapeshellarg('https_proxy=' . $https_proxy) . ' ' .
        '--limit-rate=' . ((int)$nginx_cache_limit_rate) . 'k ' .
        '--wait=' . ((int)$nginx_cache_wait) . ' ' .
        '--reject-regex=' . escapeshellarg($nginx_cache_reject_regex) . ' ' .
        '--reject=' . escapeshellarg($nginx_cache_reject_extension) . ' ' .
        '--domains=' . escapeshellarg($domain_list) . ' ' .
        '--header=' . escapeshellarg(NPPP_HEADER_ACCEPT) . ' ' .
        '--user-agent=' . escapeshellarg($NPPP_DYNAMIC_USER_AGENT) . ' ' .
        '-- ' .
        escapeshellarg($fdomain);

    $out['single']['old'] =
        ($use_safexec ? escapeshellarg($safexec_path) . ' ' : '') .
        'nohup wget ' .
        '--quiet --no-config --no-cookies --delete-after ' .
        '--no-dns-cache --no-check-certificate --prefer-family=IPv4 --retry-on-http-error=503,429 --waitretry=10 ' .
        '--dns-timeout=10 --connect-timeout=5 --read-timeout=' . $nginx_cache_read_timeout . ' --tries=2 ' .
        '-e robots=off ' .
        '-e ' . escapeshellarg('use_proxy=' . $use_proxy) . ' ' .
        '-e ' . escapeshellarg('http_proxy='  . $http_proxy) . ' ' .
        '-e ' . escapeshellarg('https_proxy=' . $https_proxy) . ' ' .
        '-P ' . escapeshellarg($use_safexec ? '/tmp' : $tmp_path) . ' ' .
        '--limit-rate=' . ((int)$nginx_cache_limit_rate) . 'k ' .
        '--domains=' . escapeshellarg($domain_list) . ' ' .
        '--header=' . escapeshellarg(NPPP_HEADER_ACCEPT) . ' ' .
        '--user-agent=' . escapeshellarg(NPPP_USER_AGENT_X) . ' ' .
        '-- ' .
        escapeshellarg($current_page_url) . ' ' .
        '>/dev/null 2>&1 & echo $!';

    $common =
        '--quiet --no-config --no-cookies --delete-after ' .
        '--no-dns-cache --no-check-certificate --prefer-family=IPv4 ' .
        '--dns-timeout=10 --connect-timeout=5 --read-timeout=' . $nginx_cache_read_timeout . ' --tries=1 ' .
        '-e robots=off ' .
        '-e ' . escapeshellarg('use_proxy=' . $use_proxy) . ' ' .
        '-e ' . escapeshellarg('http_proxy=' . $http_proxy) . ' ' .
        '-e ' . escapeshellarg('https_proxy=' . $https_proxy) . ' ' .
        '-P ' . escapeshellarg($use_safexec ? '/tmp' : $tmp_path) . ' ' .
        '--limit-rate=' . $nginx_cache_limit_rate . 'k ' .
        '--domains=' . escapeshellarg($domain_list) . ' ' .
        '--header=' . escapeshellarg(NPPP_HEADER_ACCEPT) . ' ';
    $safexec_prefix = $use_safexec ? escapeshellarg($safexec_path) . ' ' : '';
    $out['related']['old'] = $safexec_prefix . 'nohup wget ' . $common . '--user-agent=' . escapeshellarg(NPPP_USER_AGENT_X) . ' ' . '-- ' . implode(' ', $valid_url_args) . ' >/dev/null 2>&1 &';

    /* ---- NEW builder (same inputs the patched call sites pass) ---- */
    $out['crawl']['new'] = nppp_wget_build_command([
        'safexec' => $use_safexec ? $safexec_path : '', 'nohup' => true, 'verbosity' => 'log', 'recursive' => true,
        'delete_after' => true, 'ignore_case' => true, 'tries' => 2, 'read_timeout' => $nginx_cache_read_timeout,
        'use_proxy' => $use_proxy, 'http_proxy' => $http_proxy, 'https_proxy' => $https_proxy,
        'dir' => $use_safexec ? '/tmp' : $tmp_path, 'limit_rate' => (int) $nginx_cache_limit_rate, 'wait' => (int) $nginx_cache_wait,
        'reject_regex' => $nginx_cache_reject_regex, 'reject_ext' => $nginx_cache_reject_extension,
        'domains' => $domain_list, 'user_agent' => $NPPP_DYNAMIC_USER_AGENT, 'urls' => escapeshellarg($fdomain),
    ]);
    $out['probe']['new'] = nppp_wget_build_command([
        'exec' => true, 'safexec' => $use_safexec ? $safexec_path : '', 'verbosity' => 'quiet', 'no_cache' => true,
        'output' => '/dev/null', 'delete_after' => false, 'ignore_case' => true, 'tries' => 2, 'read_timeout' => 60,
        'use_proxy' => $use_proxy, 'http_proxy' => $http_proxy, 'https_proxy' => $https_proxy,
        'limit_rate' => (int) $nginx_cache_limit_rate, 'wait' => (int) $nginx_cache_wait,
        'reject_regex' => $nginx_cache_reject_regex, 'reject_ext' => $nginx_cache_reject_extension,
        'domains' => $domain_list, 'user_agent' => $NPPP_DYNAMIC_USER_AGENT, 'urls' => escapeshellarg($fdomain),
    ]);
    $out['single']['new'] = nppp_wget_build_command([
        'safexec' => $use_safexec ? $safexec_path : '', 'nohup' => true, 'delete_after' => true, 'tries' => 2,
        'read_timeout' => $nginx_cache_read_timeout, 'use_proxy' => $use_proxy, 'http_proxy' => $http_proxy,
        'https_proxy' => $https_proxy, 'dir' => $use_safexec ? '/tmp' : $tmp_path, 'limit_rate' => (int) $nginx_cache_limit_rate,
        'domains' => $domain_list, 'user_agent' => NPPP_USER_AGENT_X, 'urls' => escapeshellarg($current_page_url),
    ]) . ' >/dev/null 2>&1 & echo $!';
    $out['related']['new'] = nppp_wget_build_command([
        'safexec' => $use_safexec ? $safexec_path : '', 'nohup' => true, 'delete_after' => true, 'retry_http' => false, 'tries' => 1,
        'read_timeout' => $nginx_cache_read_timeout, 'use_proxy' => $use_proxy, 'http_proxy' => $http_proxy,
        'https_proxy' => $https_proxy, 'dir' => $use_safexec ? '/tmp' : $tmp_path, 'limit_rate' => (int) $nginx_cache_limit_rate,
        'domains' => $domain_list, 'user_agent' => NPPP_USER_AGENT_X, 'urls' => implode(' ', $valid_url_args),
    ]) . ' >/dev/null 2>&1 &';
    return $out;
}
define( 'NPPP_USER_AGENT_X', 'Mozilla/5.0 (X11; Linux x86_64) Test' );

$fail = 0; $n = 0;
foreach ( [ [ 'no', '', false ], [ 'yes', 'http://127.0.0.1:3434', false ], [ 'no', '', true ], [ 'yes', 'http://[::1]:3434', true ] ] as [ $up, $hp, $sfx ] ) {
    foreach ( cases( $in, $up, $hp, $sfx ) as $site => $pair ) {
        $n++;
        if ( $flavor === 'wget' ) {
            if ( $pair['old'] === $pair['new'] ) { continue; }
            $fail++;
            echo "MISMATCH [$site proxy=$up safexec=" . ( $sfx ? 'y' : 'n' ) . "]\n  old: {$pair['old']}\n  new: {$pair['new']}\n";
        } elseif ( ( $argv[2] ?? '' ) === 'print' ) {
            echo "[$site proxy=$up safexec=" . ( $sfx ? 'y' : 'n' ) . "]\n  " . $pair['new'] . "\n\n";
        }
    }
}
if ( $flavor === 'wget' ) {
    echo $fail === 0 ? "PASS: $n/$n wget1 command strings identical to v2.1.9\n" : "FAIL: $fail of $n differ\n";
    exit( $fail === 0 ? 0 : 1 );
}
echo "wget2: generated $n commands\n";
