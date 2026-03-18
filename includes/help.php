<?php
/**
 * Help and FAQ renderer for Nginx Cache Purge Preload
 * Description: Outputs plugin documentation, onboarding guidance, and support information in admin.
 * Version: 2.1.4
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

                <h3 class="nppp-question">How does NPP purge the cache and what is the HTTP Purge?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>NPP Purge Workflow</strong></h3>
                        <p>NPP uses a layered purge strategy. For single-URL purges (manual, auto-purge on update, related URLs) it tries each path in order and stops as soon as one succeeds. Purge All always uses the filesystem directly.</p>

                        <h4><strong>Fast-Path 1 — HTTP Purge (optional)</strong></h4>
                        <p>If <strong>HTTP Purge</strong> is enabled in settings and the <code>ngx_cache_purge</code> Nginx module is detected, NPP sends an HTTP request to the module's purge endpoint. The module removes the cache entry from shared memory and disk atomically. On HTTP 200 the filesystem is never touched — the purge is complete. On any other response NPP falls through to the next path automatically.</p>

                        <h4><strong>Fast-Path 2 — Index lookup</strong></h4>
                        <p>NPP maintains a persistent URL→Filepath index built during Preloading. If the URL is found in the index and the file still exists, NPP deletes it directly with no directory scan needed.</p>

                        <h4><strong>Fast-Path 3 — Recursive filesystem scan</strong></h4>
                        <p>If neither fast-path succeeds, NPP walks the entire Nginx cache directory, reads each file's cache key header, and deletes the matching entry. This is the original workflow and remains the fallback for all environments.</p>

                        <h4><strong>Purge All</strong></h4>
                        <p>Purge All always uses filesystem operations — it recursively removes the entire cache directory contents. HTTP Purge does not apply to Purge All. If Cloudflare APO Sync or Redis Object Cache Sync is enabled, those are triggered after the filesystem purge completes.</p>

                        <h4><strong>When HTTP Purge is not available</strong></h4>
                        <p>HTTP Purge is entirely optional. If the module is not present, not compiled, or the purge location block is not configured in Nginx, NPP falls back to the index and filesystem paths automatically. The existing workflow is fully preserved — nothing breaks.</p>

                        <h4><strong>How to enable HTTP Purge</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong> and turn on <strong>HTTP Purge</strong>. Use the <strong>Test Connection</strong> button to verify the module is reachable. Works out of the box on stacks that ship with <code>ngx_cache_purge</code> pre-compiled: GridPane, WordOps, EasyEngine, CentminMod, RunCloud, SlickStack, and servers using the Ubuntu <code>nginx-extras</code> package.</p>
                    </div>
                </div>

                <h3 class="nppp-question">How do I configure Nginx for HTTP Purge and what options does NPP provide?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h3><strong>Nginx Configuration for HTTP Purge</strong></h3>
                        <p>HTTP Purge requires the <code>ngx_cache_purge</code> module to be compiled into Nginx and a dedicated purge location block added to your Nginx configuration. Without both, the feature cannot work — but NPP falls back to filesystem purge automatically so nothing breaks.</p>

                        <h4><strong>Required Nginx config</strong></h4>
                        <p>You need two things in your Nginx server block: a <code>fastcgi_cache_path</code> with a named zone, and a location block that handles purge requests using that zone. A minimal working example:</p>

<pre><code>## In the http {} block:
fastcgi_cache_path /var/cache/nginx levels=1:2 keys_zone=my_cache:10m inactive=60m;
fastcgi_cache_key "$scheme$request_method$host$request_uri";

## In the server {} block:
fastcgi_cache my_cache;
fastcgi_cache_valid 200 301 302 60m;

## Purge location — required for HTTP Purge fast-path:
location ~ /purge(/.*) {
    allow 127.0.0.1;
    deny all;
    fastcgi_cache_purge my_cache "$scheme$request_method$host$1";
}</code></pre>

                        <p>The location prefix (<code>/purge</code>) must match what NPP is configured to send purge requests to. The cache key in the purge block (<code>$scheme$request_method$host$1</code>) must match your <code>fastcgi_cache_key</code> exactly — with <code>$1</code> capturing the path after <code>/purge</code>.</p>

                        <h4><strong>How NPP builds the purge URL</strong></h4>
                        <p>When NPP purges <code>https://example.com/my-page/</code> it sends a GET request to <code>https://example.com/purge/my-page/</code>. Nginx matches the purge location, strips <code>/purge</code>, and deletes the cache entry for the remaining path. HTTP 200 = purge confirmed. HTTP 412 = path not in cache (already gone). Any other response = NPP falls through to filesystem.</p>

                        <h4><strong>NPP settings for HTTP Purge</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong>. Three options control how NPP builds the purge URL:</p>

                        <p><strong>HTTP Purge URL Suffix</strong> (default: <code>purge</code>)<br>
                        The path prefix NPP prepends when building purge requests. Change this if your Nginx purge location uses a different prefix — for example if your location is <code>~ /cache-purge(/.*)</code> set this to <code>cache-purge</code>. NPP will then send requests to <code>https://example.com/cache-purge/my-page/</code>.</p>

                        <p><strong>HTTP Purge Custom Base URL</strong> (optional)<br>
                        Overrides the suffix entirely. Use this when the purge endpoint is on a different host, port, or internal address — the most common case being Docker where the purge endpoint is not reachable via the public hostname. Examples:</p>
                        <ul>
                            <li><code>http://nginx/purge</code> — Docker service name</li>
                            <li><code>http://127.0.0.1:8080/purge</code> — non-standard port</li>
                            <li><code>http://localhost/purge</code> — explicit localhost</li>
                        </ul>
                        <p>When a Custom Base URL is set the suffix field is ignored entirely.</p>

                        <h4><strong>Detection probe</strong></h4>
                        <p>The <strong>Test Connection</strong> button (and automatic background detection) works by sending a GET request to a random path under the purge endpoint — e.g. <code>https://example.com/purge/nppp-probe-abc123</code>. Since this path can never be in cache, the <code>ngx_cache_purge</code> module always responds with HTTP 412. NPP treats HTTP 412 with a small response body as proof the module is active. Any other response means the module is unavailable and NPP stays on the filesystem path. Detection result is cached for 12 hours and re-checked whenever a purge returns an unexpected response code.</p>

                        <h4><strong>Common issues</strong></h4>
                        <ul>
                            <li><strong>Test Connection returns "not detected"</strong> — The purge location block is missing, the module is not compiled, or the allow/deny rules are blocking PHP's request. Check that <code>allow 127.0.0.1</code> covers the IP PHP is making requests from (in Docker this is the container IP, not <code>127.0.0.1</code>).</li>
                            <li><strong>Cache key mismatch</strong> — If the purge location cache key does not exactly match <code>fastcgi_cache_key</code>, Nginx will look in the wrong shared memory slot and always return 412 even for cached pages. Double-check both keys are identical.</li>
                            <li><strong>Docker / reverse proxy</strong> — PHP inside a container cannot reach <code>https://example.com/purge</code> via the public hostname. Use the <strong>Custom Base URL</strong> option with the internal Docker service name or container IP instead.</li>
                            <li><strong>HTTPS with self-signed cert</strong> — NPP disables SSL verification for purge requests so self-signed certificates are not a problem.</li>
                        </ul>
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
                        Use the  linux packages from the
                        <a href="https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases" target="_blank" rel="noopener">Releases page</a>.
                        Below are quick examples—see GitHub for full details.
                    </p>

                    <ol class="nginx-list" style="font-size: 14px;">
                        <li><strong>Debian / Ubuntu (.deb)</strong>
                            <pre>wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.4/SHA256SUMS

# x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.4/safexec_1.9.2-1_amd64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install ./safexec_1.9.2-1_amd64.deb

# arm64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.4/safexec_1.9.2-1_arm64.deb
sha256sum -c SHA256SUMS --ignore-missing
sudo apt install ./safexec_1.9.2-1_arm64.deb</pre>
                        </li>

                        <li><strong>RHEL / CentOS / Fedora (.rpm)</strong>
                            <pre>wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.4/SHA256SUMS
# x86_64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.4/safexec-1.9.2-1.el10.x86_64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install ./safexec-1.9.2-1.el10.x86_64.rpm

# arm64
wget https://github.com/psaux-it/nginx-fastcgi-cache-purge-and-preload/releases/download/v2.1.4/safexec-1.9.2-1.el10.aarch64.rpm
sha256sum -c SHA256SUMS --ignore-missing
sudo dnf install ./safexec-1.9.2-1.el10.aarch64.rpm</pre>
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
                        <p>NPP and Redis Object Cache are two separate caching layers. NPP manages the Nginx full-page cache (FastCGI cache on disk or in RAM). Redis Object Cache stores WordPress database query results and object data in memory. This sync feature keeps both layers consistent with each other through a bidirectional relationship.</p>

                        <h4><strong>Requirements</strong></h4>
                        <p>The <strong>Redis Object Cache</strong> plugin by Till Krüss must be installed, active, and connected to a live Redis server. NPP checks for both the drop-in (<code>WP_REDIS_VERSION</code> constant) and a live connection (<code>redis_status() === true</code>) at runtime. If Redis is unreachable the toggle auto-disables itself.</p>

                        <h4><strong>How to enable</strong></h4>
                        <p>Go to <strong>Settings → NPP Settings → Advanced</strong> and turn on <strong>Redis Object Cache Sync</strong>. The toggle shows as <em>Unavailable</em> in the dashboard widget when the Redis plugin is not installed or Redis is disconnected.</p>

                        <h4><strong>Direction 1 — NPP Purge All → Redis flush</strong></h4>
                        <p>Whenever NPP's <strong>Purge All</strong> runs (manually, via admin bar, Auto Purge, REST API, or Schedule), NPP calls <code>wp_cache_flush()</code> immediately after clearing the Nginx cache. This ensures PHP regenerates fresh data from the database on the next request, so pages rebuilt into the Nginx cache contain up-to-date content rather than stale object-cached results.</p>

                        <h4><strong>Direction 2 — Redis Flush → NPP Purge All</strong></h4>
                        <p>Whenever Redis is flushed from outside NPP — via the Redis Object Cache plugin dashboard, WP-CLI (<code>wp cache flush</code>), or any plugin calling <code>wp_cache_flush()</code> — NPP automatically triggers a full Nginx cache purge in response. This direction only activates when NPP's <strong>Auto Purge</strong> setting is also enabled, since a full filesystem purge is a heavyweight operation.</p>

                        <h4><strong>Loop prevention</strong></h4>
                        <p>NPP sets an internal origin flag before triggering either direction. If Direction 1 causes a Redis flush, Direction 2 sees the flag and bails — and vice versa. This prevents an infinite purge loop between the two cache layers.</p>

                        <h4><strong>Important notes</strong></h4>
                        <p>Direction 2 respects the <strong>Auto Purge</strong> toggle — if Auto Purge is off, a Redis flush from outside NPP will not trigger an Nginx purge. If you want full bidirectional sync, both <strong>Redis Object Cache Sync</strong> and <strong>Auto Purge</strong> must be enabled.</p>
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
<code>[websiteuser.com]
user = websiteuser
group = websiteuser
listen.owner = nginx
listen.group = nginx
listen.mode = 0660
listen = /var/run/php-fcgi-websiteuser.sock</code></pre>
                    </div>
                </div>
            </div>
        </div>
        <div class="nppp-premium-widget">
            <div id="nppp-ad">
                <div class="textcenter">
                    <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell-top" data-pro-ad="sidebar-logo">
                        <img
                            src="<?php echo esc_url($image_url_space); ?>"
                            alt="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                            title="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                            width="100"
                            height="100">
                    </a>
                </div>
                <h3 class="textcenter">Hope you are enjoying NPP! Do you still need assistance with the server side integration? Get our server integration service now and optimize your website's caching performance!</h3>
                <p class="textcenter">
                    <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell" data-pro-ad="sidebar-logo">
                        <img
                            src="<?php echo esc_url($image_url_ad); ?>"
                            alt="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                            title="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                            width="100%"
                            height="auto">
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
