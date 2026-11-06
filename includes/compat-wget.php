<?php
/**
 * wget / wget2 compatibility layer for Nginx Cache Purge Preload
 * Description: One place that builds every wget command line the plugin runs, for GNU Wget 1.x
 *              and GNU Wget2 >= 2.1.0, and turns wget2's log into the wget1
 *              log format that the progress panel, snapshot, mail and Advanced tab parse.
 * Version: 2.1.8
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 *
 * Why this file exists (measured against wget 1.21.4 and wget2 2.1.0 / 2.2.1 / 2.3.0 / git master):
 *
 *   1. -e use_proxy=...      wget2: "Unknown option 'use_proxy'", exit 2.
 *                            -> --no-proxy  /  --http-proxy= --https-proxy=
 *   2. --reject / --reject-regex
 *                            wget2 still sends HEAD+GET for rejected URLs (css, zip, wp-admin ...)
 *                            -> add --filter-urls (request set then equals wget1's).
 *   3. -P /absolute/path     wget2 re-roots it under the CWD (getcwd() . '/' . prefix).
 *                            -> mkdir -p DIR; cd DIR && exec wget2 ... (same PID; wget1 -P also creates DIR).
 *   4. -e robots=off         wget2 ignores Disallow but still fetches /robots.txt and sitemaps.
 *                            -> --no-robots --no-follow-sitemaps
 *   5. --wait / threads      wget2 runs 5 threads by default and --wait is per thread.
 *                            -> --max-threads=1 (wget1 behaviour). Filter: nppp_wget2_max_threads.
 *   6. Accept-Encoding       wget2 offers gzip,br,zstd...; wget1 sends identity -> --compression=identity.
 *   7. Non-ASCII paths       wget2 assumes CP1252 and double-encodes %C3%A7 -> --remote-encoding=utf-8.
 *   8. Invalid regex         wget1 exits 1; wget2 regcomp() failure = "no match", exit 0, the reject
 *                            filter silently switches OFF (crawls /wp-admin/, /cart/ ...).
 *                            -> nppp_wget_regex_is_valid() (sed -E == regcomp) before the crawl.
 *   9. Log format            wget2 has no --no-verbose equivalent (it prints nothing) and no
 *                            FINISHED / "URL: ... ->" lines. The crawl uses wget2's default verbose
 *                            output and nppp_wget_log_read() normalises it when it is read.
 *
 *  10. FINISHED semantics  wget1 prints FINISHED / Downloaded only when something was downloaded; wget2 prints
 *                            "Downloaded: 0 files" for a crawl whose start URL failed. Without a rule the plugin
 *                            would treat that run as complete and overwrite the snapshot with an empty crawl.
 *                            -> the reader emits the FINISHED tail only for files > 0 and bytes > 0.
 *  11. Timestamps           wget1 stamps lines with the server's local time; WordPress forces PHP to UTC.
 *                            -> stamps use the system time zone (TZ env, /etc/localtime, /etc/timezone).
 *  12. Crawl start          wget2 prints no wall-clock time. The crawl shell writes the epoch into
 *                            nppp-wget.start right before wget2 starts, so a crawl held back by the Purge All
 *                            gate does not count the wait as crawl time.
 *
 * wget2 versions differ (measured):
 *   - chatter: 2.1.0 "Adding URL", 2.2.1 "Enqueue", 2.3.0 "Enqueuing" (all dropped by the log reader);
 *   - 2.1.0 and 2.2.1 request /robots.txt even with --no-robots --no-follow-sitemaps (2.3.0 does not):
 *     the reader drops that response, so it is neither a crawled URL nor a "broken link";
 *   - wget2 logs decoded URLs (UTF-8, spaces): the reader re-encodes them like wget1 (%C3%A7, %20).
 * Known, accepted differences (wget2 library behaviour, no flag turns it off): wget2 decodes %2F (path) and
 * %2b (query) while following links, wget1 does not (the request differs from wget1 for those links only);
 * wget2 logs one ERROR line per failed try (429/5xx), wget1 none for 429 (only ERROR 404 is counted).
 * Known, accepted: a redirect source is not logged twice (wget1 logs the target again), so the
 * "checked" counter can be lower by the number of redirects; the unique URL set is identical.
 *
 * Exit codes of the wget2 crawl are not used by the plugin (only the 300 ms probe uses them).
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ============================================================================
 * 1. Flavor detection
 * ========================================================================= */

