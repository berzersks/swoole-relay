<?php

use plugins\router\Start\cache;
use plugins\router\Start\console;
use plugins\terminal;

include 'libspech/plugins/autoloader.php';
include 'plugins/autoloader.php';




if (empty($argv[1]) && empty($argv[2]) && empty($argv[3])) {
    $cache = cache::global()['interface'];
    $script = sprintf("php relay.php %s %s %s",
        $cache['server']['localPortListener'],
        $cache['server']['remoteAddress'],
        $cache['server']['autoGenerateSslCertificate'] ? 'auto-secure' : 'no-auto-secure'
    );
} else $script = sprintf("php relay.php %s %s %s", $argv[1], $argv[2], $argv[3]);


$p = readline("Deseja iniciar o servidor? (s/n): ");


for (;;) {
    Co\run(function () use ($script) {
        \libspech\Coroutine\bash::command($script);
    });
sleep(2);
}
