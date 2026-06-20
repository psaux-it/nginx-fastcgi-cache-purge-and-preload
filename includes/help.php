<?php
/**
 * Help and FAQ renderer for Nginx Cache Purge Preload
 * Description: Outputs plugin documentation, onboarding guidance, and support information in admin.
 * Version: 2.1.7
 * Author: Hasan CALISIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Function to output the FAQ HTML
function nppp_my_faq_html() {
    ob_start();
    $image_url_space = plugins_url('/admin/img/lost_in_space.png', dirname(__FILE__));
    $image_url_ad = plugins_url('/admin/img/logo_ad.png', dirname(__FILE__));
    ?>

    <style>
      .responsive-table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-size: 14px;
        text-align: left;
      }

      .responsive-table th, .responsive-table td {
        border: 1px solid #ddd;
        padding: 12px;
      }

      .responsive-table th {
        background-color: #f4f4f4;
        font-weight: bold;
      }

      .responsive-table tr:nth-child(even) {
        background-color: #f9f9f9;
      }

      .responsive-table tr:hover {
        background-color: #f1f1f1;
      }

      .responsive-table td:nth-child(1) {
        font-weight: bold;
        color: #333;
      }

      @media (max-width: 768px) {
        .responsive-table {
          font-size: 14px;
        }
      }
    </style>

    <div class="nppp-premium-container" style="display: none;">
        <div class="nppp-premium-wrap">
            <div id="nppp-accordion" class="accordion">
                <h3 class="nppp-question">Why plugin not functional on my environment?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">This WordPress plugin is compatible exclusively with Nginx web servers running on Linux-powered systems. Additionally, the <strong>shell_exec</strong> function must be enabled and unrestricted. Consequently, the plugin may not operate fully on shared hosting environments where native <strong>Linux commands</strong> are blocked from running via PHP.</p>
                        <p style="font-size: 14px;">Moreover, granting the correct permissions to the PHP process owner (<strong>PHP-FPM-USER</strong>) is essential for the proper functioning of the purge and preload operations. This is necessary in isolated user environments that have two distinct user roles: the <strong>WEBSERVER-USER</strong> (nginx or www-data) and the <strong>PHP-FPM-USER</strong>.</p>
                        <p style="font-size: 14px;">📌 If you see warnings or if any plugin settings or tabs are disabled, this could indicate permission issues, an unsupported environment, or missing dependencies that the plugin requires to function properly.</p>
                    </div>
                </div>

                <h3 class="nppp-question">I've checked everything, but preloading still doesn't work and Nginx cannot be detected — could open_basedir be the cause?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>PHP <code>open_basedir</code> and NPP</strong></h3>
                        <p>Yes. If <code>open_basedir</code> is active in your PHP configuration, it silently restricts every filesystem access PHP makes — including the paths NPP must reach.</p>
                        <p><strong>NPP performs an automatic compatibility check</strong> when <code>open_basedir</code> is active. If required paths are missing, you will see a warning on the plugin <strong>Settings</strong> page that lists exactly which paths need to be added to your <code>open_basedir</code> configuration.</p>

                        <h4><strong>How does NPP determine required paths?</strong></h4>
                        <p>The plugin dynamically builds the list of paths based on your actual WordPress installation and configuration. The categories below are checked:</p>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Path category</th>
                                    <th>Why NPP needs it?</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>WordPress root (<code>ABSPATH</code>) and its parent</td>
                                    <td>Entire WordPress installation must be functional. Parent only needed on specific wp installations.</td>
                                </tr>
                                <tr>
                                    <td><code>WP_CONTENT_DIR</code> / uploads directory</td>
                                    <td>NPP keeps runtime files and logs in uploads directory. Usually <code>ABSPATH</code> already covers.</td>
                                </tr>
                                <tr>
                                    <td>Your configured Nginx cache path</td>
                                    <td>NPP reads, writes, and deletes nginx cache files.</td>
                                </tr>
                                <tr>
                                    <td>FUSE source path (if bindfs is used)</td>
                                    <td>NPP reads the real cache directory behind the FUSE mount when <code>rg</code> scans are executed through <code>safexec</code>.</td>
                                </tr>
                                <tr>
                                    <td><code>/proc/</code></td>
                                    <td>FUSE detection, preload progress job and various system resource metric reads.</td>
                                </tr>
                                <tr>
                                    <td><code>/dev/null</code></td>
                                    <td>Preload premature process check — only required if <code>proc_open</code> is available.</td>
                                </tr>
                                <tr>
                                    <td><code>/tmp/</code></td>
                                    <td>Preload process — temporarily hold artifacts here during preloading.</td>
                                </tr>
                                <tr>
                                    <td>Main nginx configuration directories (nginx.conf)</td>
                                    <td>NPP parse Nginx configuration, paths, keys, users for Status tab metrics and also needed for strict‑mode Nginx detection.</td>
                                </tr>
                                <tr>
                                    <td>aaPanel‑specific directories <em>(only if detected)</em></td>
                                    <td><code>/www/server/nginx/conf</code> and <code>/www/server/panel/vhost/nginx</code> — required on aaPanel environments.</td>
                                </tr>
                            </tbody>
                        </table>

                        <h4><strong>What should I do when the <code>open_basedir</code> warning appears?</strong></h4>
                        <ol>
                            <li>Go to the <strong>Settings</strong> page of NPP. The warning will show an exact list of missing paths.</li>
                            <li>Add those paths to your <code>open_basedir</code> configuration (in your PHP‑FPM pool config or server‑wide <code>php.ini</code>).</li>
                            <li>Reload PHP‑FPM: <code>systemctl reload php-fpm</code> (or the equivalent for your environment).</li>
                            <li>Return to the NPP Settings tab — the warning should disappear and full functionality will be restored.</li>
                        </ol>

                        <h4><strong>Example: typical minimal <code>open_basedir</code> entry</strong></h4>
                        <p>Every environment is different. The plugin warning will give you the exact list, but here is a typical baseline you can start from (adjust <code>ABSPATH</code> and cache path):</p>
                        <pre>php_admin_value[open_basedir] =
    /var/www/yoursite.com/ :
    /tmp/ :
    /var/cache/nginx/ :
    /proc/ :
    /dev/null :
    /etc/nginx/nginx.conf :</pre>

                        <h4><strong>Important notes</strong></h4>
                        <p>📌 On Docker‑based deployments, ensure the actual nginx.conf directory (e.g., <code>/usr/local/etc/nginx/</code>) is included, as the plugin probes multiple standard locations. The compatibility warning will tell you if one is missing.</p>
                    </div>
                </div>

                <h3 class="nppp-question">How does NPP purge the cache and what is the HTTP Purge?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>NPP Purge Workflow</strong></h3>
                        <p>NPP uses a layered purge strategy for both single-URL and full-cache purges. For single-URL purges (manual, auto-purge on update, related URLs) it tries each path in order and stops as soon as one succeeds. Purge All tries the HTTP fast path first when HTTP Purge is enabled, and falls back to filesystem operations otherwise.</p>

                        <h4><strong>Fast-Path 1 — HTTP Purge (optional)</strong></h4>
                        <p>If <strong>HTTP Purge</strong> is enabled in settings and the <code>ngx_cache_purge</code> Nginx module is detected, NPP sends an HTTP request to the module's purge endpoint. The module removes the cache entry from shared memory and disk atomically. On HTTP 200 the filesystem is never touched — the purge is complete. On any other response NPP falls through to the next path automatically.</p>

                        <h4><strong>Fast-Path 2 — Index lookup</strong></h4>
                        <p>NPP maintains a persistent URL→Filepath index built during Preloading. If the URL is found in the index and the file still exists, NPP deletes it directly with no directory scan needed.</p>

                        <h4><strong>Fast-Path 3|4 — Recursive filesystem scan (PHP and RG)</strong></h4>
                        <p>If neither fast-path succeeds, NPP walks the entire Nginx cache directory, reads each file's cache key header, and deletes the matching entry. This is the original workflow and remains the fallback for all environments.</p>

                        <h4><strong>Purge All</strong></h4>
                        <p>If HTTP Purge is enabled and a dedicated Purge All location block is configured in Nginx (via the <code>ngx_cache_purge</code> module's <code>purge_all</code> directive, module version 3.0.2+), NPP first asks Nginx to purge the entire cache over HTTP. On HTTP 200 Nginx has already deleted all cache files and shared memory metadata atomically, and NPP's filesystem purge is skipped entirely. On any other response — including HTTP 202, which means Nginx queued the purge into a background worker — NPP falls back to its own filesystem-based full purge, recursively removing the entire cache directory contents. If Cloudflare APO Sync or Redis Object Cache Sync is enabled, those are triggered after the purge completes, regardless of which path handled it.</p>

                        <h4><strong>When HTTP Purge is not available</strong></h4>
                        <p>HTTP Purge is entirely optional. If the module is not present, not compiled, or the purge location block is not configured in Nginx, NPP falls back to the index and filesystem paths automatically. The existing workflow is fully preserved — nothing breaks.</p>

                        <h4><strong>How to enable HTTP Purge</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong> and enable <strong>HTTP Purge</strong>. This works out of the box on stacks that include <code>ngx_cache_purge</code> precompiled, such as popular managed hosting platforms, control panels, and servers using the Ubuntu <code>nginx-extras</code> package.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Why does NPP's cache warm get bypassed by real visitors? (Accept-Encoding / Vary Cache Issue)</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>The Problem: NPP Warms Cache, But Cache Get Overwritten, Bypassed or Secondary Cache Generated</strong></h3>

                        <p style="font-size: 14px;">
                            When a response cached by Nginx contains a <code>Vary: Accept-Encoding</code> header,
                            Nginx's cache engine computes an MD5 <em>variant hash</em> from the request's <code>Accept-Encoding</code> value.
                            On a variant hash mismatch, Nginx attempts to open a secondary cache file.
                            If the secondary fetch returns no <code>Vary</code> header, Nginx falls back and
                            <strong>overwrites the primary cache</strong> with the new response — destroying the previously warmed cache.
                        </p>
                        <p style="font-size: 14px;">
                            This means warmed cache entries can be silently destroyed by subsequent requests with a different
                            <code>Accept-Encoding</code> value, causing unnecessary PHP backend hits and cache thrashing.
                            The only correct fix is on the Nginx/PHP side.
                        </p>
                        <p style="font-size: 14px;">
                            This affects <strong>fastcgi_cache, proxy_cache, uwsgi_cache, and scgi_cache</strong> equally.
                        </p>

                        <h3><strong>Root Causes</strong></h3>

                        <p style="font-size: 14px;">
                            The variant hash mismatch mechanism is triggered exclusively when the <strong>upstream response</strong>
                            contains a <code>Vary: Accept-Encoding</code> header that Nginx's cache engine stores.
                            Nginx processes this in <code>ngx_http_upstream_process_vary()</code>, which writes to <code>r-&gt;cache-&gt;vary</code> —
                            the only field that feeds the variant hash engine. There are two independent upstream-side sources:
                        </p>

                        <h4><strong>Root Cause 1 — PHP <code>zlib.output_compression = On</code></strong></h4>
                        <p style="font-size: 14px;">
                            When PHP compresses output itself, it adds <code>Vary: Accept-Encoding</code> to the response
                            <strong>only when the client sends <code>Accept-Encoding: gzip</code> or <code>deflate</code></strong>.
                            For requests sending <code>Accept-Encoding: identity</code> — wget's hardcoded default — PHP treats this identically to no compression support and does not add <code>Vary: Accept-Encoding</code>.
                        </p>
                        <p style="font-size: 14px;">
                            This creates a <strong>cache thrashing</strong> scenario when a real browser reaches a URL before NPP does:
                            the browser's gzip request causes PHP to emit <code>Vary: Accept-Encoding</code>, which Nginx stores in the cache file
                            along with a gzip variant hash. When NPP subsequently warms that URL, its request (<code>Accept-Encoding: identity</code> — wget's hardcoded default)
                            mismatches the stored variant hash, triggers a secondary cache lookup, fetches uncompressed content from PHP
                            (which now emits no <code>Vary</code>), and <strong>overwrites the primary cache file</strong> with
                            uncompressed, Vary-free content — destroying the browser-warmed cache unnecessarily.
                            This cycle repeats on every browser/NPP alternation, causing perpetual cache churn.
                        </p>
                        <p style="font-size: 14px;">
                            📌 <strong>Note:</strong> If NPP always warms a URL before any real browser, Root Cause 1 does not trigger —
                            because PHP never emits <code>Vary: Accept-Encoding</code> for NPP's request, so no variant hash is stored,
                            and browsers receive a clean HIT. The problem only manifests when a browser reaches a URL first.
                        </p>

                        <h4><strong>Root Cause 2 — An upstream component emitting <code>Vary: Accept-Encoding</code> unconditionally</strong></h4>
                        <p style="font-size: 14px;">
                            Any component running <strong>upstream of Nginx's cache engine</strong> — such as a WordPress plugin,
                            a PHP middleware, or an intermediate proxy — that adds <code>Vary: Accept-Encoding</code> to every response
                            regardless of the client's <code>Accept-Encoding</code> value will cause variant hash mismatches for every request.
                            Unlike Root Cause 1, where the header is conditional on the client advertising gzip support,
                            this source adds it unconditionally — including for NPP's requests.
                        </p>
                        <p style="font-size: 14px;">
                            Because NPP's preloader sends <code>Accept-Encoding: identity</code> — the variant hash stored during the warm pass is computed from <code>"identity"</code>.
                            When a real browser subsequently requests
                            the same URL with <code>Accept-Encoding: gzip, deflate, br</code>, Nginx computes a different
                            variant hash, finds no matching cache file, and creates a <strong>second independent cache file</strong>
                            for the same URL — going to PHP instead of serving the NPP-warmed cache.
                            The result is two separate cache files on disk for the same URL, and the NPP-warmed cache
                            is never served to real visitors regardless of warm order.
                        </p>
                        <p style="font-size: 14px;">
                            📌 <strong>Note on <code>gzip_vary on</code>:</strong> Nginx's own <code>gzip_vary on</code> directive
                            does <strong>not</strong> cause variant hash mismatches. It sets the internal <code>r-&gt;gzip_vary</code> flag,
                            which only instructs <code>ngx_http_header_filter_module</code> to write
                            <code>Vary: Accept-Encoding</code> into the raw bytes sent to the client.
                            It never touches <code>r-&gt;cache-&gt;vary</code> — the field that controls the variant hash mechanism.
                            <code>gzip_vary on</code> is a client-facing correctness signal for browsers and downstream proxies,
                            not an instruction to Nginx's own cache engine.
                        </p>

                        <h3><strong>The Fix</strong></h3>

                        <h4><strong>Step 1 — Disable PHP-level compression (php.ini)</strong></h4>
                        <p style="font-size: 14px;">PHP must not compress output or add <code>Vary: Accept-Encoding</code>. Let Nginx handle all compression.</p>
        <pre>; /etc/php/fpm-phpX.Y/php.ini
zlib.output_compression = Off</pre>
                        <p style="font-size: 14px;">Reload PHP-FPM after saving: <code>systemctl reload php-fpm</code></p>
                        <p style="font-size: 14px;">
                            📌 <strong>This step is only required if Root Cause 1 applies to you.</strong>
                            However, disabling it is always the safer and architecturally correct choice,
                            as it ensures PHP never produces compressed output or emits <code>Vary: Accept-Encoding</code>
                            regardless of request order or client type.
                        </p>

                        <h4><strong>Step 2 — Add <code>fastcgi_ignore_headers Vary</code> to your Nginx PHP block</strong></h4>
                        <p style="font-size: 14px;">
                            This directive tells Nginx to ignore the <code>Vary</code> header completely during cache operations.
                            When set, <code>ngx_http_upstream_process_vary()</code> returns immediately without writing to
                            <code>r-&gt;cache-&gt;vary</code>, preventing variant hash storage and all resulting cache overwrites
                            regardless of whether <code>Vary: Accept-Encoding</code> originates from PHP <code>zlib.output_compression</code>,
                            a plugin, or any other upstream component.
                        </p>

<pre>location ~ \.php$ {
    fastcgi_cache_key "$scheme$request_method$host$request_uri";
    fastcgi_pass unix:/var/run/php-fcgi-yoursite.sock;
    include /etc/nginx/fastcgi_params;

    fastcgi_param  HTTP_ACCEPT_ENCODING  "";  # ← strip Accept-Encoding before it reaches PHP
    fastcgi_ignore_headers Vary;              # ← prevents variant hash storage and cache overwrites

    fastcgi_cache YOUR_ZONE;
    fastcgi_cache_valid 30d;
    fastcgi_cache_bypass $skip_cache;
    fastcgi_no_cache $skip_cache;
    fastcgi_cache_use_stale error timeout updating invalid_header http_500 http_503;
    fastcgi_cache_lock on;
}</pre>

                        <p style="font-size: 14px;">
                            <strong>Why both directives?</strong> <code>fastcgi_param HTTP_ACCEPT_ENCODING ""</code> strips the
                            <code>Accept-Encoding</code> header before it reaches PHP — so PHP cannot produce compressed output
                            or emit <code>Vary: Accept-Encoding</code> in the first place.
                            <code>fastcgi_ignore_headers Vary</code> is the universal second line of defence:
                            even if a <code>Vary</code> header arrives from any upstream source
                            (PHP, a plugin, or a proxy layer),
                            Nginx will not store it in the cache file and will not activate the variant hash mechanism.
                            Together they guarantee a single stable cache file per URL regardless of what the client sends
                            or which request arrives first.
                        </p>
                        <p style="font-size: 14px;">
                            📌 If you already set <code>fastcgi_param HTTP_ACCEPT_ENCODING ""</code> in your shared
                            <code>fastcgi_params</code> file, you do not need to repeat it here — it applies automatically
                            via <code>include /etc/nginx/fastcgi_params</code>.
                        </p>
                        <p style="font-size: 14px;">
                            Reload Nginx after saving: <code>nginx -t &amp;&amp; systemctl reload nginx</code>
                        </p>

                        <h4><strong>📌 For <code>proxy_cache</code> users (Nginx → Apache / HTTP backend)</strong></h4>
                        <p style="font-size: 14px;">
                            If your Nginx configuration uses <code>proxy_pass</code> instead of <code>fastcgi_pass</code>,
                            replace <strong>Step 2</strong> with the following equivalent configuration:
                        </p>

<pre>location / {
    proxy_pass http://127.0.0.1:8288;  # Your Apache backend

    # CACHE ZONE SETUP
    proxy_cache YOUR_ZONE;
    proxy_cache_key "$scheme$host$request_uri";
    proxy_cache_valid 200 30d;
    proxy_cache_bypass $skip_cache;
    proxy_no_cache $skip_cache;

    # ===========================================================
    # THE CRITICAL FIX FOR proxy_cache (Variant Hash Prevention)
    # ===========================================================

    # 1. Strip the Accept-Encoding header before it reaches Apache.
    #    (Prevents Apache/mod_deflate from emitting Vary in the first place)
    proxy_set_header Accept-Encoding "";

    # 2. Ignore the Vary header during cache operations.
    #    Prevents Nginx from writing r->cache->vary and stops variant hashing.
    proxy_ignore_headers Vary;

    # 3. [OPTIONAL BUT RECOMMENDED] Hide Vary from the client/probe.
    #    Nginx internally ignores Vary (due to #2), but it might still forward
    #    the header to the browser or monitoring tools.
    #    Adding this silences false-positive warnings in plugins like NPP
    #    without affecting the cache engine.
    proxy_hide_header Vary;

    # ... rest of your proxy settings
}</pre>

                        <p style="font-size: 14px;">
                            <strong>🛡️ Why <code>proxy_hide_header Vary;</code> is the "Final Polish" for proxy setups</strong><br>
                            <code>proxy_ignore_headers Vary;</code> protects the cache (stops variant hashing).<br>
                            <code>proxy_hide_header Vary;</code> protects your monitoring (stops the header from reaching the client).<br><br>
                            Without <code>hide_header</code>, Nginx will still forward the backend's <code>Vary</code> header to the client.
                            While this <strong>does not</strong> create a double cache (thanks to <code>ignore_headers</code>),
                            it will trigger <strong>false-positive warnings</strong> in environment checkers (like NPP's pre‑flight probe)
                            that simply look for the presence of the header.
                            Adding <code>proxy_hide_header Vary;</code> is the safe, recommended way to silence these warnings
                            without altering cache behavior.
                        </p>

                        <h4><strong>Step 3 — Let Nginx handle gzip (nginx.conf http block)</strong></h4>
                        <p style="font-size: 14px;">With PHP compression disabled, Nginx becomes the sole compression layer — which is the correct architecture. Confirm these are present in your <code>http {}</code> block:</p>
<pre>gzip on;
gzip_vary on;
gzip_proxied any;
gzip_types text/plain text/css application/javascript application/json text/xml application/xml text/javascript;</pre>

                        <p style="font-size: 14px;">
                            <code>gzip_vary on</code> adds <code>Vary: Accept-Encoding</code> to responses sent to clients —
                            informing browsers and downstream proxies that the response content varies by encoding.
                            This does <strong>not</strong> affect Nginx's own cache variant mechanism:
                            <code>gzip_vary on</code> writes only to the client-facing response bytes via <code>r-&gt;gzip_vary</code>,
                            and never writes to <code>r-&gt;cache-&gt;vary</code> which controls variant hash storage.
                            Nginx compresses responses on the fly per-client from a single cached uncompressed entry.
                            One cache file, served correctly to all clients.
                        </p>

                        <h3><strong>Why <code>fastcgi_ignore_headers Vary</code> is Safe Here</strong></h3>
                        <p style="font-size: 14px;">
                            Normally suppressing <code>Vary</code> would risk serving gzip-compressed content to clients that cannot decompress it.
                            But with <code>zlib.output_compression = Off</code>, PHP never produces compressed output — so there is only one content variant.
                            Nginx then compresses on the fly per-client using its own <code>gzip</code> module. One cache file, served correctly to all clients.
                        </p>

                        <h3><strong>Before / After</strong></h3>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Scenario</th>
                                    <th>Cache files per URL</th>
                                    <th>NPP warm hit for real visitors</th>
                                    <th>Risk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>zlib.output_compression = On</code>, no fix</td>
                                    <td>1 (thrashing — the no-<code>Vary</code> response from NPP's warm pass is written back to the primary cache key path, overwriting the browser-cached gzip variant)</td>
                                    <td>⚠️ Unstable — warmed cache overwritten when browser hits first</td>
                                    <td>Cache churn, unnecessary PHP hits, warm entries silently destroyed</td>
                                </tr>
                                <tr>
                                    <td>Plugin or upstream proxy emitting <code>Vary: Accept-Encoding</code> unconditionally, no fix</td>
                                    <td>2 (two variant files on disk — NPP's warm stored at <code>MD5("identity")</code> key, browser's response stored at <code>MD5("gzip, deflate, br")</code> key; neither overwrites the other)</td>
                                    <td>❌ Miss — browser request computes a different variant hash and bypasses the NPP-warmed cache entirely, going to PHP instead</td>
                                    <td>NPP preload permanently ineffective; real visitors always trigger a PHP backend hit regardless of warm order</td>
                                </tr>
                                <tr>
                                    <td><code>gzip on; gzip_vary on;</code> (nginx-side only, no upstream Vary)</td>
                                    <td>1 ✅</td>
                                    <td>✅ HIT — NPP warmed cache is served</td>
                                    <td>None — <code>gzip_vary</code> does not affect cache variant engine</td>
                                </tr>
                                <tr>
                                    <td><code>zlib.output_compression = Off</code> + <code>fastcgi_ignore_headers Vary</code></td>
                                    <td>1 ✅</td>
                                    <td>✅ HIT — NPP warmed cache is served</td>
                                    <td>None</td>
                                </tr>
                            </tbody>
                        </table>

                        <p style="font-size: 14px;">
                            📌 <strong>Note:</strong> This issue exists on all Nginx cache types — <code>fastcgi_cache</code>, <code>proxy_cache</code>, <code>uwsgi_cache</code>, <code>scgi_cache</code>.
                            Apply <code>fastcgi_ignore_headers Vary</code> / <code>proxy_ignore_headers Vary</code> / <code>uwsgi_ignore_headers Vary</code> accordingly for your setup.
                        </p>
                    </div>
                </div>

                <h3 class="nppp-question">How do I configure Nginx for HTTP Purge and what options does NPP provide?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Nginx Configuration for HTTP Purge</strong></h3>
                        <p>HTTP Purge requires the <code>ngx_cache_purge</code> module to be compiled into Nginx and a <strong>dedicated purge location block</strong> added to your Nginx configuration. Without both, the feature cannot work — but NPP falls back to filesystem purge automatically so nothing breaks.</p>

                        <h4><strong>Why a dedicated purge location?</strong></h4>
                        <p>NPP uses a <code>GET</code> request to trigger cache purges. This design choice was made to provide <strong>maximum compatibility with all cache key formats</strong>, including those that include <code>$request_method</code> (e.g. <code>$scheme$request_method$host$request_uri</code>). When a <code>GET</code> request is sent to a dedicated purge location, Nginx bypasses method checks and purges the correct cache entry regardless of the key format.</p>
                        <p><strong>Why a dedicated location is also a security best practice:</strong></p>
                        <ul>
                            <li><strong>Isolation:</strong> Purge requests are handled in a separate <code>location</code> block, completely separate from your normal PHP processing.</li>
                            <li><strong>IP Restriction:</strong> You can (and should) restrict purge access to trusted IP addresses (e.g., your server's localhost or Docker network).</li>
                            <li><strong>No Accidental Caching:</strong> A <code>GET</code> request to a dedicated purge location will never be cached or passed to PHP, eliminating any risk of warming the cache unintentionally.</li>
                        </ul>
                        <p>⚠️ <strong>Important:</strong> HTTP Purge will <strong>not</strong> work if you use the inline <code>fastcgi_cache_purge on;</code> directive inside your PHP location block. This is because the module's access handler expects the <code>PURGE</code> method for inline setups, while NPP sends <code>GET</code>. For this reason, we strongly recommend the dedicated location configuration shown below.</p>

                        <h4><strong>Required Nginx config</strong></h4>
                        <p>You need two things in your Nginx server block: a <code>fastcgi_cache_path</code> with a named zone, and a location block that handles purge requests using that zone. NPP compatible example:</p>

<pre>## In the http {} block:
fastcgi_cache_path /var/cache/nginx levels=1:2 keys_zone=NPP:100m max_size=1g inactive=30d;
</pre>

<pre>
## In the server {} block:
location ~ \.php$ {
    fastcgi_cache_key "$scheme$request_method$host$request_uri";

    try_files $uri =404;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_index index.php;
    fastcgi_pass wordpress-fpm:9001;
    include /etc/nginx/fastcgi_params;

    fastcgi_cache_bypass $skip_cache;
    fastcgi_no_cache $skip_cache;
    fastcgi_cache NPP;
    fastcgi_cache_valid 200 30d;
    fastcgi_cache_valid 404 1m;
    fastcgi_cache_use_stale error timeout updating http_500 http_503;
    fastcgi_cache_lock on;
    fastcgi_cache_background_update on;
    fastcgi_ignore_headers Vary;
}

## Purge Single location - (requires ngx_cache_purge module ≥ 2.3)
location ~ ^/purge(/.*) {
    allow 127.0.0.1;       # Only allow local requests
    allow 172.16.0.0/12;   # Docker network (adjust as needed)
    deny all;              # Deny everyone else

    # IMPORTANT: $is_args$args handles query string compatibility
    fastcgi_cache_purge NPP "$scheme$request_method$host$1$is_args$args";
}

## Purge All location — for full cache clear (requires ngx_cache_purge module ≥ 3.0.2)
location = /purge_all {
    allow 127.0.0.1;       # Only allow local requests
    allow 172.16.0.0/12;   # Docker network (adjust as needed)
    deny all;              # Deny everyone else

    fastcgi_cache       NPP;
    fastcgi_cache_key   "$scheme$request_method$host$request_uri";
    fastcgi_cache_purge GET purge_all from all;
}</pre>
                        <p>🔒 <strong>Security Tip:</strong> Always restrict the <code>allow</code> directive to your server's internal IP addresses. Never expose the purge location to the public internet. Because NPP use <code>GET</code> not <code>PURGE</code></p>

                        <p>📌 For a complete, production‑ready Nginx configuration including cache exclusion rules, rate limiting, and security headers, see the <a href="https://github.com/psaux-it/wordpress-nginx-cache-docker" target="_blank" rel="noopener">NPP Docker Stack Repository</a>.</p>

                        <h4><strong>How NPP builds the purge URL — Single‑URL Purge</strong></h4>
                        <p>When NPP purges a single page such as <code>https://example.com/my-page/?color=blue</code>:</p>
                        <ol>
                            <li>It constructs the purge URL: <code>https://example.com/purge/my-page/?color=blue</code><br>
                                — using the <strong>Purge Custom Base URL</strong> (if set) or the site’s home URL, then appending the <strong>Purge Single Path</strong> (default <code>purge</code>) and the page’s path + query string.</li>
                            <li>It sends a <code>GET</code> request to that URL.</li>
                            <li>Nginx matches the <code>location ~ ^/purge(/.*)</code> block, captures the path after <code>/purge</code> in <code>$1</code>, and <code>$is_args$args</code> restores the original query string — so the cache key matches the stored entry exactly, even for URLs with filters, search, or pagination.</li>
                            <li>Nginx returns <code>200</code> (purge successful) or another status code (see fallback behavior below).</li>
                        </ol>

                        <h4><strong>How NPP builds the purge URL — Purge All</strong></h4>
                        <p>When NPP purges the entire cache (Purge All):</p>
                        <ol>
                            <li>It constructs the purge URL: <code>https://example.com/purge_all</code><br>
                                — using the <strong>Purge Custom Base URL</strong> (if set) or the site’s home URL, then appending the <strong>Purge All Path</strong> (default <code>purge_all</code>).</li>
                            <li>It sends a <code>GET</code> request to that URL.</li>
                            <li>Nginx’s <code>location = /purge_all</code> block — configured with <code>fastcgi_cache_purge GET purge_all from all;</code> — deletes every file in the cache zone atomically.</li>
                            <li>Nginx returns <code>200</code> when the purge completes (or <code>202</code> if the module queued the work to a background worker).</li>
                        </ol>
                        <p>If a purge endpoint returns anything other than <code>200</code>, NPP automatically falls back to its filesystem‑based purge — your cache is still cleared, just a bit slower.</p>

                        <h4><strong>NPP settings for HTTP Purge</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong> and enable <strong>HTTP Purge</strong>. The following options let you customise both purge endpoints:</p>

                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Setting</th>
                                    <th>Default</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Purge Custom Base URL</strong></td>
                                    <td>(empty)</td>
                                    <td>Overrides the base URL for both single and purge‑all requests. Enter only the scheme and host, e.g. <code>http://nginx-internal:8080</code>. The <strong>Single</strong> and <strong>All</strong> paths defined below are appended automatically.<br>
                                        Leave empty to auto‑build URLs from your site URL. Set this when the purge endpoint differs from your public site URL — Dockerized environments, separate Nginx server, non-standard port, or panel environments with a custom Nginx layer</td>
                                </tr>
                                <tr>
                                    <td><strong>Purge Single Path</strong></td>
                                    <td><code>purge</code></td>
                                    <td>The URI path prefix for single‑URL purges. Must match the regex location in nginx (e.g. <code>location ~ ^/purge(/.*)</code>). Change this only if your purge location uses a different prefix.</td>
                                </tr>
                                <tr>
                                    <td><strong>Purge All Path</strong></td>
                                    <td><code>purge_all</code></td>
                                    <td>The exact URI path for full cache purge. Must match the exact location in nginx (e.g. <code>location = /purge_all</code>). This is used for the Purge All HTTP fast‑path and is independent of the single‑purge path.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <h3 class="nppp-question">What is RG Purge and how does it accelerate single‑URL purges?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>RG Purge – Turbocharged Cache Purging</strong></h3>
                        <p><strong>RG Purge</strong> is an optional fast‑path that uses <strong>ripgrep (rg)</strong> — a blazing‑fast, line‑oriented search tool — to locate cache files on disk. It replaces the traditional recursive PHP directory scan (Fast‑Path 4) with a single, highly optimised system call, dramatically reducing the time and I/O required to find and delete cached pages.</p>

                        <h4><strong>Why RG Purge matters</strong></h4>
                        <p>In a standard Nginx cache, a single‑page purge must locate one or more cache files among potentially hundreds of thousands of files. The default PHP recursive iterator (Fast‑Path 4) walks the entire cache directory tree, calling <code>is_file()</code> and reading file headers one by one. On large caches, this can take <strong>30–60 seconds</strong> and consume substantial CPU and disk I/O.</p>
                        <p><strong>RG Purge reduces this to 1–2 seconds</strong> regardless of cache size. It does this by delegating the search to <code>rg</code>, which is written in Rust and optimised for parallel directory traversal, memory‑mapped I/O, and early exit.</p>

                        <h4><strong>Architecture – How RG Purge fits into the purge workflow</strong></h4>
                        <p>NPP's single‑URL purge follows a layered fallback strategy. RG Purge sits at <strong>Fast‑Path 3</strong>:</p>
                        <ol>
                            <li><strong>FP1 – HTTP Purge</strong> (if enabled) – asks Nginx to purge via the <code>ngx_cache_purge</code> module.</li>
                            <li><strong>FP2 – Index lookup</strong> – consults the persistent URL→filepath index built during preloading.</li>
                            <li><strong>FP3 – RG Purge</strong> – uses <code>rg</code> to scan the cache directory for the target URL(s).</li>
                            <li><strong>FP4 – PHP recursive scan</strong> – the original fallback, used only if RG Purge is unavailable or fails.</li>
                        </ol>
                        <p>RG Purge is <strong>entirely optional</strong>. If <code>rg</code> is not installed or the feature is disabled in settings, NPP seamlessly falls through to FP4. There is no change to the existing filesystem purge logic — RG Purge simply makes it faster when available.</p>

                        <h4><strong>Technical workflow of RG Purge</strong></h4>
                        <ol>
                            <li>NPP builds a combined regular expression that matches the cache key lines of all pending URLs (primary + related).</li>
                            <li>It executes a single <code>rg</code> command that scans the entire cache directory, printing only file paths where the <code>KEY:</code> header matches the pattern.</li>
                            <li>The output is parsed, and each file path is validated and (if necessary) translated from a FUSE source path to a writable mount point.</li>
                            <li>All matching cache files are deleted in bulk, and their paths are written back to the URL→filepath index for future instant purges.</li>
                        </ol>
                        <p>Because <code>rg</code> respects the Linux page cache, subsequent scans are even faster for a warm cache directory.</p>

                        <h4><strong>When should I enable RG Purge?</strong></h4>
                        <p>Enable RG Purge if <strong>any</strong> of the following apply to your site:</p>
                        <ul>
                            <li>Your Nginx cache contains more than <strong>1,000 files</strong>.</li>
                            <li>Single‑page purges (manual, auto‑purge, or front‑end actions) feel sluggish or time out.</li>
                            <li>You are using a FUSE mount (bindfs) and want to avoid the overhead of PHP walking a virtual filesystem.</li>
                            <li>You want to reduce CPU / I/O spikes caused by large directory scans.</li>
                        </ul>
                        <p>RG Purge is <strong>highly recommended for any production site</strong> with a non‑trivial cache size. The performance gains are immediate and there is no downside — if <code>rg</code> is missing, NPP falls back silently to the standard PHP scan.</p>

                        <h4><strong>How to enable RG Purge</strong></h4>
                        <ol>
                            <li>Install <strong>ripgrep</strong> on your server:<br>
                                <code>apt install ripgrep</code> (Debian/Ubuntu) or <code>dnf install ripgrep</code> (RHEL/Fedora).
                            </li>
                            <li>Go to <strong>Settings → NPP Settings → Advanced</strong> and turn on <strong>RG Purge</strong>.</li>
                            <li>The toggle will show <em>Unavailable</em> if <code>rg</code> is not detected. Once installed, refresh the page and enable it.</li>
                        </ol>
                        <p>After enabling, single‑page purges will automatically use RG Purge. No further configuration is required.</p>

                        <h4><strong>RG Purge and safexec</strong></h4>
                        <p>If your cache directory is a FUSE mount (e.g., bindfs) and the PHP process lacks read access to the underlying source directory, NPP will attempt to use <strong>safexec</strong> to run <code>rg</code> with elevated read privileges. This ensures RG Purge works even in isolated multi‑user environments. If safexec is not available, RG Purge gracefully falls back to scanning the FUSE mount directly (slower, but still faster than PHP).</p>

                        <h4><strong>Troubleshooting</strong></h4>
                        <p><strong>Q: The RG Purge toggle shows "Unavailable".</strong><br>
                        A: The <code>rg</code> binary is not installed or not in the <code>PATH</code>. Install ripgrep via your package manager and refresh the page.</p>

                        <p><strong>Q: Purges are still slow after enabling RG Purge.</strong><br>
                        A: Check the <strong>Status</strong> tab to confirm <code>rg</code> is detected. If you are using a FUSE mount, ensure safexec is installed and SUID‑root (see the safexec FAQ). If neither is available, RG Purge will fall back to the PHP scan.</p>

                        <p><strong>Q: Does RG Purge work with Purge All?</strong><br>
                        A: No. Purge All either completes via the HTTP fast path (when HTTP Purge is enabled and Nginx confirms completion) or falls back to a recursive filesystem deletion — RG Purge applies only to single‑URL and related‑URL purges, never to Purge All.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Why is the Advanced tab slow to load when I have many cached URLs?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Advanced Tab Load Performance</strong></h3>
                        <p>Every time you open the <strong>Advanced</strong> tab, NPP performs a full scan of your Nginx cache directory to build the cached-URL table — reading the <code>KEY:</code> header from every cache file to extract the URL, content type, and variant count. On large caches this scan is the dominant cost. The method NPP uses depends entirely on which tools are available on the host or container running WordPress/PHP-FPM.</p>

                        <h4><strong>The three scanning paths</strong></h4>
                        <p><strong>No ripgrep installed:</strong> NPP falls back to a PHP <code>RecursiveDirectoryIterator</code> loop — opening and reading each cache file one at a time from userland PHP. This is the slowest method and is heavily penalised by FUSE filesystem overhead in containerised environments.</p>
                        <p><strong>ripgrep (<code>rg</code>) installed, no safexec:</strong> NPP delegates the scan to <code>rg</code>. ripgrep uses parallel, memory-mapped I/O and is significantly faster than the PHP loop. However, if your cache path is a FUSE mount (bindfs), <code>rg</code> still walks through the FUSE virtual filesystem — faster than PHP, but still subject to FUSE overhead.</p>
                        <p><strong>ripgrep + safexec both installed (FUSE environment):</strong> NPP runs <code>rg</code> via safexec against the <strong>real source path on disk</strong>, completely bypassing the FUSE layer. This is the fastest possible scan path and the configuration that is <strong>strongly recommended</strong> for any containerised or FUSE-mounted deployment.</p>

                        <h4><strong>Real-world benchmark — 8,000 cached URLs, containerised environment with FUSE mount (bindfs)</strong></h4>
                        <table class="responsive-table">
                            <thead>
                                <tr>
                                    <th>Setup</th>
                                    <th>Scanning Method</th>
                                    <th>Scanned Path</th>
                                    <th>Advanced Tab Load Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>No ripgrep, no safexec</strong></td>
                                    <td>PHP <code>RecursiveDirectoryIterator</code></td>
                                    <td>FUSE mount (slow)</td>
                                    <td>⚠️ ~21 seconds</td>
                                </tr>
                                <tr>
                                    <td><strong>ripgrep + safexec installed</strong></td>
                                    <td><code>rg</code> via safexec</td>
                                    <td>Real source path (FUSE bypassed)</td>
                                    <td>✅ ~5 seconds</td>
                                </tr>
                            </tbody>
                        </table>
                        <p>Installing both tools reduces Advanced tab load time by <strong>~76%</strong> (from ~21 s to ~5 s) on a real containerised production stack with 8,000 cached URLs. The gains grow proportionally as cache size increases.</p>

                        <h4><strong>Why FUSE makes it dramatically worse</strong></h4>
                        <p>A FUSE mount (bindfs) introduces a per-syscall context-switch overhead because every file <code>open()</code> and <code>read()</code> is bridged through a userspace FUSE daemon. When PHP walks 8,000+ files via <code>RecursiveDirectoryIterator</code> over a FUSE mount, each individual file operation crosses the FUSE boundary — multiplying the I/O cost by orders of magnitude compared to reading the same files from the underlying native filesystem. <strong>ripgrep via safexec bypasses this entirely</strong> by scanning the real source directory, with zero FUSE crossings.</p>

                        <h4><strong>How to get maximum performance</strong></h4>
                        <ol>
                            <li>
                                Install <strong>ripgrep</strong> on the host or inside the container running PHP-FPM:
                                <pre>apt install ripgrep       # Debian / Ubuntu
dnf install ripgrep       # RHEL / Fedora
apk add ripgrep           # Alpine Linux</pre>
                            </li>
                            <li>Install <strong>safexec</strong> (required to bypass FUSE overhead): see the <em>"What is safexec?"</em> FAQ entry below for package download links and the one-liner installer.</li>
                            <li>Visit the <strong>Status tab</strong> to confirm both <code>rg</code> and <code>safexec</code> are detected — no further plugin configuration is required. NPP detects and uses both tools automatically.</li>
                        </ol>

                        <h4><strong>What if I cannot install safexec?</strong></h4>
                        <p>ripgrep alone still helps in non-FUSE environments. If your cache path is a FUSE mount without safexec, NPP falls back to running <code>rg</code> directly against the FUSE mount — faster than the PHP loop, but not as fast as the full FUSE-bypass path. The ~76% improvement described above requires <strong>both</strong> ripgrep and safexec.</p>

                        <h4><strong>Does this affect anything other than the Advanced tab?</strong></h4>
                        <p>Yes — the same scanning chain is used during <strong>Scheduled Preload</strong> and <strong>Preload All</strong> completion to rebuild the URL→filepath index. The Advanced tab is simply the most visible and interactive place where the scan latency is felt directly by the administrator.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Why does the NPP REST API return 404 (rest_no_route) behind an Nginx → Apache reverse proxy?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>REST API 404 on Nginx + Apache Reverse Proxy Stacks</strong></h3>
                        <p style="font-size: 14px;">
                            On setups where <strong>Nginx acts as a reverse proxy in front of Apache</strong> (a common pattern in control panels such as aaPanel, HestiaCP, CyberPanel, and similar), NPP's REST API endpoints return a
                            <code>rest_no_route</code> 404 even though the URL and method are correct:
                        </p>
                        <pre>{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}</pre>
                        <p style="font-size: 14px;">
                            The root cause is a two-layer header suppression problem. First, Nginx does not forward the
                            <code>Authorization</code> header to Apache by default. Second, even when Apache receives it,
                            PHP does not automatically expose it as <code>$_SERVER['HTTP_AUTHORIZATION']</code> unless a
                            <code>RewriteRule</code> explicitly sets the environment variable. WordPress reads
                            <code>HTTP_AUTHORIZATION</code> to authenticate REST API requests — if it is absent, the
                            Bearer token is never seen and the route resolver treats the request as unauthenticated or
                            misrouted, returning 404.
                        </p>

                        <h4><strong>Fix 1 — Nginx: forward Authorization and X-Api-Key to Apache</strong></h4>
                        <p style="font-size: 14px;">
                            Inside your <code>location / { ... }</code> block that proxies to Apache, add the four
                            highlighted lines. Both <code>proxy_pass_header</code> (copies the upstream response header
                            downstream) and <code>proxy_set_header</code> (injects the client request header into the
                            proxied request) are required together — <code>proxy_pass_header</code> alone is not
                            sufficient for request-direction forwarding.
                        </p>
                        <pre>location / {
    proxy_pass http://127.0.0.1:8288;  # your Apache backend port

    proxy_pass_header Authorization;
    proxy_pass_header X-Api-Key;
    proxy_set_header Authorization $http_authorization;
    proxy_set_header X-Api-Key $http_x_api_key;
}</pre>
                        <p style="font-size: 14px;">Reload Nginx after saving: <code>nginx -t &amp;&amp; systemctl reload nginx</code></p>

                        <h4><strong>Fix 2 — Apache: expose the Authorization header to PHP</strong></h4>
                        <p style="font-size: 14px;">
                            Apache's <code>mod_php</code> and PHP-FPM via <code>mod_proxy_fcgi</code> do not populate
                            <code>$_SERVER['HTTP_AUTHORIZATION']</code> from the incoming header automatically — it must
                            be injected via <code>mod_rewrite</code>. Add the block below inside your WordPress
                            <code>&lt;Directory&gt;</code> section. The critical line is the first
                            <code>RewriteRule</code> that maps the raw HTTP header into the
                            <code>HTTP_AUTHORIZATION</code> environment variable before any other rewrite logic runs.
                            The standard WordPress router rules must follow it in the same block.
                        </p>
                        <pre>&lt;Directory "/www/wwwroot/yourdomain.com"&gt;
    SetOutputFilter DEFLATE
    Options FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php index.html index.htm default.php default.html default.htm

    RewriteEngine On
    RewriteBase /
    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteRule ^index\.php$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.php [L]
&lt;/Directory&gt;</pre>
                        <p style="font-size: 14px;">
                            Reload Apache after saving: <code>systemctl reload apache2</code> (Debian/Ubuntu) or
                            <code>systemctl reload httpd</code> (RHEL/Fedora).
                        </p>

                        <h4><strong>Why the RewriteRule line is the key fix</strong></h4>
                        <p style="font-size: 14px;">
                            <code>RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]</code> matches every
                            request (<code>^</code>), performs no URL rewrite (<code>-</code>), and sets the Apache
                            environment variable <code>HTTP_AUTHORIZATION</code> to the value of the incoming
                            <code>Authorization</code> HTTP header. PHP's CGI/FastCGI SAPI then picks up all
                            <code>HTTP_*</code> environment variables and exposes them as <code>$_SERVER</code> keys,
                            making the Bearer token visible to WordPress and NPP's REST authentication layer.
                        </p>

                        <h4><strong>Verification</strong></h4>
                        <p style="font-size: 14px;">After applying both fixes, re-run your REST API call — a successful purge or preload returns HTTP 200 with a JSON body. If you still see 404, confirm with:</p>
                        <pre># Test purge endpoint
curl -v -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Accept: application/json" \
  "https://yourdomain.com/wp-json/nppp_nginx_cache/v2/purge"

# Test preload endpoint
curl -v -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Accept: application/json" \
  "https://yourdomain.com/wp-json/nppp_nginx_cache/v2/preload"</pre>
                        <p style="font-size: 14px;">
                            📌 The NPP API key is generated under <strong>Settings → NPP Settings → REST API</strong>.
                            The <code>Authorization: Bearer</code> scheme and the <code>X-Api-Key</code> header are both
                            accepted — use whichever your client supports.
                        </p>
                        <p style="font-size: 14px;">
                            📌 This fix applies to any control panel that uses the Nginx → Apache proxy pattern,
                            including <strong>aaPanel</strong>, <strong>HestiaCP</strong>,
                            <strong>CyberPanel</strong>, and manually configured dual-stack servers. The Nginx and
                            Apache config paths will differ per panel — consult your panel's vhost editor for the
                            correct files to edit.
                        </p>
                    </div>
                </div>

                <h3 class="nppp-question">How do I clear the entire cache on every post update instead of just the updated page?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Full Cache Purge on Post Update — Developer Recipe</strong></h3>
                        <p style="font-size: 14px;">
                            By default, NPP's Auto Purge surgically clears only the updated post and its related URLs
                            (homepage, category archives, tag archives). This is the correct behavior for most sites.
                        </p>
                        <p style="font-size: 14px;">
                            However, if your site sorts content by modified date, uses a heavily interlinked layout, or
                            has any other reason to invalidate the entire cache on every content update, you can override
                            this behavior with the drop-in recipe below. Add it to your <strong>child theme's
                            <code>functions.php</code></strong>.
                        </p>

                        <h4><strong>Recipe 1 — Full purge on publish → publish updates only</strong></h4>
                        <p style="font-size: 14px;">
                            Fires only when an already-published post is updated. New posts, drafts going live, and
                            deletions are not affected.
                        </p>
                        <pre>// NPP Drop-in | Clear the entire cache when a post is updated
add_action( 'transition_post_status', function ( string $new, string $old, WP_Post $post ): void {
    if ( $new !== 'publish' || $old !== 'publish' ) return;
    if ( ! function_exists( 'nppp_purge_callback' ) ) return;

    // Gutenberg: second meta-box-loader request guard.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_REQUEST['meta-box-loader'] ) ) return;

    if ( defined( 'DOING_AUTOSAVE' ) &amp;&amp; DOING_AUTOSAVE ) return;
    if ( ! is_post_type_viewable( $post->post_type ) ) return;

    $options = get_option( 'nginx_cache_settings', [] );
    if ( ( $options['nginx_cache_purge_on_update']  ?? 'no' ) !== 'yes' ) return;
    if ( ( $options['nppp_autopurge_posts']         ?? 'no' ) !== 'yes' ) return;

    // Cross-request dedup: Elementor fires multiple HTTP requests per save.
    $dedup_key = 'nppp_fullpurge_recipe_' . $post->ID;
    if ( get_transient( $dedup_key ) ) return;
    set_transient( $dedup_key, 1, 10 );

    nppp_purge_callback();
}, 20, 3 );</pre>

                        <h4><strong>Recipe 2 — Full purge on any transition to published</strong></h4>
                        <p style="font-size: 14px;">
                            Extends Recipe 1 to also fire when a new post is published, a draft goes live, or a
                            scheduled post is released. Replace the single status guard line:
                        </p>
                        <p style="font-size: 14px;"><strong>Remove:</strong></p>
                        <pre>if ( $new !== 'publish' || $old !== 'publish' ) return;</pre>
                        <p style="font-size: 14px;"><strong>Replace with:</strong></p>
                        <pre>if ( $new !== 'publish' ) return;</pre>
                        <p style="font-size: 14px;">
                            Everything else in the recipe stays the same. The hook now covers content updates,
                            new publications, drafts going live, and scheduled posts becoming public.
                        </p>

                        <h4><strong>How it works</strong></h4>
                        <p style="font-size: 14px;">
                            The recipe hooks into WordPress's <code>transition_post_status</code> action and calls
                            NPP's internal <code>nppp_purge_callback()</code> — the same function the Purge All
                            button triggers. It respects your existing NPP settings:
                        </p>
                        <ul style="font-size: 14px;">
                            <li>The <strong>Auto Purge master toggle</strong> (<code>nginx_cache_purge_on_update</code>) must be ON — the recipe is a no-op if Auto Purge is disabled.</li>
                            <li>The <strong>Posts &amp; Comments sub-trigger</strong> (<code>nppp_autopurge_posts</code>) must be ON.</li>
                            <li>Works correctly with <strong>Gutenberg</strong>, <strong>Elementor</strong>, and <strong>Classic Editor</strong> — a 10-second transient dedup guard prevents duplicate purges from editors that fire multiple HTTP requests per save.</li>
                            <li>Only fires for <strong>publicly viewable post types</strong> — CPTs registered with <code>publicly_queryable = false</code> are skipped.</li>
                            <li>Autosaves never trigger a purge.</li>
                        </ul>

                        <h4><strong>Important</strong></h4>
                        <p style="font-size: 14px;">
                            ⚠️ If <strong>Auto Preload</strong> is also ON in your NPP settings, every post save will
                            trigger a <strong>full site crawl</strong> immediately after the purge. On large sites this
                            is a significant server load event — use with caution or keep Auto Preload OFF and rely on
                            Scheduled Preload instead.
                        </p>
                    </div>
                </div>

                <h3 class="nppp-question">What Linux commands are required for the preload action?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">For the preload action to work properly, the server must have the <code>wget</code> command installed, as the plugin uses it to preload the cache by fetching pages. Additionally, it is recommended to have the <code>cpulimit</code> command installed to effectively manage <code>wget</code> process server load during the preload action.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Why is the purge & preload actions not functioning properly?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <ol class="nginx-list">
                            <p style="font-size: 14px;"><strong>Technical Background:</strong></p>
                            <p style="font-size: 14px;">In properly configured Nginx servers, it is not strictly necessary to have separate <code>PHP-FPM-USER</code> (as known WEBSITE-USER) and <code>WEBSERVER-USER</code> (commonly, nginx or www-data), but there are scenarios where separating these users can enhance security and performance. Here’s why:</p>
                            <ul>
                                <li><strong>Security:</strong> By running the PHP-FPM process under a different user than the Nginx web server, you reduce the risk of privilege escalation. If one process is compromised, the attacker does not automatically gain control over the other process.</li>
                                <li><strong>Permission Management:</strong> Having separate users allows for more granular permission settings. For example, PHP scripts can be restricted to only the directories they need to access, while the web server user can be given more restrictive permissions on other parts of the filesystem.</li>
                                <li><strong>Resource Management:</strong> Separate users can help with resource management and monitoring, as it becomes easier to track resource usage per user.</li>
                            </ul>
                            <p style="font-size: 14px;"><strong>Problem Statement:</strong></p>
                            <ul>
                                <li>The issue with <strong>Nginx cache</strong> purging often arises in environments where two distinct users are involved.</li>
                                <li>The <code>WEBSERVER-USER</code> is responsible for creating cache folders and files with strict permissions, while the <code>PHP-FPM-USER</code> handles cache purge & preload operations but lacks necessary privileges.</li>
                            </ul>
                            <p style="font-size: 14px;">This plugin also addresses the challenge of automating cache purging and preloading in Nginx environments that involve two distinct users, <code>WEBSERVER-USER</code> and <code>PHP-FPM-USER</code>, by offering a pre-made bash script.</p>
                        </ol>
                    </div>
                </div>

                <h3 class="nppp-question">What is the solution for the permission issues?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">In environments with two distinct user roles the <strong>WEBSERVER-USER</strong> (nginx or www-data) and the <strong>PHP-FPM-USER</strong> the pre-made bash script automates the management of <strong>Nginx Cache Paths</strong>. It utilizes <strong>bindfs</strong> to create a FUSE mount of the original Nginx Cache Paths, enabling the <strong>PHP-FPM-USER</strong> to write to these directories with the necessary permissions. This approach resolves permission conflicts by granting the <strong>PHP-FPM-USER</strong> access to a new mount point, while keeping the original Nginx Cache Paths intact and synchronized.</p>

                        <p><strong>Shortly:</strong></p>
                        <ol class="nginx-list">
                            <li>Solution utilizes <strong>bindfs</strong> to create a FUSE mount of the Nginx Cache Paths.</li>
                            <li>PHP-FPM-USER is granted write access to the mount point without altering the original cache paths.</li>
                            <li>This resolves permission conflicts and enables seamless cache purge and preload operations.</li>
                            <li>Automates the task, eliminating manual configuration of permissions or file access management.</li>
                        </ol>
                    </div>
                </div>

                <h3 class="nppp-question">Is there any solution to automate granting write permission to the PHP-FPM-USER?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <ol class="nginx-list">
                            <pre><code>bash &lt;(curl -Ss https://psaux-it.github.io/install.sh)</code></pre>
                            <li>The <code>install.sh</code> script serves as a wrapper for executing the main <code>fastcgi_ops_root.sh</code> script from <a href="https://psaux-it.github.io">psaux-it.github.io</a>. This bash script is designed for the Nginx Cache Purge Preload WordPress plugin (NPP).</li>
                            <li>The script first attempts to automatically identify the <strong>PHP-FPM-USER</strong> (also known as the PHP process owner or website user) along with their associated Nginx Cache Paths.</li>
                            <li>If it cannot automatically match the PHP-FPM-USER with their respective Nginx Cache Path, it provides an easy manual setup option using the <code>manual-configs.nginx</code> file.</li>
                            <li>According to matches this script automates the management of Nginx Cache Paths. It utilizes <strong>bindfs</strong> to create a FUSE mount of the original Nginx Cache Paths, enabling the <strong>PHP-FPM-USER</strong> to write to these directories with the necessary permissions automatically.</li>
                            <li>After the setup (whether automatic or manual) is completed, the script creates an <code>npp-wordpress</code> systemd service that ensures the FUSE mount is automatically restored on server reboot.</li>

                            <h4>Features:</h4>
                            <ul>
                                <li><strong>Automated Detection:</strong> Quickly sets up Nginx Cache Paths and associated PHP-FPM-USERS.</li>
                                <li><strong>Dynamic Configuration:</strong> Detects Nginx configuration dynamically for seamless integration.</li>
                                <li><strong>Systemd Integration:</strong> Generates and enables the <code>npp-wordpress</code> systemd service for managing FUSE mount operations.</li>
                                <li><strong>Manual Configuration Support:</strong> Allows manual configuration via the <code>manual-configs.nginx</code> file.</li>
                            </ul>

                            <h4>Tip</h4>
                            <p style="font-size: 14px;">Furthermore, if you're hosting multiple WordPress sites each with their own Nginx cache paths and associated PHP-FPM pool users on the same host, you'll find that deploying just one instance of this script effectively manages all WordPress instances using the NPP plugin. This streamlined approach centralizes cache management tasks, ensuring optimal efficiency and simplified maintenance throughout your server environment.</p>

                            <p style="font-size: 14px;">If auto-detection does not work for you, for proper matching, please ensure that your Nginx Cache Path includes the associated PHP-FPM-USER username.</p>

                            <p style="font-size: 14px;">For example, assuming your PHP-FPM-USER = <code>psauxit</code>, the following example <code>fastcgi_cache_path</code> naming formats will match perfectly with your PHP-FPM-USER and be detected by the script automatically:</p>
                            <ul>
                                <li><code>fastcgi_cache_path /dev/shm/fastcgi-cache-psauxit</code></li>
                                <li><code>fastcgi_cache_path /dev/shm/cache-psauxit</code></li>
                                <li><code>fastcgi_cache_path /dev/shm/psauxit</code></li>
                                <li><code>fastcgi_cache_path /var/cache/psauxit-fastcgi</code></li>
                                <li><code>fastcgi_cache_path /var/cache/website-psauxit.com</code></li>
                            </ul>
                            <p style="font-size: 14px;">⚠️ <strong>Note:</strong> The <code>install.sh</code> script is designed for <strong>monolithic (all-in-one) servers</strong> only — environments where Nginx, PHP-FPM, and WordPress all run on the same host. For <strong>Docker-based setups</strong>, a dedicated Docker integration is available at <a href="https://github.com/psaux-it/wordpress-nginx-cache-docker" target="_blank" rel="noopener">github.com/psaux-it/wordpress-nginx-cache-docker</a>.</p>
                        </ol>
                    </div>
                </div>

                <h3 class="nppp-question">Why can't I use my preferred path for the Nginx Cache Directory?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">The Nginx Cache Directory option restricts which paths are accepted to prevent accidental deletion of critical system files or WordPress installation data.</p>

                        <h4>Allowed root paths:</h4>
                        <ul>
                            <li><code>/dev/shm/</code> — RAM-based (tmpfs). Fast, lost on reboot. Recommended for high-performance setups.</li>
                            <li><code>/tmp/</code> — Temporary filesystem. Suitable for testing or low-memory environments.</li>
                            <li><code>/var/</code> — Persistent disk. Most common for production. Many subdirectories are blocked (see below).</li>
                            <li><code>/cache/</code> — Custom mount point. Useful for Docker or environments with a dedicated cache volume.</li>
                        </ul>

                        <h4>Examples of valid paths:</h4>
                        <ul>
                            <li><code>/dev/shm/nginx-cache</code></li>
                            <li><code>/tmp/cache</code></li>
                            <li><code>/var/cache/nginx</code></li>
                            <li><code>/var/nginx-cache</code></li>
                            <li><code>/var/run/nginx-cache</code></li>
                            <li><code>/cache/mysite</code></li>
                        </ul>

                        <h4>Important rules:</h4>
                        <ul>
                            <li>The path must be <strong>at least one level deeper</strong> than the root — <code>/var/cache</code>, <code>/var/run</code>, and <code>/cache</code> exact roots are blocked. Use a subdirectory like <code>/var/cache/nginx</code>.</li>
                            <li>The following subtrees within <code>/var/</code> are blocked to protect system data: <code>/var/log</code>, <code>/var/lib</code>, <code>/var/www</code>, <code>/var/spool</code>, <code>/var/mail</code>, <code>/var/lock</code>, <code>/var/backups</code>, <code>/var/snap</code>.</li>
                            <li>Paths outside the allowed roots — including <code>/home</code>, <code>/opt</code>, <code>/srv</code>, <code>/etc</code>, and the WordPress installation directory — are always rejected.</li>
                            <li>Symlinks are resolved and the destination is validated against the same rules.</li>
                        </ul>

                        <h4>Bypass Path Restriction <span style="font-size: 12px; font-weight: normal;">(available since v2.1.6)</span></h4>
                        <p style="font-size: 14px;">If your environment requires a cache path that falls outside the allowed roots above — for example a custom mount point, a non-standard directory layout, an environment managed by web panels, managed hosting configurations, or a containerised setup — you can enable <strong>Bypass Path Restriction</strong> in the Nginx Cache Directory settings.</p>
                        <p style="font-size: 14px;">When enabled, <strong>all built-in path safety guardrails are disabled</strong>. NPP will operate on any directory you configure as the Nginx cache path, including locations it would normally reject.</p>

                        <p style="font-size: 14px;">⚠️ <strong>CAUTION — read before enabling:</strong></p>
                        <ul style="font-size: 14px;">
                            <li>The plugin will perform purge operations — including <strong>recursive deletion</strong> — on whatever path you supply, with no further validation.</li>
                            <li>Configuring a system-critical path (e.g., <code>/var/www</code>, <code>/home</code>) by mistake will cause <strong>irreversible data loss</strong> if the <strong>PHP process owner</strong> has write/delete permissions for that directory and the path is not restricted by <code>open_basedir</code>.</li>
                            <li>By enabling this feature you explicitly accept full responsibility for any data loss or system damage that may result.</li>
                        </ul>
                        <p style="font-size: 14px;">Only enable Bypass Path Restriction if you are certain of the nginx cache path you are entering and understand the consequences. For all standard deployments, the built-in allowed-path list is the safer and recommended choice.</p>
                    </div>
                </div>

                <h3 class="nppp-question">What is safexec?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">
                            <strong>safexec</strong> is a secure, privilege-dropping SUID wrapper for executing a set of tools from higher-level contexts such as PHP’s <strong>shell_exec()</strong>.
                            It is written in C as the backend for <strong>NPP</strong> and pairs with an optional <strong>LD_PRELOAD</strong> shim library, <strong>libnpp_norm.so</strong>, that normalizes percent-encoded HTTP request-lines during cache preloading to ensure consistent Nginx cache keys.
                        </p>

                    <h4>Why does NPP need/use it?</h4>
                    <ul style="font-size: 14px;">
                        <li><strong>Privilege drop:</strong> commands run as <code>nobody</code>.</li>
                        <li><strong>URL Normalization for Preload</strong></li>
                    </ul>

                    <h4>Benefits</h4>
                    <ul style="font-size: 14px;">
                        <li>Reduces risk from injected or misbehaving shell commands.</li>
                        <li>Keeps preload process isolated from WordPress/PHP-FPM.</li>
                    </ul>

                    <h4>Is it recommended?</h4>
                    <p style="font-size: 14px;">
                        Cause NPP deeply use PHPs <em>shell_exec</em>, <strong>Yes,</strong> using safexec is <em>highly</em> recommended for all users.
                    </p>

                    <h4>How do I install it?</h4>
                    <p style="font-size: 14px;">
                        Use the <code>.deb</code>, <code>.rpm</code> or <code>.apk</code> packages from the
                        <a href="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases" target="_blank" rel="noopener">Releases page</a>,
                        or directly with one liner:
                    </p>
                    <pre><code>curl -fsSL https://psaux-it.github.io/install-safexec.sh | sudo sh</code></pre>

                    <ol class="nginx-list" style="font-size: 14px;">
                        <li><strong> Debian / Ubuntu (.deb)</strong>
                            <pre># Download checksums
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/SHA256SUMS

# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/safexec_<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1_amd64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install --reinstall ./safexec_<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1_amd64.deb

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/safexec_<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1_arm64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install --reinstall ./safexec_<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1_arm64.deb</pre>
                        </li>

                        <li><strong> RHEL / CentOS / Fedora (.rpm)</strong>
                            <pre># Download checksums
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/SHA256SUMS

# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1.el10.x86_64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install ./safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1.el10.x86_64.rpm
sudo dnf reinstall ./safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1.el10.x86_64.rpm

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1.el10.aarch64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install ./safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1.el10.aarch64.rpm
sudo dnf reinstall ./safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-1.el10.aarch64.rpm</pre>
                        </li>

                        <li><strong> Alpine Linux (.apk)</strong>
                            <pre># Download checksums
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/SHA256SUMS

# For x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-r1.x86_64.apk
sha256sum -c SHA256SUMS --ignore-missing
sudo apk add --allow-untrusted --force-overwrite ./safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-r1.x86_64.apk

# For AArch64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v<?php echo esc_html(NPPP_PLUGIN_VERSION); ?>/safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-r1.aarch64.apk
sha256sum -c SHA256SUMS --ignore-missing
sudo apk add --allow-untrusted --force-overwrite ./safexec-<?php echo esc_html(NPPP_SAFEXEC_VERSION); ?>-r1.aarch64.apk</pre>
                            <p style="font-size: 13px;"><em>Note: <code>--allow-untrusted</code> is required because the package is not signed with an Alpine trusted key. The SHA256 checksum above provides integrity verification.</em></p>
                        </li>

                        <li><strong>Verify:</strong>
                            <pre><code>safexec --version</code></pre>
                        </li>

                        <li><em>Note:</em> Install safexec <u>inside</u> the WordPress/PHP-FPM host or container so NPP can call it.</li>
                      </ol>

                    <h4>Optional: quick test</h4>
                    <p style="font-size: 14px;">NPP uses safexec automatically, but you can test it manually:</p>
                    <pre>safexec wget -qO- https://example.com
safexec --kill=&lt;pid&gt;
                    </pre>

                      <p style="font-size: 14px;">
                          <em>Notes:</em> On systems without cgroup v2, isolation falls back to rlimits; without setuid-root, safexec runs in pass-through mode.
                      </p>

                      <p style="font-size: 12px; margin-top: 8px;">
                          <strong>Full docs &amp; source:</strong>
                          <a href="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/tree/main/safexec" target="_blank" rel="noopener">GitHub &raquo; safexec</a>
                      </p>
                  </div>
                </div>

                <h3 class="nppp-question">How does Cloudflare APO Sync work and how do I enable it?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Cloudflare APO Sync</strong></h3>
                        <p>When enabled, NPP automatically mirrors every cache purge operation to Cloudflare's edge cache, keeping your Cloudflare-cached pages in sync with your Nginx cache at all times.</p>

                        <h4><strong>Requirements</strong></h4>
                        <p>The official <strong>Cloudflare WordPress plugin</strong> must be installed, active, and authenticated with your Cloudflare account. NPP reads its configuration directly — no API keys are entered in NPP itself. Either <strong>Automatic Platform Optimization (APO)</strong> or <strong>Plugin-Specific Cache</strong> must be enabled inside the Cloudflare plugin for HTML caching to be active.</p>

                        <h4><strong>How to enable</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong> and turn on <strong>Cloudflare APO Sync</strong>. If the Cloudflare plugin is not installed or not authenticated, the toggle will show as <em>Unavailable</em> in the dashboard widget and will auto-disable itself.</p>

                        <h4><strong>What gets purged and when</strong></h4>
                        <p><strong>Single-URL Purge</strong> (manual, auto-purge on update, comment count change, post delete): NPP purges the page and its related URLs (homepage, category archives) from both Nginx and Cloudflare edge cache. Purge requests are batched and sent once at request shutdown for efficiency — up to 30 URLs per API call.</p>
                        <p><strong>Purge All</strong>: Cloudflare's entire zone cache is wiped with a single API call (<code>zonePurgeCache</code>), matching the full Nginx cache clear.</p>
                        <p><strong>APO Cache By Device Type</strong>: If this setting is active inside the Cloudflare plugin, NPP automatically sends a second purge pass with the <code>CF-Device-Type: mobile</code> header so mobile-specific cached variants are also cleared.</p>

                        <h4><strong>Important notes</strong></h4>
                        <p>Cloudflare purge only fires when APO or Plugin-Specific Cache is actually enabled in the Cloudflare plugin — if HTML caching is off on the Cloudflare side, the purge is skipped and logged. NPP does not purge Cloudflare during preload operations, only on purge events.</p>
                    </div>
                </div>

                <h3 class="nppp-question">How does Redis Object Cache Sync work and how do I enable it?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Redis Object Cache Sync</strong></h3>
                        <p>When enabled, NPP flushes the Redis object cache at the right point in the Nginx Purge+Preload chain, keeping both caching layers aligned. Flushing Redis only makes sense immediately before fresh content is pulled into the Nginx cache — so a purge-only operation (without preload) deliberately leaves Redis warm.</p>

                        <h4><strong>Requirements</strong></h4>
                        <p>The <strong>Redis Object Cache</strong> plugin must be installed, active, and connected to a live Redis server. NPP checks for both the drop-in (<code>WP_REDIS_VERSION</code> constant) and a live connection (<code>redis_status() === true</code>) at runtime. If Redis is unreachable the toggle shows as <em>Unavailable</em>.</p>

                        <h4><strong>How to enable</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong> and turn on <strong>Redis Object Cache Sync</strong>.</p>

                        <h4><strong>When Redis is flushed</strong></h4>
                        <p><strong>Purge All + Auto Preload ON</strong> (Admin button, Admin Bar, REST API): NPP flushes Redis immediately after clearing the Nginx cache, just before the Preload All begins. This ensures PHP fetches fresh database content when pages are rebuilt into the Nginx cache. If Auto Preload is OFF, Purge All does not flush Redis — no preload means no rebuild, so there is no reason to invalidate the object cache.</p>
                        <p><strong>Preload All</strong> (Admin button, Admin Bar, REST API, Scheduled Cron): NPP always flushes Redis at the start of every Preload All, regardless of whether a purge preceded it. This guarantees the preloader warms the Nginx cache with the freshest possible content.</p>

                        <h4><strong>What is never flushed</strong></h4>
                        <p>Purge-only operations — including single-URL purges (Auto Purge, Admin Bar, Advanced Tab) and Purge All without Auto Preload — never touch Redis. The object cache stays warm so that any PHP requests served between purge and preload still benefit from cached database queries.</p>

                        <h4><strong>Important notes</strong></h4>
                        <p>Redis Object Cache Sync is independent of the <strong>Auto Purge</strong> setting — it activates based on whether a preload action is part of the operation, not on whether Auto Purge is enabled. There is no reverse direction: a Redis flush from outside NPP does not trigger an Nginx purge.</p>
                    </div>
                </div>

                <h3 class="nppp-question">What is the Preload Watchdog and when should I enable it?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Preload Watchdog</strong></h3>
                        <p>The Preload Watchdog ensures that post-preload tasks — such as building the cache index, sending the completion email, and starting the mobile preload pass — run immediately when preloading finishes.</p>

                        <h4><strong>Why it is needed</strong></h4>
                        <p>Normally these tasks are handled by WP-Cron, which depends on visitor traffic to trigger. On low-traffic or fully-cached sites, no visitor may arrive after preloading finishes, causing post-preload tasks to be delayed or never run at all. The watchdog removes this dependency by detecting the exact moment preloading finishes and triggering the tasks directly — no visitor needed.</p>

                        <h4><strong>How to enable</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings</strong> and turn on <strong>Preload Watchdog</strong>. It is especially recommended for low-traffic sites, heavily cached sites, and any site using the <strong>Scheduled Cache</strong> feature.</p>

                        <h4><strong>Notes</strong></h4>
                        <p>The watchdog starts automatically with each preload cycle and exits on its own once its job is done. If preloading is cancelled by a <strong>Purge All</strong>, the watchdog is also stopped so it does not fire tasks for a cancelled run.</p>
                    </div>
                </div>

                 <h3 class="nppp-question">What is different about this plugin compared to other Nginx Cache Plugins?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">Most Nginx cache plugins for WordPress share a common baseline — purge via the <code>ngx_cache_purge</code> module and basic filesystem purge. The table below shows where NPP goes beyond that baseline.</p>
                        <table class="responsive-table">
                          <thead>
                            <tr>
                              <th>Feature</th>
                              <th>Typical Nginx Cache Plugin</th>
                              <th>NPP</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr>
                              <td>Purge via ngx_cache_purge module</td>
                              <td>✅ Primary method</td>
                              <td>✅ Optional fast-path (HTTP Purge)</td>
                            </tr>
                            <tr>
                              <td>Filesystem purge fallback</td>
                              <td>✅ Supported</td>
                              <td>✅ Three-layer strategy: HTTP → URL index → recursive scan</td>
                            </tr>
                            <tr>
                              <td>URL→filepath index for instant purge</td>
                              <td>❌ No</td>
                              <td>✅ Built and updated automatically during preload — skips directory scan entirely</td>
                            </tr>
                            <tr>
                              <td>Multi-user environments (WEBSERVER-USER ≠ PHP-FPM-USER)</td>
                              <td>❌ No filesystem purge support — known limitation</td>
                              <td>✅ Fully solved via bindfs + safexec — the core reason NPP was created</td>
                            </tr>
                            <tr>
                              <td>Process isolation for shell operations</td>
                              <td>❌ No</td>
                              <td>✅ safexec — privilege-dropping SUID wrapper, runs as <code>nobody</code></td>
                            </tr>
                            <tr>
                              <td>Cache preloading engine</td>
                              <td>⚠️ Basic — simple HTTP fetch loop</td>
                              <td>✅ Full wget-based crawler with rate limiting, CPU limiting, reject regex, AMP, mobile pass, proxy support</td>
                            </tr>
                            <tr>
                              <td>Mobile cache preload</td>
                              <td>❌ No</td>
                              <td>✅ Separate preload pass with mobile user-agent</td>
                            </tr>
                            <tr>
                              <td>Scheduled cache (cron preload)</td>
                              <td>❌ No</td>
                              <td>✅ Full cron scheduler with custom interval and time picker</td>
                            </tr>
                            <tr>
                              <td>Preload Watchdog</td>
                              <td>❌ No</td>
                              <td>✅ Guarantees post-preload tasks run without depending on visitor traffic</td>
                            </tr>
                            <tr>
                              <td>Cache Coverage Ratio</td>
                              <td>❌ No</td>
                              <td>✅ Live gauge showing % of known URLs currently in cache</td>
                            </tr>
                            <tr>
                              <td>Cloudflare APO sync</td>
                              <td>❌ No</td>
                              <td>✅ Mirrors every purge to Cloudflare edge cache automatically</td>
                            </tr>
                            <tr>
                              <td>Redis Object Cache bidirectional sync</td>
                              <td>⚠️ One-direction only at best</td>
                              <td>✅ Bidirectional — NPP purge flushes Redis, Redis flush triggers NPP purge</td>
                            </tr>
                            <tr>
                              <td>REST API</td>
                              <td>❌ No</td>
                              <td>✅ Full REST API for purge and preload with API key auth</td>
                            </tr>
                            <tr>
                              <td>URL normalization (percent-encoding)</td>
                              <td>❌ No</td>
                              <td>✅ Via safexec + libnpp_norm.so or mitmproxy — prevents cache misses on non-ASCII URLs</td>
                            </tr>
                            <tr>
                              <td>Auto purge on plugin/theme update</td>
                              <td>⚠️ Requires custom filter to enable</td>
                              <td>✅ Built-in toggle, enabled by default</td>
                            </tr>
                            <tr>
                              <td>Completion email notification</td>
                              <td>❌ No</td>
                              <td>✅ Sends email summary after preload completes</td>
                            </tr>
                          </tbody>
                      </table>
                    </div>
                </div>

                <h3 class="nppp-question">What is the Cache Coverage Ratio in the dashboard widget?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Cache Coverage Ratio</strong></h3>
                        <p>The circular gauge in the NPP dashboard widget shows what percentage of your site's known URLs are currently present in the Nginx cache. It answers the question: <em>of all the pages NPP crawled during the last preload, how many are cached right now?</em></p>

                        <h4><strong>How it is calculated</strong></h4>
                        <p><strong>Total</strong> is the number of URLs discovered during the last completed <strong>Preload All</strong> run, taken from the saved preload snapshot. <strong>Hits</strong> is the number of cache files currently found in the Nginx cache directory. <strong>Coverage = Hits ÷ Total × 100</strong>. The ratio is capped at 100% — the cache can contain pages not in the snapshot (manually visited pages, paginated archives) but the gauge will not exceed 100.</p>

                        <h4><strong>Why it shows N/A</strong></h4>
                        <p>The gauge shows N/A until a <strong>Preload All</strong> has been run at least once and completed successfully. The snapshot is only written when a full preload finishes — an interrupted or partial preload does not produce one. Once the snapshot exists, clicking the <strong>refresh button</strong> on the gauge triggers a live scan of the cache directory and updates the ratio immediately.</p>

                        <h4><strong>When to use it</strong></h4>
                        <p>Check it after a <strong>Purge All</strong> followed by a new <strong>Preload All</strong> to confirm the cache was fully rebuilt. A ratio well below 100% after preloading may indicate pages were excluded by the reject regex, the preload was interrupted, or cache entries have already expired.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Feature dependency map — what works with what and when?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Feature Dependency Map</strong></h3>
                        <p style="font-size: 14px;">NPP's features are layered — many behaviors only activate when specific combinations of settings are enabled. This reference shows exactly what fires for every action and under what conditions. Use it to understand what changes when you toggle an option.</p>

                        <div style="overflow-x:auto;">
                        <table class="responsive-table" style="min-width:900px;">
                            <thead>
                                <tr>
                                    <th>Action / Trigger</th>
                                    <th>HTTP Purge</th>
                                    <th>Related URLs Purge</th>
                                    <th>Cloudflare Sync</th>
                                    <th>Redis sync</th>
                                    <th>Single-URL Preload</th>
                                    <th>Mobile Preload</th>
                                    <th>Related URLs Preload</th>
                                    <th>Send Mail</th>
                                    <th>Watchdog</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Manual Purge All</strong><br><small>Button / Admin bar — No Auto Preload</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Full Zone purge<br><small>Requires CF Sync ON</small></td>
                                    <td>✅ Flushes Redis<br><small>Requires Redis Sync ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>Manual Purge All + Auto Preload ON</strong><br><small>Purge All immediately followed by full Preload All</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Full zone purge<br><small>Requires CF Sync ON</small></td>
                                    <td>✅ Flushes Redis<br><small>Requires Redis Sync ON</small></td>
                                    <td>❌ Never</td>
                                    <td>✅ After desktop preload completes<br><small>Requires Preload Mobile ON</small></td>
                                    <td>❌ Never</td>
                                    <td>✅ After all phases complete<br><small>Requires Send Mail ON</small></td>
                                    <td>✅ Spawned with Preload All<br><small>Requires Watchdog ON</small></td>
                                </tr>
                                <tr>
                                    <td><strong>Manual Preload All</strong><br><small>Button / Admin bar</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Auto-follows after desktop completes<br><small>Requires Preload Mobile ON</small></td>
                                    <td>❌ Never</td>
                                    <td>✅ After all phases complete<br><small>Requires Send Mail ON</small></td>
                                    <td>✅ Spawned at start<br><small>Requires Watchdog ON</small></td>
                                </tr>
                                <tr>
                                    <td><strong>Manual Single-URL Purge</strong><br><small>Frontend Purge This Page button</small></td>
                                    <td>✅ First attempt<br><small>Requires HTTP Purge ON</small></td>
                                    <td>✅ Always<br><small>Scope set by related settings</small></td>
                                    <td>✅ Purges page + related URLs<br><small>Requires CF Sync ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Preloads related URLs<br><small>Requires Related Preload after Manual ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>Auto Purge</strong><br><small>post save / delete / comment / plugin or theme update / Gutenberg / Elementor / WooCommerce</small></td>
                                    <td>✅ First attempt<br><small>Requires HTTP Purge ON</small></td>
                                    <td>✅ Always<br><small>Scope set by related settings</small></td>
                                    <td>✅ Purges page + related URLs<br><small>Requires CF Sync ON</small></td>
                                    <td>❌ Never</td>
                                    <td>✅ Preloads the single purged URL<br><small>Requires Auto Purge ON + Auto Preload ON</small></td>
                                    <td>✅ Mobile variant of single URL<br><small>Requires Auto Purge + Auto Preload + Preload Mobile all ON</small></td>
                                    <td>✅ Preloads related URLs<br><small>Requires Auto Preload ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>Advanced tab — Single-URL Purge</strong><br><small>File selected from cache browser, purged directly by file path</small></td>
                                    <td>❌ Never<br><small>Always filesystem — no HTTP Purge</small></td>
                                    <td>✅ Always<br><small>Scope set by related settings</small></td>
                                    <td>✅ Purges page + related URLs<br><small>Requires CF Sync ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Preloads related URLs<br><small>Requires Related Preload after Manual ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>Advanced tab — Single-URL Preload</strong><br><small></small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Mobile variant also preloaded<br><small>Requires Preload Mobile ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>REST API Purge</strong><br><small>Purge All, never single URL</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Full zone purge<br><small>Requires CF Sync ON</small></td>
                                    <td>✅ Flushes Redis<br><small>Requires Redis Sync ON</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>REST API Preload</strong><br><small>Preload All</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Auto-follows after desktop completes<br><small>Requires Preload Mobile ON</small></td>
                                    <td>❌ Never</td>
                                    <td>✅ After all phases complete<br><small>Requires Send Mail ON</small></td>
                                    <td>✅ Spawned at start<br><small>Requires Watchdog ON</small></td>
                                </tr>
                                <tr>
                                    <td><strong>Scheduled Preload</strong><br><small>WP-Cron</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Auto-follows after desktop completes<br><small>Requires Preload Mobile ON</small></td>
                                    <td>❌ Never</td>
                                    <td>✅ After all phases complete<br><small>Requires Send Mail ON</small></td>
                                    <td>✅ Spawned at start<br><small>Requires Watchdog ON</small></td>
                                </tr>
                                <tr>
                                    <td><strong>Redis flush from outside NPP</strong><br><small>Redis plugin dashboard, WP-CLI wp cache flush, any plugin calling wp_cache_flush — with Redis Sync OFF or Auto Purge OFF</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Not applicable</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                </tr>
                                <tr>
                                    <td><strong>Redis flush from outside NPP</strong><br><small>with Redis Sync ON + Auto Purge ON — triggers full NPP Purge All cascade</small></td>
                                    <td>❌ Never</td>
                                    <td>❌ Never</td>
                                    <td>✅ Full zone purge<br><small>Via the Purge All it triggers</small></td>
                                    <td>⚠️ Loop guard prevents re-flush</td>
                                    <td>❌ Never</td>
                                    <td>⚠️ If Auto Preload ON → Preload All → mobile follows<br><small>Requires Preload Mobile ON</small></td>
                                    <td>❌ Never</td>
                                    <td>⚠️ If Preload All fires<br><small>Requires Send Mail ON</small></td>
                                    <td>⚠️ If Preload All fires<br><small>Requires Watchdog ON</small></td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>

                <h3 class="nppp-question">What is different about Nginx server-side caching compared to traditional WordPress page caching?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <table class="responsive-table">
                          <thead>
                            <tr>
                              <th>Aspect</th>
                              <th>Traditional WordPress Page Caching</th>
                              <th>Nginx Server-Side Caching</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr>
                              <td>Setup Complexity</td>
                              <td>Requires installation and configuration of PHP-based caching plugins (e.g., WP Rocket, W3 Total Cache).</td>
                              <td>Requires Nginx configuration at the server level, but no PHP-based plugins are needed.</td>
                            </tr>
                            <tr>
                              <td>Cache Location</td>
                              <td>Cache is stored on the filesystem, usually in a dedicated folder, and requires PHP processing to serve cached content.</td>
                              <td>Cache is stored directly at the server level (e.g., in memory or disk), bypassing PHP entirely for faster access.</td>
                            </tr>
                            <tr>
                              <td>Speed</td>
                              <td>Cached pages are served through PHP, which introduces overhead, making it slower compared to direct server-level caching.</td>
                              <td>Pages are served directly from Nginx's cache, resulting in faster page load times as there is no PHP overhead involved.</td>
                            </tr>
                            <tr>
                              <td>Resource Usage</td>
                              <td>Requires PHP to process and serve cached content, consuming more server resources (CPU, RAM).</td>
                              <td>Uses minimal resources as Nginx serves cached files directly, significantly reducing CPU and memory usage, especially under high traffic.</td>
                            </tr>
                            <tr>
                              <td>Cache Invalidation</td>
                              <td>Cache invalidation requires interaction with PHP-based cache purging mechanisms (e.g., through plugin settings or custom rules), which may take time.</td>
                              <td>Cache is invalidated quickly and efficiently at the server level, often via direct cache purging commands without involving PHP.</td>
                            </tr>
                            <tr>
                              <td>Integration</td>
                              <td>Integrated within the WordPress environment, relying on the WordPress plugin ecosystem to manage page caching.</td>
                              <td>Operates independently of WordPress, with caching occurring directly at the server level, making it more versatile for various types of content.</td>
                            </tr>
                            <tr>
                              <td>Scalability</td>
                              <td>As traffic grows, PHP-based page caching can consume more resources, potentially slowing down site performance under high load.</td>
                              <td>Highly scalable as Nginx handles large numbers of concurrent requests with minimal resources, making it ideal for handling high-traffic sites.</td>
                            </tr>
                            <tr>
                              <td>Redundancy</td>
                              <td>Can lead to redundant caching layers if combined with other caching mechanisms (e.g., object caching or server-side caching), increasing complexity.</td>
                              <td>Eliminates redundancy by serving static cached content directly from Nginx, offering a more efficient and streamlined caching solution.</td>
                            </tr>
                          </tbody>
                        </table>
                    </div>
                </div>

                <h3 class="nppp-question">How can I use NPP with other caching plugins?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">When using NPP alongside other WordPress caching plugins, it's important to <strong>disable page caching</strong> in the other plugins to avoid conflicts and redundancy. They  can still be used for frontend optimizations. Here's how:</p>

                        <h4>To prevent conflicts, configure Other Caching Plugins correctly:</h4>
                        <ol class="nginx-list">
                        <ul>
                            <li><strong>While Disabling the Page Caching Feature</strong><br> Turn off the page caching option in any caching plugin you're using (e.g., WP Rocket, W3 Total Cache, LiteSpeed Cache).</li>
                            <li><strong>Keep Other Frontend Optimization Features Active</strong><br>
                                <ul>
                                    <p style="font-size: 14px;"><code>CSS/JS Optimization</code>: Minify and combine stylesheets and scripts.</p>
                                    <p style="font-size: 14px;"><code>Lazy Loading</code>: Improve page load speed by loading images and videos only when needed.</p>
                                    <p style="font-size: 14px;"><code>Database Cleanup</code>: Optimize your WordPress database to reduce bloat.</p>
                                    <p style="font-size: 14px;"><code>CDN Integration</code>: Seamlessly deliver static files from a content delivery network.</p>
                                </ul>
                            </li>
                        </ul>
                        </ol>

                        <p style="font-size: 14px;">By using NPP for server side page caching and other plugins solely for frontend optimizations, you ensure a streamlined, high-performance system without redundant caching layers and conflicts.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Why does a cache miss occur for URLs with percent-encoded characters like /product/水滴轮锻碳单摇/?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;"><strong>Background:</strong> Some URLs contain non-ASCII characters (e.g., Chinese, Cyrillic, Arabic), which browsers and tools like <code>wget</code> automatically convert into percent-encoded ASCII format. For example:</p>
                        <pre><code>/product/水滴轮锻碳单摇/ → /product/%e6%b0%b4%e6%bb%b4%e8%bd%ae%e9%94%bb%e7%a2%b3%e5%8d%95%e6%91%87/</code></pre>

                        <p style="font-size: 14px;">However, percent-encoded characters can appear in either <strong>uppercase</strong> or <strong>lowercase</strong> depending on how the request is generated:</p>

                        <p style="font-size: 14px;"><strong>Nginx cache is case-sensitive</strong>, meaning if the NPP preload request stores a file using uppercase encoding, and a real visitor accesses the page using lowercase, Nginx sees them as different and returns a <strong>cache miss</strong>.</p>

                        <p style="font-size: 14px;">So, for example, if the NPP plugin preloads this URL:</p>
                        <pre><code>https://example.com/product/%E6%B0%B4%E6%BB%B4%E8%BD%AE%E9%94%BB%E7%A2%B3%E5%8D%95%E6%91%87/</code></pre>
                        <p style="font-size: 14px;">...but a user accesses it like this:</p>
                        <pre><code>https://example.com/product/%e6%b0%b4%e6%bb%b4%e8%bd%ae%e9%94%bb%e7%a2%b3%e5%8d%95%e6%91%87/</code></pre>
                        <p style="font-size: 14px;">Nginx will not find a matching cache file. <strong>This is a classic cache mismatch caused by encoding inconsistency.</strong></p>

                        <h4>✅ Solution 1 (Recommended): Normalize Encoding with safexec</h4>
                        <h4>✅ Solution 2: Normalize Encoding with mitmproxy</h4>
                        <p style="font-size: 14px;"><strong>mitmproxy</strong> acts as a "man-in-the-middle" proxy between the NPP Preload (wget) and Nginx. It rewrites percent-encoded characters to a consistent casing <strong>on the fly</strong>, ensuring preload and browser requests use identical formats.</p>

                        <p style="font-size: 14px;">To fix cache misses caused by inconsistent percent-encoding (uppercase vs lowercase), follow these steps:</p>

                        <p style="font-size: 14px;"><strong>WordPress Admin → Settings → Nginx Cache Purge Preload → Preload Options</strong></p>

                        <ul>
                            <li>
                                <strong>Use Proxy:</strong><br>
                                Enable this to route all preload requests (from <code>wget</code>) through  <strong>mitmproxy</strong>.
                            </li>
                            <li>
                                <strong>Proxy Host:</strong><br>
                                <ul>
                                    <li><code>127.0.0.1|localhost</code> – if mitmproxy runs on the <strong>same server</strong> as WordPress.</li>
                                    <li><code>my-mitmproxy</code> – if using mitmproxy in a <strong>containerized setup</strong>.</li>
                                </ul>
                            </li>
                            <li>
                                <strong>Proxy Port:</strong><br>
                                Enter the port that mitmproxy is listening on. <br>
                                Example: <code>3434</code>
                            </li>
                        </ul>

                        <p style="font-size: 14px;">Once enabled, the plugin automatically routes all <strong>Preload requests</strong> (<code>wget</code>) through <strong>mitmproxy</strong>, which intercepts and rewrites the request paths <strong>on the fly</strong> — ensuring that percent-encoded characters follow a consistent format <strong>before reaching Nginx</strong>. This eliminates cache mismatches caused by case differences in encoding.</p>

                        <pre><code>[wget] → [mitmproxy] → [Nginx]</code></pre>

                        <h4>🧠 Any helper script?</h4>
                        <p style="font-size: 14px;">Depending on how the NPP plugin generates cache keys on your system, choose the appropriate script:</p>

                        <p><strong>1. percent_encode_lowercase.py</strong> – It forcibly rewrites the percent-encoded characters in NPP plugin preload requests to lowercase for consistency:</p>
                        <pre>from mitmproxy import http, ctx
import re

percent_encoded_re = re.compile(r'%[0-9A-Fa-f]{2}')

def request(flow: http.HTTPFlow) -> None:
    path = flow.request.path
    new_path = percent_encoded_re.sub(lambda m: m.group(0).lower(), path)

    if new_path != path:
        flow.request.path = new_path
        ctx.log.info(f"Rewriting path: {path} → {new_path}")</pre>

                        <p><strong>2. percent_encode_uppercase.py</strong> – It forcibly rewrites the percent-encoded characters in NPP plugin preload requests to uppercase for consistency:</p>
                        <pre>from mitmproxy import http, ctx
import re

percent_encoded_re = re.compile(r'%[0-9a-f]{2}')

def request(flow: http.HTTPFlow) -> None:
    path = flow.request.path
    new_path = percent_encoded_re.sub(lambda m: m.group(0).upper(), path)

    if new_path != path:
        flow.request.path = new_path
        ctx.log.info(f"Rewriting path: {path} → {new_path}")</pre>

                        <h4>🔧 Example systemd service for mitmproxy:</h4>
                        <ul style="font-size: 14px;">
                            <li><strong>Same host:</strong> Use <code>--listen-host 127.0.0.1</code></li>
                            <li><strong>Container:</strong> Use <code>--listen-host 0.0.0.0</code></li>
                            <li><strong>Allow-Hosts:</strong> Set <code>yourdomain.com</code></li>
                        </ul>

                        <pre>[Unit]
Description=Mitmproxy - Normalize Percent-Encoding
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/mitmdump \
    --mode regular \
    --listen-host 127.0.0.1 \
    --listen-port 3434 \
    --set block_global=false \
    --allow-hosts yourdomain.com \
    -s /etc/mitmproxy/percent_encode_lowercase.py
Restart=always
RestartSec=3
StandardOutput=append:/var/log/mitmproxy.log
StandardError=append:/var/log/mitmproxy.log

[Install]
WantedBy=multi-user.target</pre>
                    </div>
                </div>

                <h3 class="nppp-question">Why am I seeing -GLOBAL ERROR SERVER: The plugin is not functional on your environment. It requires an Nginx web server.-?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">
                            This message appears when the plugin cannot detect a running Nginx server or valid <code>nginx.conf</code> in your environment. This is common in reverse proxy setups (e.g., Apache in front of Nginx), containerized environments, or jailed systems (like <strong>cPanel</strong>, <strong>aaPanel</strong>, etc.).
                        </p>
                        <p style="font-size: 14px;">
                            🔧 To force plugin activation in such non-standard environments, define the following constant in your <code>wp-config.php</code>:
                        </p>
                        <pre><code>define('NPPP_ASSUME_NGINX', true);</code></pre>
                        <p style="font-size: 14px;">
                            This override enables full plugin functionality even when Nginx auto-detection fails.
                        </p>
                        <p style="font-size: 14px;">
                            ✅ <strong>Preferred Solution:</strong> For best accuracy, bind-mount or sync your actual <code>nginx.conf</code> file into the WordPress environment (jail/chroot/container) at:
                        </p>
                        <pre><code>/etc/nginx/nginx.conf</code></pre>
                        <p style="font-size: 14px;">
                            This lets the plugin auto-parse your live Nginx configuration to accurately detect cache paths, user directives, and cache key settings.
                        </p>
                    </div>
                </div>

                <h3 class="nppp-question">When should I use Clear Plugin Cache on the Status tab?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Clear Plugin Cache</strong></h3>
                        <p>NPP caches expensive server-side status checks — Nginx detection, configuration parsing, permission checks, cache path validation — to avoid running them on every page load. This cache is stored as WordPress transients and is normally refreshed automatically.</p>

                        <h4><strong>When to clear it</strong></h4>
                        <p>Clear the plugin cache whenever you make server-side changes that NPP should immediately reflect:</p>
                        <ul>
                            <li>You changed the Nginx cache path in <code>nginx.conf</code> or the NPP settings.</li>
                            <li>You installed, removed, or reconfigured the <code>ngx_cache_purge</code> module.</li>
                            <li>You ran the <code>fastcgi_ops_root.sh</code> / bindfs setup script for the first time or re-ran it after a change.</li>
                            <li>You changed PHP-FPM user permissions or cache directory ownership.</li>
                            <li>The Status tab shows stale or unexpected values after a server configuration change.</li>
                            <li>You are in active testing and need accurate real-time status on every check.</li>
                        </ul>

                        <h4><strong>Where to find it</strong></h4>
                        <p>Go to <strong>Status tab → Clear Plugin Cache</strong> button at the top of the page. The page reloads automatically after clearing so you see fresh values immediately.</p>

                        <h4><strong>Is it safe to clear?</strong></h4>
                        <p>Yes. Clearing the plugin cache does not affect your Nginx cache, WordPress content, or any plugin settings. It only discards NPP's internal status snapshots — they rebuild automatically on the next Status tab visit.</p>
                    </div>
                </div>

                <h3 class="nppp-question">When should I use Clear URL Index on the Status tab?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Clear URL Index</strong></h3>
                        <p>NPP maintains a persistent URL→Filepath index that maps cached page URLs to their exact filesystem paths. This index is what allows single-page purges to skip the full recursive directory scan — making them near-instant regardless of cache size.</p>

                        <h4><strong>How the index is built</strong></h4>
                        <p>The index is populated automatically during <strong>Preload All</strong>, <strong>Scheduled Preload</strong>, and on each Advanced tab visit. It grows incrementally — every successful single-page purge appends its path via write-back so the index improves over time without a full scan.</p>

                        <h4><strong>When to clear it</strong></h4>
                        <p>Clearing is only needed when the index contains stale or incorrect entries that can no longer be trusted:</p>
                        <ul>
                            <li>You moved the Nginx cache directory to a different path — all stored paths are now wrong.</li>
                            <li>You changed the <code>fastcgi_cache_key</code> directive — the stored URL-to-path mappings are no longer valid for the new key scheme.</li>
                            <li>You manually deleted the cache directory and recreated it — old paths no longer exist on disk.</li>
                            <li>Single-page purges are consistently reporting errors or not deleting the correct file.</li>
                        </ul>

                        <h4><strong>When NOT to clear it</strong></h4>
                        <p>Do not clear the index routinely — it is not a cache that needs regular flushing. Clearing it means the next purge operations must fall back to the recursive filesystem scan until the index is rebuilt. On large caches this adds latency to every single-page purge until the index is repopulated.</p>

                        <h4><strong>What happens after clearing</strong></h4>
                        <p>The index is deleted from the database. The next <strong>Preload All</strong>, <strong>Scheduled Preload</strong>, or <strong>Advanced tab visit</strong> will rebuild it automatically from a fresh directory scan. Single-page purges in the meantime will fall through to the recursive scan as a safe fallback — nothing breaks, purges still succeed.</p>

                        <h4><strong>Where to find it</strong></h4>
                        <p>Go to <strong>Status tab → Clear URL Index</strong> button.</p>
                    </div>
                </div>

                <h3 class="nppp-question">What is the proper PHP-FPM Nginx setup?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Proper PHP-FPM Nginx Setup</strong></h3>
                        <p>For a proper PHP-FPM Nginx setup, follow these steps:</p>
                        <h4><strong>PHP-FPM-USER (Website User)</strong></h4>
                        <p>The <b>PHP-FPM-USER</b> should be specifically created to run your website</p>
                        <h4><strong>WEBSERVER-USER (Web Server User)</strong></h4>
                        <p>Nginx must run with its own unprivileged user, such as <code>nginx</code> on RHEL-based systems or <code>www-data</code> on Debian-based systems.</p>
                        <h4><strong>Connecting PHP-FPM-USER and WEBSERVER-USER</strong></h4>
                        <p>Ensure that the web server user can read files belonging to the <b>PHP-FPM-GROUP</b>. Control file access via group chmod permission bits. Avoid granting additional group permissions to the <code>nginx/www-data</code> user to minimize security risks.</p>
                        <h4><strong>Set Website Content Permissions</strong></h4>
                        <p>Set the website content&#39;s group permission to <code>g=rX</code> to allow <code>nginx/www-data</code> to read all files and traverse all directories, but not write to them.</p>
                        <p><strong>IMPORTANT:</strong><br />
                        Granting additional group permissions to the <code>nginx/www-data</code> user can potentially introduce security risks due to the principle of least privilege. Your <b>PHP-FPM-USER</b> should never have sudo privileges, even if it&#39;s not listed in the sudoer list, as this can still pose security drawbacks. Therefore, we will set the website content&#39;s group permission to <code>g=rX</code> so that <code>nginx/www-data</code> can read all files and traverse all directories, but not write to them.</p>

                        <pre><code>usermod -a -G PHP-FPM-GROUP WEBSERVER-USER</code></pre>
                        <p><u>Add the <b>WEBSERVER-USER</b> (such as <code>nginx/www-data</code>) to the <b>PHP-FPM-GROUP</b>, allowing the web server user to access files belonging to this group.</u></p>

                        <pre><code>chown -R PHP-FPM-USER:PHP-FPM-GROUP /home/websiteuser/websitefiles</code></pre>
                        <p><u>Set the ownership of all files under <b>/home/websiteuser/websitefiles</b> to <b>PHP-FPM-USER</b> and <b>PHP-FPM-GROUP</b>, ensuring that <b>PHP-FPM</b> can read and write these files.</u></p>

                        <pre><code>chmod -R u=rwX,g=rX,o= /home/websiteuser/websitefiles</code></pre>
                        <p><u>Set permissions for the files under <b>/home/websiteuser/websitefiles</b> so that <b>PHP-FPM-USER</b> can read and write, <b>PHP-FPM-GROUP</b> (which includes the web server user) can read, and others have no access.</u></p>

                        <h4><strong>PHP-FPM Pool Settings</strong></h4>
                        <p>Adjust PHP-FPM pool settings in the configuration file to specify the user, group, ownership, and permissions for the PHP-FPM process.</p>

                        <pre>
[websiteuser.com]
user = websiteuser
group = websiteuser
listen.owner = nginx
listen.group = nginx
listen.mode = 0660
listen = /var/run/php-fcgi-websiteuser.sock</pre>
                    </div>
                </div>
            </div>
        </div>
        <div class="nppp-premium-widget">
            <div id="nppp-ad">
                <h3 class="textcenter"><?php echo esc_html__('Hope you are enjoying NPP! If it saves you time, consider giving it a star or sponsoring its development.', 'fastcgi-cache-purge-and-preload-nginx'); ?></h3>
                <p class="textcenter">
                        <img
                        src="<?php echo esc_url($image_url_ad); ?>"
                        alt="<?php echo esc_attr__('Give a Star', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                        title="<?php echo esc_attr__('Give a Star or Sponsor NPP', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                        width="100%"
                        height="auto">
                </p>
                <p class="textcenter">
                    <a href="https://wordpress.org/support/plugin/fastcgi-cache-purge-and-preload-nginx/reviews/#new-post" target="_blank" rel="noopener" class="button button-secondary" style="margin-right: 8px;">
                        ⭐ <?php echo esc_html__('Give a Star', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                    </a>
                    <a href="https://github.com/sponsors/psaux-it" target="_blank" rel="noopener" class="button button-secondary">
                        ❤️ <?php echo esc_html__('Sponsor', 'fastcgi-cache-purge-and-preload-nginx'); ?>
                    </a>
                </p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Shortcode to display the FAQ HTML
function nppp_my_faq_shortcode() {
    return nppp_my_faq_html();
}