/**
 * 'wget2' when the binary behind `wget` is GNU Wget2 (>= 2.1.0), otherwise 'wget'.
 * Reuses the cached nppp_get_wget_compatibility() probe (pre-checks.php), no extra fork.
 */
function nppp_wget_flavor(): string {
    static $flavor = null;
    if ( $flavor !== null ) {
        return $flavor;
    }
    $compat = function_exists( 'nppp_get_wget_compatibility' ) ? nppp_get_wget_compatibility() : [];
    $flavor = ( is_array( $compat ) && ( $compat['flavor'] ?? '' ) === 'wget2' ) ? 'wget2' : 'wget';
    return $flavor;
}

function nppp_wget_is_v2(): bool {
    return nppp_wget_flavor() === 'wget2';
}

/* ============================================================================
 * 2. Regex pre-validation (wget2 silently ignores an invalid --reject-regex)
 * ========================================================================= */

/**
 * True when $regex compiles as a POSIX extended regex, i.e. when wget / wget2 (--regex-type=posix, the
 * default: regcomp(REG_EXTENDED)) will actually use it as a filter.
 *
 * sed -E compiles with the same regcomp engine of the system libc (glibc, musl, busybox -> musl).
 * rejects, i.e. in the safe direction. The pattern is passed with a \x01 address delimiter so slashes
 * and backslashes in it need no escaping.
 *
 * Fail-open (true) when it cannot be tested: no shell, no sed, empty pattern, newline/NUL/\x01 in it.
 */
function nppp_wget_regex_is_valid( string $regex ): bool {
    if ( $regex === '' || ! function_exists( 'shell_exec' ) || preg_match( '/[\x00\x01\r\n]/', $regex ) ) {
        return true;
    }
    $script = "\\\x01" . $regex . "\x01p"; // sed address form: \cREGEXc with c = 0x01
    $out    = shell_exec( "printf '' | sed -E -n -e " . escapeshellarg( $script ) . ' >/dev/null 2>&1; echo $?' );
    $rc     = trim( (string) $out );
    return ! ( $rc !== '' && ctype_digit( $rc ) && (int) $rc !== 0 && (int) $rc !== 126 && (int) $rc !== 127 );
}

/**
 * Direct reachability check of the site for the preflight probe (wget2 only).
 *
 * wget2 2.1.0 reports an unresolvable host or a refused connection as exit 0 and takes ~1 s to give up (it retries
 * once), so the 300 ms probe sees "still running" and calls it a success. wget1 and wget2 >= 2.2.1 fail fast with
 * exit 4. The probe therefore checks the site itself with a plain TCP connect, independent of wget2's exit code.
 * IPv4 first (the crawl runs --prefer-family=IPv4), IPv6 only when there is no A record.
 *
 * @return string|null 'dns_error' (does not resolve), 'network_error' (resolves, connection refused / timed out),
 *                     null (reachable, or it cannot be tested).
 */
function nppp_wget_site_failure( string $url ): ?string {
    $p    = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
    $host = is_array( $p ) ? trim( (string) ( $p['host'] ?? '' ), '[]' ) : '';
    if ( $host === '' || ! function_exists( 'fsockopen' ) ) {
        return null;
    }
    $scheme = strtolower( (string) ( $p['scheme'] ?? 'http' ) );
    $port   = (int) ( $p['port'] ?? ( $scheme === 'https' ? 443 : 80 ) );

    $target = null;
    if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
        $target = $host;
    } else {
        $ip = gethostbyname( $host ); // IPv4 only: returns the input unchanged on failure
        if ( $ip !== $host ) {
            $target = $ip;
        } elseif ( function_exists( 'dns_get_record' ) ) {
            $aaaa = @dns_get_record( $host, DNS_AAAA );
            if ( ! empty( $aaaa ) && isset( $aaaa[0]['ipv6'] ) ) {
                $target = $aaaa[0]['ipv6'];
            }
        }
    }
    if ( $target === null ) {
        return 'dns_error';
    }
    if ( strpos( $target, ':' ) !== false ) {
        $target = 'tcp://[' . $target . ']';
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- TCP reachability probe
    $conn = @fsockopen( $target, $port, $errno, $errstr, 2 );
    if ( $conn ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing probe socket
        fclose( $conn );
        return null;
    }
    return 'network_error';
}

/* ============================================================================
 * 3. Command builder
 * ========================================================================= */

