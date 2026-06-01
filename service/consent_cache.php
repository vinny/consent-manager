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
	public const TRANSLATIONS_CACHE_KEY = '_phpbb_consentmanager_translations';

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
	 * Invalidate persistent Consent Manager integration cache entries.
	 *
	 * @return void
	 */
	public function invalidate_integrations()
	{
		$this->cache->destroy(self::INTEGRATIONS_CACHE_KEY);
	}

	/**
	 * Return cached custom translations.
	 *
	 * @return array|null
	 */
	public function get_translations()
	{
		$cached = $this->cache->get(self::TRANSLATIONS_CACHE_KEY);

		return is_array($cached) ? $cached : null;
	}

	/**
	 * Cache custom translations.
	 *
	 * @param array $translations Custom translations indexed by key and language
	 *
	 * @return void
	 */
	public function put_translations(array $translations)
	{
		$this->cache->put(self::TRANSLATIONS_CACHE_KEY, $translations);
	}

	/**
	 * Invalidate persistent Consent Manager translation cache entries.
	 *
	 * @return void
	 */
	public function invalidate_translations()
	{
		$this->cache->destroy(self::TRANSLATIONS_CACHE_KEY);
	}
}
