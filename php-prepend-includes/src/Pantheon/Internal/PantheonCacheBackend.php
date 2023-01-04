<?php

namespace Pantheon\Internal;

use \Redis;

class PantheonCacheBackend
{
    public function fetch_new_cache()
    {
        return new Redis();
    }

    public function initialize_cache_connection($cache)
    {
        $success = FALSE;
        try {
            // documentation says connect returns true on success, false on failure.
            // testing shows this throws a RedisExcption in some situations
            if ($cache->connect($_ENV['CACHE_HOST'], $_ENV['CACHE_PORT']) && $cache->auth($_ENV['CACHE_PASSWORD'])) {
                $success = TRUE;
            }
        } catch (\RedisException $e) {
            return $success;
        }

        return $success;
    }

    public function verify_cache_connection_active($cache)
    {
        // This can throw an exception if the connection is unavailable or is unauthenticated
        try {
            return $cache->ping();
        } catch (\RedisException $e) {
            return FALSE;
        }
    }

    public function update_pantheon_heartbeat($cache)
    {
        $heartbeat_key = 'pantheon_heartbeat';
        $cache->set($heartbeat_key, time());
    }

    public function resurrect_cache()
    {
        $success = FALSE;
        $apc_key = 'skip_cache_resurrection_check';
        if (!empty($_ENV['CACHE_HOST'])) {
            if (!apc_exists($apc_key)) {
                $cache = $this->fetch_new_cache();
                if ($this->initialize_cache_connection($cache) && $this->verify_cache_connection_active($cache)) {
                    $this->update_pantheon_heartbeat($cache);
                } else {
                    curl_resurrector("GET", "cacheserver", "redis", "start");
                }
                apc_store($apc_key, TRUE, 10);
            }
            $success = TRUE;
        }
        return $success;
    }
}