<?php
require __DIR__ . '/../lib.php';
$t = microtime(true);
[$name, $st] = regen_index('big', 21, false, false);
printf("regen_index(200+1 threads, 1 thread of 5000 posts): %s %s  peak=%.2f MB  %.0f ms\n", $name, $st, memory_get_peak_usage() / 1048576, (microtime(true) - $t) * 1000);
$t = microtime(true);
[$name, $st] = regen_index('big', 41, false, false);
printf("regen_index page 2: %s %s  peak=%.2f MB  %.0f ms\n", $name, $st, memory_get_peak_usage() / 1048576, (microtime(true) - $t) * 1000);
