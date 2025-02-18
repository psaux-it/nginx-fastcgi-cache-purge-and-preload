<?php
/**
 * FAQ for FastCGI Cache Purge and Preload for Nginx
 * Description: This help file contains informations about FastCGI Cache Purge and Preload for Nginx plugin usage.
 * Version: 2.0.9
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
    // img url's
    $image_url_bar = plugins_url('/admin/img/bar.png', dirname(__FILE__));
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
                            <li>The <code>install.sh</code> script serves as a wrapper for executing the main <code>fastcgi_ops_root.sh</code> script from <a href="https://psaux-it.github.io">psaux-it.github.io</a>. This bash script is designed for the FastCGI Cache Purge and Preload for Nginx WordPress plugin (NPP).</li>
                            <li>The script first attempts to automatically identify the <strong>PHP-FPM-USER</strong> (also known as the PHP process owner or website user) along with their associated Nginx Cache Paths.</li>
                            <li>If it cannot automatically match the PHP-FPM-USER with their respective Nginx Cache Path, it provides an easy manual setup option using the <code>manual-configs.nginx</code> file.</li>
                            <li>According to matches this script automates the management of Nginx Cache Paths. It utilizes <strong>bindfs</strong> to create a FUSE mount of the original Nginx Cache Paths, enabling the <strong>PHP-FPM-USER</strong> to write to these directories with the necessary permissions automatically.</li>
                            <li>After the setup (whether automatic or manual) is completed, the script creates an <code>npp-wordpress</code> systemd service that can be managed from the WordPress admin dashboard under the NPP plugin <strong>STATUS</strong> tab.</li>
                            <li>Additionally, NPP users have the flexibility to manage FUSE mount and unmount operations for the original Nginx Cache Path directly from the WP admin dashboard, effectively preventing unexpected permission issues and maintaining consistent cache stability.</li>

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
                        </ol>
                    </div>
                </div>

                <h3 class="nppp-question">Why can’t I use my preferred path for the Nginx Cache Directory?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p style="font-size: 14px;">The Nginx Cache Directory option has restrictions on the paths you can use to prevent accidental deletions or harm to critical system files. By default, certain paths, like ‘/home’ and other vital system directories, are blocked to safeguard your system’s stability and prevent data loss.</p>
                        <p style="font-size: 14px;">While this might limit your options, it ensures your system’s security. Recommended directories to choose from, such as ‘/dev/shm/’ or ‘/var/cache/’, which are commonly used for caching purposes and are generally safer.</p>
                        <h4>Allowed Cache Paths:</h4>
                        <ul>
                            <li><strong>For RAM-based:</strong> Use directories under <code>/dev/</code>, <code>/tmp/</code>, or <code>/var/</code>.</li>
                            <li><strong>For persistent disk:</strong> Use directories under <code>/opt/</code>.</li>
                            <li><strong>Important:</strong> Paths must be one level deeper (e.g., <code>/var/cache</code>).</li>
                        </ul>
                    </div>
                </div>

                <h3 class="nppp-question">What is different about this plugin compared to other Nginx Cache Plugins?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <table class="responsive-table">
                          <thead>
                            <tr>
                              <th>Aspect</th>
                              <th>Nginx Cache Purge Module</th>
                              <th>NPP</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr>
                              <td>Ease of Setup</td>
                              <td>Requires module installation and Nginx recompilation.</td>
                              <td>Simple to implement once permissions are set correctly, no need for Nginx recompilation.</td>
                            </tr>
                            <tr>
                              <td>Granularity</td>
                              <td>Allows precise cache purging based on URLs or cache keys.</td>
                              <td>Can be controlled at a file level, offering flexibility in managing cache and other resources.</td>
                            </tr>
                            <tr>
                              <td>Security</td>
                              <td>Built-in access control via HTTP request.</td>
                              <td>Offers greater security control by leveraging existing filesystem permissions.</td>
                            </tr>
                            <tr>
                              <td>Performance</td>
                              <td>Efficient, handled by Nginx.</td>
                              <td>Direct deletion is faster in certain cases when targeting specific files, with no need to rely on Nginx processing.</td>
                            </tr>
                            <tr>
                              <td>Integration</td>
                              <td>Seamless integration with Nginx’s cache system.</td>
                              <td>Works independently of Nginx and can be adapted for various cache systems, offering broader application.</td>
                            </tr>
                            <tr>
                              <td>Automation</td>
                              <td>Simple automation via HTTP request (e.g., `PURGE` request).</td>
                              <td>Highly customizable with scripts to manage cache purging, allowing greater automation and flexibility.</td>
                            </tr>
                          </tbody>
                      </table>
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

                <!-- Start of GDPR Compliance Section -->
                <h3 class="nppp-question">GDPR Compliance and Data Collection</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <h1>GDPR Compliance and Data Collection</h1>
                        <p>The <strong>FastCGI Cache Purge and Preload for Nginx</strong> plugin collects essential technical data to improve plugin functionality and performance, while fully adhering to <strong>GDPR</strong> (General Data Protection Regulation) guidelines. Below, we outline the data we collect and explain how it complies with GDPR principles.</p>

                        <h2>What Data Does the Plugin Collect?</h2>
                        <p>NPP only collects technical data related to its functionality. Specifically, we collect the following metrics:</p>
                        <ul>
                            <li><strong>Site URL:</strong> The URL of the website where the plugin is installed.</li>
                            <li><strong>Plugin Name:</strong> The name of the plugin being used.</li>
                            <li><strong>Plugin Version:</strong> The version of the plugin currently active.</li>
                            <li><strong>Plugin Status:</strong> The current status of the plugin (active/inactive/opt-out).</li>
                        </ul>
                        <div class="highlight">
                            <p><strong>Important Note:</strong> We do <strong>not</strong> collect any personal or sensitive data. The collected data is strictly related to the operation of the plugin and is used solely for improving the plugin’s functionality and tracking its activation/deactivation status.</p>
                        </div>

                        <h2>Why Do We Collect This Data?</h2>
                        <p>We collect this data for the following reasons:</p>
                        <ul>
                            <li><strong>Plugin Usage Statistics:</strong> Understanding how many users are actively using the plugin helps us improve its performance, update features, and ensure compatibility with newer versions of WordPress and Nginx.</li>
                            <li><strong>Version Tracking:</strong> By tracking the version of the plugin in use, we can provide better support and recommendations for updates, ensuring that users benefit from the latest features and security improvements.</li>
                            <li><strong>Status Monitoring:</strong> Tracking the plugin’s activation and deactivation helps us improve stability, quickly detect issues, and better understand user behavior to enhance the plugin’s reliability.</li>
                        </ul>

                        <h2>GDPR Compliance</h2>
                        <p>We take GDPR compliance seriously. Here’s why the data we collect is fully compliant with GDPR regulations:</p>

                        <h3>1. No Personal Data Collected</h3>
                        <p>We do not collect any personally identifiable information (PII) such as names, email addresses, or IP addresses. The data we collect is strictly technical (e.g., site URL, plugin version) and is necessary for plugin functionality and performance improvements.</p>

                        <h3>2. Legitimate Interest</h3>
                        <p>The data we collect falls under the legitimate interest clause of GDPR, as it is necessary for us to maintain, improve, and support the plugin. By collecting only the data that is essential for improving user experience, we ensure that no unnecessary data is collected.</p>

                        <h3>3. Data Security</h3>
                        <p>All data is transmitted securely over HTTPS, ensuring that it is protected from interception during transmission. We use JWT (JSON Web Token) for secure authentication of data requests, further safeguarding the data.</p>

                        <h3>4. Transparency</h3>
                        <p>We are fully transparent about the data we collect. Users are informed about what data is being collected and why, through our privacy policy and plugin documentation.</p>

                        <h3>5. User Control and Opt-Out</h3>
                        <p>Users have full control over the data collection process. Our plugin provides an opt-out option, allowing users to disable data tracking at any time if they choose not to participate in usage statistics collection. This ensures that users have full autonomy over their data.</p>

                        <h2>Conclusion</h2>
                        <p>NPP collects only technical data essential for improving its performance and functionality. No personal data is collected, and all data is transmitted securely, ensuring full compliance with GDPR. Users have full control over the process and can opt-out at any time.</p>

                        <div class="highlight">
                            <p>If you have any questions or concerns regarding the data we collect or our GDPR compliance, please feel free to contact us at <a href="mailto:support@psauxit.com">support@psauxit.com</a>.</p>
                        </div>
                    </div>
                </div>
                <!-- End of GDPR Compliance Section -->
            </div>
        </div>
        <div class="nppp-premium-widget">
            <div id="nppp-ad">
                <div class="textcenter">
                    <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell-top" data-pro-ad="sidebar-logo">
                        <img
                            src="<?php echo esc_url($image_url_bar); ?>"
                            alt="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                            title="<?php echo esc_attr__('Nginx Cache Purge & Preload PRO', 'fastcgi-cache-purge-and-preload-nginx'); ?>"
                            width="90"
                            height="90">
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
                <p class="textcenter"><a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="button button-primary button-large open-nppp-upsell" data-pro-ad="sidebar-button">Get Service</a></p>
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
