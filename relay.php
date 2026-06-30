<?php

use plugins\router\Start\cache;
use Swoole\WebSocket\Server;
use Swoole\Runtime;


Swoole\Coroutine::set([
    "max_coroutine" => 20000000,
    "hook_flags" => SWOOLE_HOOK_ALL,
]);
Runtime::enableCoroutine(SWOOLE_HOOK_ALL | SWOOLE_HOOK_NATIVE_CURL);


include 'libspech/plugins/autoloader.php';
include "plugins/autoloader.php";


var_dump(implode(" ", $argv));

if (!empty($argv[1])) {
    $GLOBALS["interface"]["server"]["localPortListener"] = $argv[1];
}
if (!empty($argv[2])) {
    $GLOBALS["interface"]["server"]["remoteAddress"] = $argv[2];
}
if (!empty($argv[3])) {
    if ($argv[3] == "auto-secure") {
        $GLOBALS["interface"]["server"]["autoGenerateSslCertificate"] = true;
    } else {
        $GLOBALS["interface"]["server"]["autoGenerateSslCertificate"] = false;
    }
} else {
    $GLOBALS["interface"]["server"]["autoGenerateSslCertificate"] = false;
}

if ($GLOBALS["interface"]["server"]["autoGenerateSslCertificate"]) {
    $useSsl = true;
}

$newSettings = array_map(fn($value) => $value, cache::global()["interface"]["serverSettings"]);

if (!empty($useSsl)) {
    $server = new Server("0.0.0.0", cache::global()["interface"]["server"]["localPortListener"], SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
} else {
    $server = new Server("0.0.0.0", cache::global()["interface"]["server"]["localPortListener"], SWOOLE_BASE);
}

$server->set($newSettings);
$server->on("Start", '\plugins\router\Start\handler::start');
$server->on("Request", '\plugins\router\Request::callBack');
$server->on("Message", '\plugins\router\Message::onMessage');
$server->on("Open", '\plugins\router\Message::onOpen');
$server->on("Close", '\plugins\router\Message::onClose');
try {
    $server->start();
} catch (Exception $e) {
    print "Oops! Something went wrong. " . $e->getMessage();
}
