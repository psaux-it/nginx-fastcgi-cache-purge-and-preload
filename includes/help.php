<?php
/**
 * FAQ for FastCGI Cache Purge and Preload for Nginx
 * Description: This help file contains informations about FastCGI Cache Purge and Preload for Nginx plugin usage.
 * Version: 2.0.4
 * Author: Hasan ÇALIŞIR
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
    <div class="nppp-premium-container">
        <div class="nppp-premium-wrap">
            <div id="nppp-accordion" class="accordion">
                <h3 class="nppp-question">Why plugin not functional on my environment?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p>This plugin is compatible exclusively with <strong>Nginx web servers</strong> running on <strong>Linux-powered</strong> systems. Additionally, the <strong>shell_exec</strong> function must be enabled and unrestricted. Consequently, the plugin may not operate fully on <strong>shared hosting</strong> environments where native <strong>Linux commands</strong> are blocked from running via PHP.</p>
                        <p>Moreover, a correctly configured PHP-FPM and Nginx setup is essential for the purge and preload operations to function properly. Otherwise, permission issues may arise.</p>
                        <p>If warnings appear or plugin settings/tabs disabled, they may indicate permission issues, an unsupported environment, or missing dependencies required for cache operations.</p>
                    </div>
                </div>

                <h3 class="nppp-question">What Linux commands are required for the preload action?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p>For the preload action to work properly, the server needs to have the <code>wget</code> command installed. The plugin uses <code>wget</code> to preload cache by fetching pages. Additionally, it’s highly recommended to have the <code>cpulimit</code> command installed to manage server load effectively during the cache preloading action.</p>
                    </div>
                </div>

                <h3 class="nppp-question">Why is the purge & preload actions not functioning properly?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <ol class="nginx-list">
                            <p><strong>Technical Background:</strong></p>
                            <p>In properly configured Nginx servers, it is not strictly necessary to have separate <code>PHP-FPM-USER</code> (as known WEBSITE-USER) and <code>WEBSERVER-USER</code> (commonly, nginx or www-data), but there are scenarios where separating these users can enhance security and performance. Here’s why:</p>
                            <ul>
                                <li><strong>Security:</strong> By running the PHP-FPM process under a different user than the Nginx web server, you reduce the risk of privilege escalation. If one process is compromised, the attacker does not automatically gain control over the other process.</li>
                                <li><strong>Permission Management:</strong> Having separate users allows for more granular permission settings. For example, PHP scripts can be restricted to only the directories they need to access, while the web server user can be given more restrictive permissions on other parts of the filesystem.</li>
                                <li><strong>Resource Management:</strong> Separate users can help with resource management and monitoring, as it becomes easier to track resource usage per user.</li>
                            </ul>
                            <p><strong>Problem Statement:</strong></p>
                            <ul>
                                <li>The issue with <strong>Nginx cache</strong> purging often arises in environments where two distinct users are involved.</li>
                                <li>The <code>WEBSERVER-USER</code> is responsible for creating cache folders and files with strict permissions, while the <code>PHP-FPM-USER</code> handles cache purge & preload operations but lacks necessary privileges.</li>
                                <li>Even when <code>PHP-FPM-USER</code> is added to the <code>WEBSERVER-GROUP</code>, permission conflicts persist.</li>
                                <li>This is because Nginx overrides default setfacl settings, ignoring Access Control Lists (ACLs) and creating cache folders and files with strict permissions.</li>
                            </ul>
                            <p>This plugin also addresses the challenge of automating cache purging and preloading in Nginx environments that involve two distinct users, <code>WEBSERVER-USER</code> and <code>PHP-FPM-USER</code>, by offering an alternative simple approach. It accomplishes this with the help of server-side tools <code>inotifywait</code> and <code>setfacl</code>.</p>
                        </ol>
                    </div>
                </div>

                <h3 class="nppp-question">What is the solution for the permission issues?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p>In case your current web server setup involves two distinct users, <strong>WEBSERVER-USER</strong> and <strong>PHP-FPM-USER</strong>, the solution proposed by this plugin involves combining Linux server side tools <strong>inotifywait</strong> with <strong>setfacl</strong> to automatically grant write permissions to the <strong>PHP-FPM-USER</strong> for the corresponding <strong>Nginx Cache Paths</strong>, facilitated by server-side bash scripting. Users need to manage <strong>inotifywait</strong> and <strong>setfacl</strong> operations manually or use the provided basic bash script for fully functional purge and preload actions provided by this plugin. If you prefer to use the pre-made automation bash script, you can find the necessary information below.</p>
                        <p><strong>Shortly:</strong></p>
                        <ol class="nginx-list">
                            <li>Solution involves combining <strong>inotifywait</strong> with <strong>setfacl</strong> under <strong>root</strong>.</li>
                            <li>Additional step required to make cache purge & preload operation work seamlessly.</li>
                            <li>We need to grant write permission to the <code>PHP-FPM-USER</code> for the Nginx Cache folder.</li>
                            <li>This task can be facilitated by automation.</li>
                        </ol>
                    </div>
                </div>

                <h3 class="nppp-question">Is there any solution to automate granting write permission to the PHP-FPM-USER?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <ol class="nginx-list">
                            <pre><code>bash &lt;(curl -Ss https://psaux-it.github.io/install.sh)</code></pre>
                            <li>🎉 This Bash script automates the management of <strong>inotify/setfacl</strong> operations to grant write permission to the <code>PHP-FPM-USER</code> for the Nginx cache folder, ensuring efficiency and security. It enhances the efficiency and security of cache management tasks by automating the setup and configuration processes.</li>
                            <li>The <code>install.sh</code> script serves as a wrapper that facilitates the execution of the main <code>fastcgi_ops_root.sh</code> script from <a href="https://psaux-it.github.io">psaux-it.github.io</a>. It acts as a convenient entry point for users to initiate the setup and configuration procedures seamlessly. Rest assured, this solution is entirely safe to use, providing a reliable and straightforward method for managing Nginx cache operations.</li>
                            <h3>Features:</h3>
                            <ul>
                                <li><strong>Automated Setup:</strong> Quickly sets up Nginx cache paths and associated PHP-FPM users.</li>
                                <li><strong>Dynamic Configuration:</strong> Detects Nginx configuration dynamically for seamless integration.</li>
                                <li><strong>ACL Verification:</strong> Ensures filesystem ACL configuration for proper functionality.</li>
                                <li><strong>Systemd Integration:</strong> Generates and enables systemd service for continuous operation.</li>
                                <li><strong>Manual Configuration Support:</strong> Allows manual configuration for customized setups.</li>
                                <li><strong>Inotify Operations:</strong> Listens to Nginx cache folder events for real-time updates.</li>
                            </ul>

                            <h3>Tip</h3>
                            <p>Furthermore, if you're hosting multiple WordPress sites each with their own Nginx cache paths and associated PHP-FPM pool users on the same host, you'll find that deploying just one instance of this script effectively manages all WordPress instances using the NPP plugin. This streamlined approach centralizes cache management tasks, ensuring optimal efficiency and simplified maintenance throughout your server environment.</p>

                            <p>If auto-detection does not work for you, for proper matching, please ensure that your Nginx Cache Path includes the associated PHP-FPM-USER username.</p>

                            <p>For example, assuming your PHP-FPM-USER = <code>psauxit</code>, the following example <code>fastcgi_cache_path</code> naming formats will match perfectly with your PHP-FPM-USER and be detected by the script automatically:</p>
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
                        <p>The Nginx Cache Directory option has restrictions on the paths you can use to prevent accidental deletions or harm to critical system files. By default, certain paths, like ‘/home’ and other vital system directories, are blocked to safeguard your system’s stability and prevent data loss.</p>
                        <p>While this might limit your options, it ensures your system’s security. Recommended directories to choose from, such as ‘/dev/shm/’ or ‘/var/cache/’, which are commonly used for caching purposes and are generally safer.</p>
                    </div>
                </div>

                <h3 class="nppp-question">What is different about this plugin compared to other Nginx Cache Plugins?</h3>
                <div class="nppp-answer">
                    <div class="nppp-answer-content">
                        <p>NPP offers a more direct solution without any external NGINX module such as <strong>Cache Purge module</strong>. This plugin directly traverses the cache directory and clears cache if PHP-FPM and WEBSERVER user permissions are adjusted properly. To automate and fix these permission issues, there is a pre-made bash script that works on the server side.</p>
                        <pre><code>bash &lt;(curl -Ss https://psaux-it.github.io/install.sh)</code></pre>
                        <p>Note that NPP also supports Nginx cache preloading with a simple direct approach, with the help of <code>wget</code>. This feature is missing in other Nginx Cache plugins.</p>
                        <p>There are many cases where the external Nginx modules work fine for cache purge operations, but integrating the module with Nginx can be challenging for non-technical or regular WordPress users. Not every Linux distro packages this module or has outdated module versions, so users may need to follow extra steps to integrate it, which becomes more complicated.</p>
                        <p>Also, there are other cases where even bleeding-edge module versions are easily installed and integrated with Nginx by users, but purge operations do not work as expected. This is what I faced before and decided to develop NPP.</p>
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
                        <img src="<?php echo esc_url($image_url_bar); ?>" alt="Nginx Cache Purge & Preload PRO" title="Nginx Cache Purge & Preload PRO" style="width: 60px !important;">
                    </a>
                </div>
                <h3 class="textcenter">Hope you are enjoying NPP! Do you still need assistance with the server side integration? Get our server integration service now and optimize your website's caching performance!</h3>
                <p class="textcenter">
                    <a href="https://www.psauxit.com/nginx-fastcgi-cache-purge-preload-for-wordpress/" class="open-nppp-upsell" data-pro-ad="sidebar-logo">
                        <img src="<?php echo esc_url($image_url_ad); ?>" alt="Nginx Cache Purge & Preload PRO" title="Nginx Cache Purge & Preload Pro">
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