/**
 * Build the wget command line for the running flavor.
 *
 * Output for GNU Wget 1.x is byte-for-byte the command the plugin always built
 * For wget2 the flags are translated.
 *
 * Options (all optional):
 *   safexec        string  absolute safexec path, '' = none
 *   nohup          bool    prefix "nohup"
 *   exec           bool    prefix "exec" (probe)
 *   verbosity      string  'quiet' (--quiet) | 'log' (crawl log: wget1 --no-verbose, wget2 verbose)
 *   recursive      bool    --recursive -l inf
 *   no_cache       bool    --no-cache
 *   output         string  -O target, '' = none
 *   delete_after   bool    --delete-after
 *   retry_http     bool    --retry-on-http-error=503,429 --waitretry=10
 *   ignore_case    bool    --ignore-case
 *   tries          int     --tries=N
 *   read_timeout   int     --read-timeout=N
 *   use_proxy / http_proxy / https_proxy
 *   dir            string  download directory: wget1 -P DIR, wget2 cd DIR && exec
 *   limit_rate     int     --limit-rate=Nk
 *   wait           ?int    --wait=N (null = omit)
 *   reject_regex / reject_ext  ?string
 *   domains        string  --domains=
 *   user_agent     string
 *   urls           string  already shell-escaped URL argument(s)
 */
function nppp_wget_build_command( array $o ): string {
    $v2 = nppp_wget_is_v2();

    $o = array_merge( [
        'safexec'      => '',
        'nohup'        => false,
        'exec'         => false,
        'verbosity'    => 'quiet',
        'recursive'    => false,
        'no_cache'     => false,
        'output'       => '',
        'delete_after' => true,
        'retry_http'   => true,
        'ignore_case'  => false,
        'tries'        => 2,
        'read_timeout' => 60,
        'use_proxy'    => 'no',
        'http_proxy'   => '',
        'https_proxy'  => '',
        'dir'          => '',
        'limit_rate'   => 0,
        'wait'         => null,
        'reject_regex' => null,
        'reject_ext'   => null,
        'domains'      => '',
        'user_agent'   => '',
        'urls'         => '',
    ], $o );

    $t = [];

    // Verbosity. wget1: --no-verbose gives the "ts URL:... ->" log. wget2 prints nothing with
    // --no-verbose, so the crawl log uses its default (verbose) output without the progress bar.
    if ( $o['verbosity'] === 'log' ) {
        // wget2 block-buffers stdout when it is a file (0 bytes for seconds, then 4 KB chunks), which would
        // freeze the live progress panel. "-o /dev/stdout" re-opens the same log file with line flushing.
        // If it cannot be re-opened (safexec: wget2 runs as nobody, the log belongs to the PHP user) wget2
        // falls back to plain stdout: the crawl still runs, only the live log is buffered.
        $t[] = $v2 ? '--progress=none -o /dev/stdout' : '--no-verbose';
    } else {
        $t[] = '--quiet';
    }
    if ( $o['recursive'] ) {
        $t[] = '--recursive -l inf';
    }
    if ( $o['no_cache'] ) {
        $t[] = '--no-cache';
    }
    $t[] = '--no-config --no-cookies';
    if ( $o['output'] !== '' ) {
        // Never combine with --delete-after (wget2 run as root would unlink the -O target).
        $t[] = '-O ' . $o['output'];
    }
    if ( $o['delete_after'] ) {
        $t[] = '--delete-after';
    }
    $t[] = '--no-dns-cache --no-check-certificate --prefer-family=IPv4';
    if ( $o['retry_http'] ) {
        $t[] = '--retry-on-http-error=503,429 --waitretry=10';
    }
    $t[] = '--dns-timeout=10 --connect-timeout=5 --read-timeout=' . (int) $o['read_timeout'] . ' --tries=' . (int) $o['tries'];
    if ( $o['ignore_case'] ) {
        $t[] = '--ignore-case';
    }

    // robots
    $t[] = $v2 ? '--no-robots --no-follow-sitemaps' : '-e robots=off';

    // proxy
    if ( $v2 ) {
        if ( $o['use_proxy'] === 'yes' ) {
            $t[] = '--http-proxy=' . escapeshellarg( (string) $o['http_proxy'] ) . ' --https-proxy=' . escapeshellarg( (string) $o['https_proxy'] );
        } else {
            $t[] = '--no-proxy';
        }
    } else {
        $t[] = '-e ' . escapeshellarg( 'use_proxy=' . $o['use_proxy'] )
             . ' -e ' . escapeshellarg( 'http_proxy=' . $o['http_proxy'] )
             . ' -e ' . escapeshellarg( 'https_proxy=' . $o['https_proxy'] );
    }

    // download directory (wget1 only: wget2 gets it through cd below)
    if ( ! $v2 && $o['dir'] !== '' ) {
        $t[] = '-P ' . escapeshellarg( (string) $o['dir'] );
    }

    $t[] = '--limit-rate=' . (int) $o['limit_rate'] . 'k';
    if ( $o['wait'] !== null ) {
        $t[] = '--wait=' . (int) $o['wait'];
    }
    if ( $o['reject_regex'] !== null ) {
        $t[] = '--reject-regex=' . escapeshellarg( (string) $o['reject_regex'] );
    }
    if ( $o['reject_ext'] !== null ) {
        $t[] = '--reject=' . escapeshellarg( (string) $o['reject_ext'] );
    }
    $t[] = '--domains=' . escapeshellarg( (string) $o['domains'] );
    $t[] = '--header=' . escapeshellarg( NPPP_HEADER_ACCEPT );
    $t[] = '--user-agent=' . escapeshellarg( (string) $o['user_agent'] );

    // wget2-only parity flags
    if ( $v2 ) {
        $threads = (int) apply_filters( 'nppp_wget2_max_threads', 1 );
        $threads = max( 1, min( 5, $threads ) );
        $t[] = '--max-threads=' . $threads . ' --compression=identity --remote-encoding=utf-8';
        if ( $o['reject_regex'] !== null || $o['reject_ext'] !== null ) {
            $t[] = '--filter-urls';
        }
    }

    $core = ( $o['safexec'] !== '' ? escapeshellarg( (string) $o['safexec'] ) . ' ' : '' )
          . ( $o['nohup'] ? 'nohup ' : '' )
          . 'wget ' . implode( ' ', $t ) . ' -- ' . $o['urls'];

    if ( $v2 && $o['dir'] !== '' ) {
        // "mkdir -p DIR; cd DIR && exec CMD": wget1 -P creates a missing directory (a Purge All removes the
        // cache tmp dir), wget2 has no usable -P, so create it here. The async list "cd && exec" runs in one
        // subshell that exec()s wget2, so $! stays the PID of the crawler (the plugin signals / watches it).
        $d     = escapeshellarg( (string) $o['dir'] );
        $start = '';
        if ( $o['verbosity'] === 'log' ) {
            $sf = nppp_wget_start_file();
            if ( $sf !== '' ) {
                // Epoch of the real start (after a Purge All gate, if any): "date" runs right before the exec.
                $start = 'date +%s > ' . escapeshellarg( $sf ) . ' 2>/dev/null; ';
            }
        }
        return 'mkdir -p ' . $d . ' 2>/dev/null; ' . $start . 'cd ' . $d . ' && exec ' . $core;
    }

    return ( $o['exec'] ? 'exec ' : '' ) . $core;
}

