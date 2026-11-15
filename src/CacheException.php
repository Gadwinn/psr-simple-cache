<?php

declare(strict_types=1);

namespace G\SimpleCache;

use Psr\SimpleCache\CacheException as CacheExceptionInterface;
use Exception;

class CacheException extends Exception implements CacheExceptionInterface
{
}
