# PHP Ketama

This is a Pure-PHP implementation of libketama, a consistent hashing library.

## Compatibility with libketama

phpketama uses the same algorithm as libketama, and will always return the
same results as libketama.

The API is not compatible.

## Speed

Loading a cached continuum files is faster in phpketama. Hashing is slower. As
per the included benchmark, this makes phpketama faster than libketama when
doing up to 200 hashes per instance.

## Usage

``` php
<?php

use Ketama\Ketama;
use Symfony\Component\Cache\Simple\ApcuCache;

// Cache used to store the parsed continuum file
$cache = new ApcuCache('mynamespace');

$ketama = new Ketama($cache);
$continuum = $ketama->createContinuum('/some/file');

// Lookup server
$ip = $continuum->getServer("some key");
```

Continuum file:

```
# server    weight
server1 1
server2 3
server3 1
server4 2
```
