<?php
/**
 * Server Diagnostics for NPP (Nginx Cache Purge Preload) Wordpress Plugin
 *
 * 📌 Purpose:
 * This script is designed to test critical server environment components required by the
 * **NPP (Nginx Cache Purge Preload)** WordPress plugin. It helps diagnose issues related to:
 * - PHP `shell_exec()` availability
 * - `wget` binary presence
 * - Cache directory writability by the PHP process
 * - Correct URL resolution for preload requests
 *
 * ✅ If all checks pass, the script performs a sample `wget` preload request to confirm that
 * the system is ready for automated cache preloading.
 *
 * 🚀 Usage Instructions:
 * 1. Place this script in the **root directory of your WordPress installation** (e.g., `/var/www/html/`).
 *    It must be web-accessible like: `https://example.com/npp-test.php`.
 *
 * 2. Update the `$cachePath` variable near the top of the script:
 *    ```php
 *    $cachePath = '/path/to/your/nginx/cache'; // e.g., /opt/nginx-cache or /dev/shm/nginx-cache
 *    ```
 *    This should point to the **directory where Nginx stores its cache**, and it must be writable
 *    by the PHP process owner (typically `www-data`, `nginx`, or `apache`).
 *
 * 3. Visit the script in your browser to view a full diagnostic report.
 *
 * 🔒 Security Note:
 * - This script executes shell commands. DO NOT leave it permanently exposed to the internet.
 *   Delete or restrict access to it after use.
 * - Optionally, you can use `.htaccess`, Nginx auth, or IP restrictions for protection.
 *
 * Version: 1.0
 * Author: Hasan CALISIR
 */

declare(strict_types=1);
$cachePath  = '/opt/nginx-cache';

// 1) Show all errors directly in the browser
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 2) Basic HTML wrapper
echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
   . "  <meta charset=\"UTF-8\">\n"
   . "  <title>Server Diagnostics</title>\n</head>\n<body>\n"
   . "<h1>Server Diagnostics</h1>\n";

// Flag to determine if all checks passed
$canProceed = true;

// 3) Test shell_exec()
$test = @shell_exec('echo OK 2>&1');
if ($test === null || strpos($test, 'OK') === false) {
    echo "<p style=\"color:red;\"><strong>❌ shell_exec()</strong> is disabled or not functioning correctly.</p>\n";
    $canProceed = false;
} else {
    echo "<p style=\"color:green;\"><strong>✅ shell_exec()</strong> is enabled.</p>\n";
}

// 4) Choose which command to test
$cmdToCheck = 'wget';

// 5) Test the command via shell_exec()
$path = @shell_exec("command -v " . escapeshellarg($cmdToCheck) . " 2>/dev/null");
$path = ($path === null ? '' : trim($path));

// 5.1) If missing, print and exit
if ($path === '') {
    echo "<p style=\"color:red;\">\n"
       . "  <strong>❌ {$cmdToCheck}</strong> command not found in PATH.\n"
       . "</p>\n"
       . "</body></html>";
    $canProceed = false;
} else {
    // 5.2) If found, report and proceed
    echo "<p style=\"color:green;\">\n"
       . "  <strong>✅ {$cmdToCheck}</strong> found at <code>" . htmlspecialchars($path) . "</code>.\n"
       . "</p>\n";
}

// 6) Print PHP process owner
if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
    $userInfo = posix_getpwuid(posix_geteuid());
    $owner   = $userInfo['name'] ?? 'unknown';
} else {
    $owner = trim((string) shell_exec('whoami')) ?: 'unknown';
}
echo "<p style=\"color:blue;\"><strong>👤 Process Owner:</strong> <code>" . htmlspecialchars($owner) . "</code></p>\n";

// 7) Check directory existence and writability by php process owner
if (!file_exists($cachePath)) {
    echo "<p style=\"color:orange;\"><strong>⚠️ Cache Path:</strong> <code>" . htmlspecialchars($cachePath) . "</code> does not exist.</p>\n";
} elseif (!is_writable($cachePath)) {
    echo "<p style=\"color:red;\"><strong>❌ Cache Path:</strong> Directory <code>" . htmlspecialchars($cachePath) . "</code> is not writable by user <code>" . htmlspecialchars($owner) . "</code>.</p>\n";
} else {
    echo "<p style=\"color:green;\"><strong>✅ Cache Path:</strong> Directory <code>" . htmlspecialchars($cachePath) . "</code> is writable by user <code>" . htmlspecialchars($owner) . "</code>.</p>\n";
}

// 8) Stop here
if (!$canProceed) {
    exit(1);
}

// 9) Get URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

$basePath = dirname($requestUri);
if ($basePath === DIRECTORY_SEPARATOR) {
    $basePath = '';
}

$url = $protocol . '://' . $host . $basePath;
if (!empty($_GET['url']) && filter_var($_GET['url'], FILTER_VALIDATE_URL)) {
    $url = $_GET['url'];
}

// 10) Build the plugin’s full preload wget command
$preloadCmd  = "wget -O - " . escapeshellarg($url) . " 2>&1";

// 11) Display the exact command for copying/testing
echo "<h2>Preload Command</h2>\n"
   . "<p>Running on your server now:</p>\n"
   . "<pre style=\"background:#f4f4f4;padding:10px;border-radius:4px;white-space:pre-wrap;\">"
   . htmlspecialchars($preloadCmd)
   . "</pre>\n";

// 12) Execute it and capture the output
$cmdOutput = @shell_exec($preloadCmd);

// 13) Show results
echo "<h2>Command Output</h2>\n";
if ($cmdOutput === null) {
    echo "<p style=\"color:red;\"><strong>Error:</strong> shell_exec returned null—command did not run.</p>\n";
} elseif (trim($cmdOutput) === '') {
    echo "<p><strong>Notice:</strong> command ran but produced no output. Check exit code or permissions.</p>\n";
} else {
    echo "<pre style=\"background:#f4f4f4;padding:10px;border-radius:4px;white-space:pre-wrap;\">"
       . htmlspecialchars($cmdOutput)
       . "</pre>\n";
}

// 14) End HTML
echo "</body>\n</html>";