/** True when the command already replaces its shell with wget ("... && exec wget2 ..."): wget2 + dir. */
function nppp_wget_command_has_exec( string $cmd ): bool {
    return (bool) preg_match( '~^mkdir -p [^;]*; (?:date \+%s > [^;]*; )?cd .* && exec ~s', $cmd ) || strncmp( $cmd, 'cd ', 3 ) === 0;
}

/** "-e use_proxy=no" for wget1, "--no-proxy" for wget2 (used by the purge-queue poll). */
function nppp_wget_no_proxy_flag(): string {
    return nppp_wget_is_v2() ? '--no-proxy' : '-e use_proxy=no';
}

/** File the wget2 crawl shell writes its start epoch into (the gate wait is not part of the crawl). */
function nppp_wget_start_file(): string {
    return function_exists( 'nppp_get_runtime_file' ) ? (string) nppp_get_runtime_file( 'nppp-wget.start' ) : '';
}

/**
 * Remember when this crawl started: wget2 prints no wall-clock time, the log reader needs it.
 * This is the launch time (fallback); the crawl shell overwrites it through nppp_wget_start_file() when the
 * crawl really starts, which is later when the crawl waited behind a Purge All.
 */
function nppp_wget_mark_started(): void {
    if ( nppp_wget_is_v2() ) {
        update_option( 'nppp_wget2_started_at', time(), false );
    }
}

/* ============================================================================
 * 4. wget2 log  ->  wget1 -nv log
 * ========================================================================= */

