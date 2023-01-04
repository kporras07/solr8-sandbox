<?php

namespace Pantheon\Internal;

use Drupal\Core\Cache\MemoryBackend;

/**
 * The PantheonCacheListener class is installed by the PantheonServiceProvider
 * class. It is tagged with 'cache.bin' so that it will be added to the list
 * of cache backends maintained by Drupal. It implements MemoryBackend so that it
 * can operate as a real cache backend in all ways; however, its only purpose
 * is to override the 'deleteAll()' method, which is called during cache clear
 * operations (and not at other times).
 */
class PantheonCacheListener extends MemoryBackend
{
    public function __construct() {
        // In Drupal 8.2.x and earlier,
        // MemoryBackend::__construct took a
        // single parameter, '$bin', which remained unused.
        // Passing an extra parameter to a constructor that
        // has none has no ill effect.
        // However, if the constructor is completely missing
        // (as is the case in Drupal 8.3.x and later), then
        // attempting to call parent::construt causes a fatal error.
        if (is_callable('parent::__construct')) {
            parent::__construct(NULL);
        }
    }

    public function deleteAll()
    {
        // pantheon_api_cache_flush() is defined in pantheon.php
        if (function_exists('pantheon_api_cache_flush')) {
            pantheon_api_cache_flush();
        }
        parent::deleteAll();
    }
}
