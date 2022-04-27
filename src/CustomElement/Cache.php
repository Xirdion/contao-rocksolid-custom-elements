<?php

namespace MadeYourDay\RockSolidCustomElements\CustomElement;

class Cache
{
    /**
     * Refreshes all active opcode caches for the specified file
     *
     * @param string $path Path to the file
     * @return bool True on success, false on failure
     */
    public static function refreshOpcodeCache(string $path): bool
    {
        try {
            // Zend OPcache
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }

            // Zend Optimizer+
            if (function_exists('accelerator_reset')) {
                accelerator_reset();
            }

            // APC
            if (function_exists('apc_compile_file') && !ini_get('apc.stat')) {
                apc_compile_file($path);
            }

            // eAccelerator
            if (function_exists('eaccelerator_purge') && !ini_get('eaccelerator.check_mtime')) {
                @eaccelerator_purge();
            }

            // XCache
            if (function_exists('xcache_count') && !ini_get('xcache.stat')) {
                if (($count = xcache_count(XC_TYPE_PHP)) > 0) {
                    for ($id = 0; $id < $count; $id++) {
                        xcache_clear_cache(XC_TYPE_PHP, $id);
                    }
                }
            }

            // WinCache
            if (function_exists('wincache_refresh_if_changed')) {
                wincache_refresh_if_changed(array($path));
            }

        } catch(\Exception $exception) {
            return false;
        }

        return true;
    }
}