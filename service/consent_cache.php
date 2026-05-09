<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\service;

use phpbb\cache\service as cache_service;

class consent_cache
{
	public const INTEGRATIONS_CACHE_KEY = '_phpbb_consentmanager_integrations';
	public const ASSET_URLS_CACHE_KEY = '_phpbb_consentmanager_asset_urls';
	public const MAX_ASSET_SOURCES = 128;

	/** @var cache_service */
	protected $cache;

	/**
	 * Constructor.
	 *
	 * @param cache_service $cache Cache service
	 */
	public function __construct(cache_service $cache)
	{
		$this->cache = $cache;
	}

	/**
	 * Return cached normalized integrations for a fingerprint.
	 *
	 * @param string $fingerprint Cache fingerprint
	 *
	 * @return array|null
	 */
	public function get_integrations($fingerprint)
	{
		$cached = $this->cache->get(self::INTEGRATIONS_CACHE_KEY);
		if (!is_array($cached)
			|| !isset($cached['fingerprint'], $cached['integrations'])
			|| $cached['fingerprint'] !== $fingerprint
			|| !is_array($cached['integrations']))
		{
			return null;
		}

		return $cached['integrations'];
	}

	/**
	 * Cache normalized integrations for a fingerprint.
	 *
	 * @param string $fingerprint  Cache fingerprint
	 * @param array  $integrations Normalized integrations
	 *
	 * @return void
	 */
	public function put_integrations($fingerprint, array $integrations)
	{
		$this->cache->put(self::INTEGRATIONS_CACHE_KEY, [
			'fingerprint' => $fingerprint,
			'integrations' => $integrations,
		]);
	}

	/**
	 * Return a cached resolved asset source, or null when absent.
	 *
	 * @param string $cache_key Asset cache key
	 *
	 * @return string|null
	 */
	public function get_asset_source($cache_key)
	{
		$cached_urls = $this->cache->get(self::ASSET_URLS_CACHE_KEY);
		if (!is_array($cached_urls) || !array_key_exists($cache_key, $cached_urls))
		{
			return null;
		}

		return (string) $cached_urls[$cache_key];
	}

	/**
	 * Cache a resolved asset source and return it.
	 *
	 * @param string $cache_key Asset cache key
	 * @param string $src       Resolved asset source
	 *
	 * @return string
	 */
	public function put_asset_source($cache_key, $src)
	{
		$cached_urls = $this->cache->get(self::ASSET_URLS_CACHE_KEY);
		if (!is_array($cached_urls))
		{
			$cached_urls = [];
		}

		if (array_key_exists($cache_key, $cached_urls))
		{
			unset($cached_urls[$cache_key]);
		}

		$cached_urls[$cache_key] = $src;
		if (count($cached_urls) > self::MAX_ASSET_SOURCES)
		{
			$cached_urls = array_slice($cached_urls, -self::MAX_ASSET_SOURCES, null, true);
		}

		$this->cache->put(self::ASSET_URLS_CACHE_KEY, $cached_urls);

		return $src;
	}

	/**
	 * Invalidate persistent Consent Manager cache entries.
	 *
	 * @return void
	 */
	public function invalidate()
	{
		$this->cache->destroy(self::INTEGRATIONS_CACHE_KEY);
		$this->cache->destroy(self::ASSET_URLS_CACHE_KEY);
	}
}