function nppp_wget_log_is_wget2( string $raw ): bool {
    return (bool) preg_match( '~^(?:\[\d+\] Downloading \'|HTTP (?:ERROR )?response \d{3} |Downloaded: \d+ files?, [\d.]+\s*[KMGT]? bytes)~m', $raw );
}

/**
 * wget1 logs URLs percent-encoded (non-ASCII bytes as %C3%A7, space as %20); wget2 logs them decoded
 * ("/unicode/çıkış/", "/a b/"). Re-encode every byte outside printable ASCII so the Advanced tab
 * (FILTER_VALIDATE_URL), the URL match keys and the preload requests see what wget1 would have logged.
 */
function nppp_wget_log_encode_url( string $url ): string {
    return (string) preg_replace_callback(
        '~[^\x21-\x7E]~',
        static function ( array $m ): string {
            return '%' . strtoupper( bin2hex( $m[0] ) );
        },
        $url
    );
}

function nppp_wget_is_robots_url( string $url ): bool {
    $path = parse_url( $url, PHP_URL_PATH );
    return is_string( $path ) && $path === '/robots.txt';
}

/**
 * Time zone wget1 stamps its lines with: the system one (TZ, /etc/localtime, /etc/timezone). WordPress forces
 * PHP to UTC, so date() would shift every stamp on a server that is not set to UTC. Falls back to the PHP zone.
 */
function nppp_wget_system_timezone(): DateTimeZone {
    static $tz = null;
    if ( $tz instanceof DateTimeZone ) {
        return $tz;
    }
    $names = [ ltrim( (string) getenv( 'TZ' ), ':' ) ];
    $link  = @readlink( '/etc/localtime' );
    if ( is_string( $link ) && preg_match( '~zoneinfo/(.+)$~', $link, $m ) ) {
        $names[] = $m[1];
    }
    if ( @is_readable( '/etc/timezone' ) ) {
        $names[] = trim( (string) @file_get_contents( '/etc/timezone' ) );
    }
    foreach ( $names as $n ) {
        if ( $n !== '' ) {
            try {
                return $tz = new DateTimeZone( $n );
            } catch ( Exception $e ) {
                continue;
            }
        }
    }
    return $tz = new DateTimeZone( date_default_timezone_get() );
}

function nppp_wget_format_stamp( int $ts ): string {
    return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( nppp_wget_system_timezone() )->format( 'Y-m-d H:i:s' );
}

function nppp_wget_format_duration( float $s ): string {
    if ( $s < 60 ) {
        return sprintf( '%.1fs', $s );
    }
    $s = (int) round( $s );
    $h = intdiv( $s, 3600 );
    $m = intdiv( $s % 3600, 60 );
    $r = $s % 60;
    return ( $h > 0 ? $h . 'h ' : '' ) . $m . 'm ' . $r . 's';
}

/**
 * Convert wget2 verbose output to the lines the plugin parses:
 *
 *   HTTP response 200 OK [URL]            -> "YYYY-MM-DD HH:MM:SS URL:URL [0/0] -> "-" [1]"
 *   HTTP ERROR response 404 Text [URL]    -> "URL:" newline "YYYY-MM-DD HH:MM:SS ERROR 404: Text."
 *   Downloaded: N files, 1.10K bytes, ... -> "FINISHED --ts--", "Total wall clock time: Xs",
 *                                            "Downloaded: N files, 1.10K in Xs (Y KB/s)"
 *
 * wget2 prints no per-line timestamp, so every line carries the log file's mtime (a running crawl:
 * "now"). FINISHED is emitted only when wget2's final "Downloaded:" line exists, i.e. only for a run
 * that ended by itself, never for a killed one (same meaning as wget1's FINISHED).
 * Already-normalised / wget1 text is returned unchanged.
 */
