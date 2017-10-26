<?php

use Ketama\Ketama;
use Symfony\Component\Cache\Simple\ApcuCache;

require __DIR__ . "/../vendor/autoload.php";

define('ITERATIONS', 200);
define('HASHES', 500);

benchmark('ketama');
benchmark('phpketama');

function ketama() {
    for ($x = 0; $x < ITERATIONS; $x++) {
        $continuum = ketama_roll(__DIR__ . '/servers');
        for ($i = 0; $i < HASHES; $i++) {
            ketama_get_server($i, $continuum);
        }
        unset($continuum);
    }
}

function phpketama() {
    $cache = new ApcuCache(strtr(Ketama::class, '\\', '_'));
    for ($x = 0; $x < ITERATIONS; $x++) {
        $continuum = (new Ketama($cache))->createContinuum(__DIR__ . '/servers');
        for ($i = 0; $i < HASHES; $i++) {
            $continuum->getServer((string)$i);
        }
    }
}

function benchmark($function) {
    $start = microtime(true);
    $function();
    $time = microtime(true) - $start;
    printf("%-20s %.02fms\n", $function, $time);

}
