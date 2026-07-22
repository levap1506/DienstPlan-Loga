<?php
// One-time OPcache flush — delete after use
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache reset OK\n";
} else {
    echo "OPcache not enabled\n";
}
