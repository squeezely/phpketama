<?php
declare(strict_types=1);

namespace Ketama;

use Psr\SimpleCache\CacheInterface;

class Ketama
{
    private $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function createContinuum(string $filename): Continuum
    {
        if (null !== $continuum = $this->loadFromCache($filename)) {
            return $continuum;
        }

        $servers = $this->readDefinitions($filename);
        $mtime = filemtime($filename);

        $memory = array_reduce($servers, function ($carry, Serverinfo $server): int {
            return $carry + $server->getMemory();
        }, 0);
        $buckets = [];
        $cont = 0;

        foreach ($servers as $i => $server) {
            $pct = $server->getMemory() / $memory;
            // printf("pct: %s\n", $pct);
            $ks = $pct * 40 * count($servers);
            // printf("ks: %s\n", $ks);
            for ($k = 0; $k < $ks; $k++) {
                $ss = sprintf('%s-%d', $server->getAddr(), $k);
                $digest = hash('md5', $ss, true);
                // printf("ss: %s\n", $ss);
                for ($h = 0; $h < 4; $h++) {
                    list (, $point) = unpack('V', substr($digest, $h*4, 4));
                    // var_dump($point);exit;
                    $buckets[$cont] = new Bucket($point, $server->getAddr());
                    $cont++;
                }
            }
        }

        usort($buckets, function ($a, $b): int {
            $a = $a->getPoint();
            $b = $b->getPoint();
            if ($a < $b) {
                return -1;
            }
            if ($a > $b) {
                return 1;
            }
            return 0;
        });

        $continuum = Continuum::create($buckets, $mtime);
        $this->storeCache($filename, $continuum);

        return $continuum;
    }

    private function readDefinitions(string $filename): array
    {
        $servers = [];

        $lineno = 0;

        $fd = fopen($filename, 'r');
        if (false === $fd) {
            throw new \Exception(sprintf('Failed opening %s', $filename));
        }

        while (!feof($fd)) {
            $lineno++;

            $line = fgets($fd);

            if (false === $line) {
                continue;
            }

            if (strlen($line) < 2 || '#' === $line[0]) {
                continue;
            }

            if (!preg_match('#^([^ \t]+)[ \t]+([0-9]+)#', $line, $m)) {
                throw new \Exception(sprintf(
                    "Failed parsing line %d: '%s'",
                    $lineno,
                    trim($line)
                ));
            }

            $serverinfo = new Serverinfo($m[1], (int) $m[2]);

            if (!$serverinfo->valid()) {
                throw new \Exception(sprintf(
                    "Invalid server definition at line %d: '%s'",
                    $lineno,
                    ttrim($line)
                ));
            }

            $servers[] = $serverinfo;
        }

        if (count($servers) === 0) {
            throw new \Exception(sprintf(
                "No valid server definitions in file %s",
                $filename
            ));
        }

        return $servers;
    }

    private function storeCache(string $filename, Continuum $continuum): void
    {
        $key = 'continuum.' . md5($filename);
        $this->cache->set($key, $continuum->serialize());
    }

    private function loadFromCache(string $filename): ?Continuum
    {
        $key = 'continuum.' . md5($filename);
        $data = $this->cache->get($key);
        if (null === $data) {
            return null;
        }

        $continuum = Continuum::unserialize($data);

        if (filemtime($filename) !== $continuum->getModtime()) {
            return null;
        }

        return $continuum;
    }
}
