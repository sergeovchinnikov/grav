<?php
namespace Grav\Common;

use \Doctrine\Common\Cache\Cache as DoctrineCache;
use Grav\Common\Config\Config;
use Grav\Common\Filesystem\Folder;

/**
 * The GravCache object is used throughout Grav to store and retrieve cached data.
 * It uses DoctrineCache library and supports a variety of caching mechanisms. Those include:
 *
 * APC
 * XCache
 * RedisCache
 * MemCache
 * MemCacheD
 * FileSystem
 *
 * @author RocketTheme
 * @license MIT
 */
class Cache extends Getters
{
    use GravTrait;

    /**
     * @var string Cache key.
     */
    protected $key;

    protected $lifetime;
    protected $now;

    protected $config;

    /**
     * @var DoctrineCache
     */
    protected $driver;

    protected $driver_name;

    /**
     * @var bool
     */
    protected $enabled;

    protected $cache_dir;

    protected static $standard_remove = [
        'cache://twig/',
        'cache://doctrine/',
        'cache://compiled/',
        'cache://validated-',
        'cache://images',
        'asset://',
    ];

    protected static $all_remove = [
        'cache://',
        'cache://images',
        'asset://'
    ];

    protected static $assets_remove = [
        'asset://'
    ];

    protected static $images_remove = [
        'cache://images'
    ];

    protected static $cache_remove = [
        'cache://'
    ];

    /**
     * Constructor
     *
     * @params Grav $grav
     */
    public function __construct(Grav $grav)
    {
        $this->init($grav);
    }

    /**
     * Initialization that sets a base key and the driver based on configuration settings
     *
     * @param  Grav $grav
     * @return void
     */
    public function init(Grav $grav)
    {
        /** @var Config $config */
        $this->config = $grav['config'];
        $this->now = time();

        $this->cache_dir = $grav['locator']->findResource('cache://doctrine', true, true);

        /** @var Uri $uri */
        $uri = $grav['uri'];

        $prefix = $this->config->get('system.cache.prefix');

        $this->enabled = (bool) $this->config->get('system.cache.enabled');

        // Cache key allows us to invalidate all cache on configuration changes.
        $this->key = ($prefix ? $prefix : 'g') . '-' . substr(md5($uri->rootUrl(true) . $this->config->key() . GRAV_VERSION), 2, 8);

        $this->driver = $this->getCacheDriver();

        // Set the cache namespace to our unique key
        $this->driver->setNamespace($this->key);

        // Dump Cache state
        $grav['debugger']->addMessage('Cache: [' . ($this->enabled ? 'true' : 'false') . '] Driver: [' . $this->driver_name . ']');

    }

    /**
     * Automatically picks the cache mechanism to use.  If you pick one manually it will use that
     * If there is no config option for $driver in the config, or it's set to 'auto', it will
     * pick the best option based on which cache extensions are installed.
     *
     * @return DoctrineCacheDriver  The cache driver to use
     */
    public function getCacheDriver()
    {
        $setting = $this->config->get('system.cache.driver');
        $driver_name = 'file';

        if (!$setting || $setting == 'auto') {
            if (extension_loaded('apc')) {
                $driver_name = 'apc';
            } elseif (extension_loaded('wincache')) {
                $driver_name = 'wincache';
            } elseif (extension_loaded('xcache')) {
                $driver_name = 'xcache';
            }
        } else {
            $driver_name = $setting;
        }

        $this->driver_name = $driver_name;

        switch ($driver_name) {
            case 'apc':
                $driver = new \Doctrine\Common\Cache\ApcCache();
                break;

            case 'wincache':
                $driver = new \Doctrine\Common\Cache\WinCacheCache();
                break;

            case 'xcache':
                $driver = new \Doctrine\Common\Cache\XcacheCache();
                break;

            case 'memcache':
                $memcache = new \Memcache();
                $memcache->connect($this->config->get('system.cache.memcache.server','localhost'),
                                   $this->config->get('system.cache.memcache.port', 11211));
                $driver = new \Doctrine\Common\Cache\MemcacheCache();
                $driver->setMemcache($memcache);
                break;

            case 'redis':
                $redis = new \Redis();
                $redis->connect($this->config->get('system.cache.redis.server','localhost'),
                                $this->config->get('system.cache.redis.port', 6379));

                $driver = new \Doctrine\Common\Cache\RedisCache();
                $driver->setRedis($redis);
                break;

            default:
                $driver = new \Doctrine\Common\Cache\FilesystemCache($this->cache_dir);
                break;
        }

        return $driver;
    }

    /**
     * Gets a cached entry if it exists based on an id. If it does not exist, it returns false
     *
     * @param  string $id the id of the cached entry
     * @return object     returns the cached entry, can be any type, or false if doesn't exist
     */
    public function fetch($id)
    {
        if ($this->enabled) {
            return $this->driver->fetch($id);
        } else {
            return false;
        }
    }

    /**
     * Stores a new cached entry.
     *
     * @param  string $id       the id of the cached entry
     * @param  array|object $data     the data for the cached entry to store
     * @param  int $lifetime    the lifetime to store the entry in seconds
     */
    public function save($id, $data, $lifetime = null)
    {
        if ($this->enabled) {
            if ($lifetime === null) {
                $lifetime = $this->getLifetime();
            }
            $this->driver->save($id, $data, $lifetime);
        }
    }

    /**
     * Getter method to get the cache key
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Helper method to clear all Grav caches
     *
     * @param string $remove    standard|all|assets-only|images-only|cache-only
     *
     * @return array
     */
    public static function clearCache($remove = 'standard')
    {
        $locator = self::getGrav()['locator'];
        $output = [];
        $user_config = USER_DIR . 'config/system.yaml';

        switch($remove) {
            case 'all':
                $remove_paths = self::$all_remove;
                break;
            case 'assets-only':
                $remove_paths = self::$assets_remove;
                break;
            case 'images-only':
                $remove_paths = self::$images_remove;
                break;
            case 'cache-only':
                $remove_paths = self::$cache_remove;
                break;
            default:
                $remove_paths = self::$standard_remove;
        }


        foreach ($remove_paths as $stream) {

            // Convert stream to a real path
            $path = $locator->findResource($stream, true, true);
            // Make sure path exists before proceeding, otherwise we would wipe ROOT_DIR
            if (!$path)
                throw new \RuntimeException("Stream '{$stream}' not found", 500);

            $anything = false;
            $files = glob($path . '/*');

            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        if (@unlink($file)) {
                            $anything = true;
                        }
                    } elseif (is_dir($file)) {
                        if (@Folder::delete($file)) {
                            $anything = true;
                        }
                    }
                }
            }

            if ($anything) {
                $output[] = '<red>Cleared:  </red>' . $path . '/*';
            }
        }

        $output[] = '';

        if (($remove == 'all' || $remove == 'standard') && file_exists($user_config)) {
            touch($user_config);

            $output[] = '<red>Touched: </red>' . $user_config;
            $output[] = '';
        }

        return $output;
    }


    /**
     * Set the cache lifetime programmatically
     *
     * @param int $future timestamp
     */
    public function setLifetime($future)
    {
        if (!$future) {
            return;
        }

        $interval = $future - $this->now;
        if ($interval > 0 && $interval < $this->getLifetime()) {
            $this->lifetime = $interval;
        }
    }


    /**
     * Retrieve the cache lifetime (in seconds)
     *
     * @return mixed
     */
    public function getLifetime()
    {
        if ($this->lifetime === null) {
            $this->lifetime = $this->config->get('system.cache.lifetime') ?: 604800; // 1 week default
        }

        return $this->lifetime;
    }
}