function nppp_wget_log_normalize( string $raw, int $mtime, int $started = 0 ): string {
    if ( ! nppp_wget_log_is_wget2( $raw ) ) {
        return $raw;
    }

    $stamp   = nppp_wget_format_stamp( $mtime > 0 ? $mtime : time() );
    $elapsed = ( $started > 0 && $mtime >= $started && ( $mtime - $started ) < 604800 ) ? (float) ( $mtime - $started ) : null;
    $out     = [];
    $tail    = [];
    $ok      = 0; // 2xx pages that reach the log (robots.txt excluded): wget1's "Downloaded: N files"

    foreach ( preg_split( '~\r?\n~', $raw ) as $line ) {
        $line = rtrim( $line );

        if ( preg_match( '~^HTTP response (\d{3})\b.*?\[((?:https?|ftps?)://.+)\]$~', $line, $m ) ) {
            // wget2 2.1.x / 2.2.x fetch /robots.txt first even with --no-robots (2.3.0 does not); wget1
            // with robots=off never does. Not a crawled page: keep it out of the log.
            if ( nppp_wget_is_robots_url( $m[2] ) ) {
                continue;
            }
            // Only final 2xx answers are logged by wget1 -nv; redirects / 304 are not.
            if ( $m[1][0] === '2' ) {
                $ok++;
                $out[] = $stamp . ' URL:' . nppp_wget_log_encode_url( $m[2] ) . ' [0/0] -> "-" [1]';
            }
            continue;
        }

        if ( preg_match( '~^HTTP ERROR response (\d{3}) (.*?)\s*\[((?:https?|ftps?)://.+)\]$~', $line, $m ) ) {
            if ( nppp_wget_is_robots_url( $m[3] ) ) {
                continue; // a missing robots.txt is no broken link
            }
            $m[3]  = nppp_wget_log_encode_url( $m[3] );
            $out[] = $m[3] . ':';
            $out[] = $stamp . ' ERROR ' . $m[1] . ': ' . ( $m[2] !== '' ? $m[2] : 'Error' ) . '.';
            continue;
        }

        if ( preg_match( '~^Downloaded: (\d+) files?, ([\d.]+)\s*([KMGT]?) bytes~', $line, $m ) ) {
            $mult  = [ '' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776 ];
            $bytes = (float) $m[2] * $mult[ $m[3] ];
            $size  = $m[3] === '' ? (string) (int) $m[2] : $m[2] . $m[3];

            // wget1 prints FINISHED / Downloaded only when bytes were downloaded; a crawl whose start URL failed
            // (404 / 5xx / unreachable) must stay "incomplete", or the snapshot would be overwritten by an empty one.
            // wget2 2.1.x / 2.2.x also count the /robots.txt they fetch despite --no-robots: use the pages counted
            // above, not wget2's own file counter, or a failed start URL would still look "finished".
            if ( $ok < 1 || $bytes < 1 ) {
                continue;
            }
            $tail[] = 'FINISHED --' . $stamp . '--';
            if ( $elapsed !== null ) {
                $tail[] = 'Total wall clock time: ' . nppp_wget_format_duration( $elapsed );
                $rate   = $elapsed > 0 ? $bytes / $elapsed / 1024 : 0.0;
                $tail[] = 'Downloaded: ' . $ok . ' files, ' . $size . ' in ' . nppp_wget_format_duration( $elapsed )
                        . ' (' . ( $rate >= 1024 ? sprintf( '%.2f MB/s', $rate / 1024 ) : sprintf( '%.2f KB/s', $rate ) ) . ')';
            } else {
                $tail[] = 'Downloaded: ' . $ok . ' files, ' . $size;
            }
            continue;
        }

        // Drop wget2 chatter that has no wget1 counterpart; keep anything else (real error text).
        if ( $line === '' || preg_match( '~^(\[\d+\] Downloading |Enqueu(?:e|ing) |Adding URL|URI content encoding|Saving |URL \'.*\' not followed|Job reached max tries)~', $line ) ) {
            continue;
        }
        $out[] = $line;
    }

    return implode( "\n", array_merge( $out, $tail ) ) . "\n";
}

/**
 * Read a wget log (live log or snapshot) through the WP filesystem and return wget1-format text.
 * Drop-in for $wp_filesystem->get_contents( $path ); wget1 logs pass through untouched.
 *
 * @return string|false
 */
function nppp_wget_log_read( $wp_filesystem, string $path ) {
    $raw = $wp_filesystem->get_contents( $path );
    if ( ! is_string( $raw ) || $raw === '' ) {
        return $raw;
    }
    if ( ! nppp_wget_log_is_wget2( $raw ) ) {
        return $raw;
    }
    $started = (int) get_option( 'nppp_wget2_started_at', 0 );
    $sf      = nppp_wget_start_file();
    if ( $sf !== '' && $wp_filesystem->exists( $sf ) ) {
        $sf_val = (int) trim( (string) $wp_filesystem->get_contents( $sf ) );
        if ( $sf_val >= $started ) { // the shell writes it after PHP launched the crawl
            $started = $sf_val;
        }
    }
    return nppp_wget_log_normalize( $raw, (int) $wp_filesystem->mtime( $path ), $started );
}
