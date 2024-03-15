<?php
/**
 * FAQ for Nginx FastCGI Cache Purge and Preload
 * Description: This help file contains informations about plugin usage.
 * Version: 1.0.1
 * Author: Hasan ÇALIŞIR
 * Author Email: hasan.calisir@psauxit.com
 * Author URI: https://www.psauxit.com
 * License: GPL-2.0+
 */

// Function to output the FAQ HTML
function my_faq_html() {
    ob_start();
    ?>
    <div class="faq">
        <div class="question">Why is the Purge Nginx Cache not functioning properly?</div>
        <div class="answer">
          <ol class="nginx-list">
            <ul>
              <li>The issue with Nginx FastCGI cache purging often arises in environments where two distinct users, <code>WEBSERVER-USER</code> and <code>PHP-FPM-USER</code>, are involved.</li>
              <li>The <code>WEBSERVER-USER</code> is responsible for creating cache folders and files with strict permissions, while the <code>PHP-FPM-USER</code> handles cache purge operations but lacks necessary privileges.</li>
              <li>Even when <code>PHP-FPM-USER</code> is added to the <code>WEBSERVER-GROUP</code>, permission conflicts persist.</li>
              <li>This is because Nginx overrides default setfacl settings, ignoring Access Control Lists (ACLs) and creating cache folders and files with strict permissions.</li>
            </ul>
          </ol>
        </div>
    </div>

    <div class="faq">
        <div class="question">What is the solution for the Purge Nginx Cache issue?</div>
        <div class="answer">
          <ol class="nginx-list">
            <li>Solution involves combining <strong>inotifywait</strong> with <strong>setfacl</strong> under <strong>root</strong>.</li>
            <li>Additional step required to make purge operation works seamlessly.</li>
            <li>We need to grant write permission to the <strong>PHP-FPM-USER</strong> for the Nginx Cache folder.</li>
            <li>This task can be facilitated by a automation.</li>
          </ol>
        </div>
    </div>

    <div class="faq">
        <div class="question">Is there any solution to automate granting write permission to the PHP-FPM-USER for Multisite?</div>
        <div class="answer">
          <ol class="nginx-list">
<pre>
<code>bash &lt;(curl -Ss https://psaux-it.github.io/install.sh)</code></pre>
            <li>🎉 This Bash script automates the management of inotify/setfacl operations for multisite, ensuring efficiency and security. It enhances the efficiency and security of cache management tasks by automating the setup and configuration processes.</li>
            <li>The <code>install.sh</code> script serves as a wrapper that facilitates the execution of the main <code>fastcgi_ops_root.sh</code> script from <a href="https://psaux-it.github.io">psaux-it.github.io</a>. It acts as a convenient entry point for users to initiate the setup and configuration procedures seamlessly. Rest assured, this solution is entirely safe to use, providing a reliable and straightforward method for managing Nginx FastCGI cache operations.</li>
            <h3>Features:</h3>
            <ul>
              <li><strong>Automated Setup:</strong> Quickly sets up FastCGI cache paths and associated PHP-FPM users.</li>
              <li><strong>Dynamic Configuration:</strong> Detects Nginx configuration dynamically for seamless integration.</li>
              <li><strong>ACL Verification:</strong> Ensures filesystem ACL configuration for proper functionality.</li>
              <li><strong>Systemd Integration:</strong> Generates and enables systemd service for continuous operation.</li>
              <li><strong>Manual Configuration Support:</strong> Allows manual configuration for customized setups.</li>
              <li><strong>Inotify Operations:</strong> Listens to FastCGI cache folder events for real-time updates.</li>
            </ul>
          </ol>
        </div>
    </div>

    <div class="faq">
        <div class="question">What is the proper PHP-FPM Nginx setup?</div>
        <div class="answer">

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

<pre>
<code>usermod -a -G PHP-FPM-GROUP WEBSERVER-USER</code></pre>
         <p><u>Add the <b>WEBSERVER-USER</b> (such as <code>nginx/www-data</code>) to the <b>PHP-FPM-GROUP</b>, allowing the web server user to access files belonging to this group.</u></p>
<pre>
<code>chown -R PHP-FPM-USER:PHP-FPM-GROUP /home/websiteuser/websitefiles</code></pre>
         <p><u>Set the ownership of all files under <b>/home/websiteuser/websitefiles</b> to <b>PHP-FPM-USER</b> and <b>PHP-FPM-GROUP</b>, ensuring that <b>PHP-FPM</b> can read and write these files.</u></p>
<pre>
<code>chmod -R u=rwX,g=rX,o= /home/websiteuser/websitefiles</code></pre>
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

    <script>
        // JavaScript to toggle answer visibility
        const questions = document.querySelectorAll('.question');
        questions.forEach(question => {
            question.addEventListener('click', () => {
                question.nextElementSibling.classList.toggle('answer');
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

// Shortcode to display the FAQ HTML
function my_faq_shortcode() {
    return my_faq_html();
}
add_shortcode('my_faq', 'my_faq_shortcode');
