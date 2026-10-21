<?php
// Stand-in for WordPress: every /p/<n> answers 200, is cacheable, and has a deterministic,
// log-normally distributed body size (real page HTML is skewed: many small pages, a long tail
// of big ones). SIZE_MEDIAN_KB comes from the nginx fastcgi_param of the active profile.
header('Cache-Control: public, max-age=86400');
$uri = $_SERVER['REQUEST_URI'];
$i   = (int) substr(strrchr($uri, '/'), 1);
$med = max(1, (int) ($_SERVER['SIZE_MEDIAN_KB'] ?? 24)) * 1024;
mt_srand($i + 1);
$u1 = (mt_rand(1, 2147483646)) / 2147483647;
$u2 = (mt_rand(1, 2147483646)) / 2147483647;
$z  = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
$size = (int) min(1500000, max(1500, round($med * exp(0.9 * $z))));
$head = '<!doctype html><title>page ' . $i . '</title><body>';
echo $head . str_repeat(chr(97 + $i % 26) . 'lorem ipsum ', (int) ceil(($size - strlen($head)) / 12));
