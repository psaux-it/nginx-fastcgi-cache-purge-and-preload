<?php
// Usage: php php_delete.php <plugin includes/wp-filesystem.php> <cache dir>
// Runs the plugin's REAL nppp_delete_tree()
// with the same top-level loop as nppp_wp_purge() Step 2 (DirectoryIterator + protected temp dirs).
$src = file_get_contents($argv[1]);
if (!preg_match('/^function nppp_delete_tree\(.*?^}\n/ms', $src, $m)) { fwrite(STDERR, "nppp_delete_tree not found\n"); exit(2); }
eval($m[0]);
$dir = $argv[2];
$protected = ['client_temp', 'scgi_temp', 'uwsgi_temp', 'fastcgi_temp', 'proxy_temp'];
$t = hrtime(true); $n = 0; $fail = 0;
foreach (new DirectoryIterator($dir) as $e) {
    if ($e->isDot() || in_array($e->getFilename(), $protected, true)) continue;
    if (!nppp_delete_tree($e->getPathname())) $fail++;
    $n++;
}
printf("%s\n", json_encode(['top_level_entries' => $n, 'failed' => $fail, 'php' => PHP_VERSION, 'seconds' => (hrtime(true) - $t) / 1e9]));
