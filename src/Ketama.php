<?php
declare(strict_types=1);

namespace Ketama;

class Ketama
{
    private $cache;
    /**
     * @var null|callable
     */
    private $getCacheKeyCallable = null;
    /**
     * @var mixed
     */
    private int $cacheTtl;

    public function __construct($cache, array $options = [])
    {
        $this->cacheTtl = $options['ttl'] ?? 3600;
        $this->cache = $cache;
    }

    public function createContinuum(string $filename): Continuum
    {
        $modificationTime = filemtime($filename);
        if (null !== $continuum = $this->loadFromCache($filename, $modificationTime)) {
            return $continuum;
        }

        $servers = $this->readDefinitions($filename);
        $mtime = filemtime($filename);

        return $this->createContinuumFromArray($servers, $filename, $mtime);
    }

    public function createContinuumFromArray(array $servers, string $uniqueCacheKey, int $modificationTime)
    {
        if (null !== $continuum = $this->loadFromCache($uniqueCacheKey, $modificationTime)) {
            return $continuum;
        }

        $memory = array_reduce($servers, function ($carry, Serverinfo $server): int {
            return $carry + $server->getMemory();
        }, 0);
        $buckets = [];
        $cont = 0;

        foreach ($servers as $i => $server) {
            $pct = $server->getMemory() / $memory;
            $ks = floor($pct * 40 * count($servers));

            for ($k = 0; $k < $ks; $k++) {
                $ss = sprintf('%s-%d', $server->getAddr(), $k);
                $digest = hash('md5', $ss, true);

                for ($h = 0; $h < 4; $h++) {
                    [, $point] = unpack('V', substr($digest, $h*4, 4));
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

        $continuum = Continuum::create($buckets, $modificationTime);
        $this->storeCache($uniqueCacheKey, $continuum);

        return $continuum;
    }

    private function readDefinitions(string $filename): array
    {
        $servers = [];

        $lineno = 0;

        $fd = fopen($filename, 'r');
        if (false === $fd) {
            throw new KetamaException(sprintf('Failed opening %s', $filename));
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
                throw new KetamaException(sprintf(
                    "Failed parsing line %d: '%s'",
                    $lineno,
                    trim($line)
                ));
            }

            $serverinfo = new Serverinfo($m[1], (int) $m[2]);

            if (!$serverinfo->valid()) {
                throw new KetamaException(sprintf(
                    "Invalid server definition at line %d: '%s'",
                    $lineno,
                    trim($line)
                ));
            }

            $servers[] = $serverinfo;
        }

        if (count($servers) === 0) {
            throw new KetamaException(sprintf(
                "No valid server definitions in file %s",
                $filename
            ));
        }

        return $servers;
    }

    private function storeCache(string $filename, Continuum $continuum): void
    {
        $cacheKey = $this->getCacheKey('continuum.' . md5($filename));
        $this->cache->set($cacheKey, $continuum->serialize(), $this->cacheTtl);
    }

    private function loadFromCache(string $uniqueCacheKey, int $modificationTime = 0): ?Continuum
    {
        $cacheKey = $this->getCacheKey('continuum.' . md5($uniqueCacheKey));
        $data = $this->cache->get($cacheKey);
        if (null === $data || $data === false) {
            return null;
        }

        $continuum = Continuum::unserialize($data);

        if ($modificationTime !== $continuum->getModtime()) {
            return null;
        }

        return $continuum;
    }

    public function setCacheKeyClosure(callable $func)
    {
        $this->getCacheKeyCallable = $func;
    }

    private function getCacheKey(string $key)
    {
        if(!$this->getCacheKeyCallable) return $key;

        return call_user_func($this->getCacheKeyCallable, $key);
    }
}
