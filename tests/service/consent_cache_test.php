<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\service;

class consent_cache_test extends \phpbb_test_case
{
	public function test_invalidate_clears_cached_entries()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);

		$consent_cache->put_integrations('fingerprint', [['id' => 'board.analytics']]);
		$consent_cache->put_asset_source('asset-key', '/assets/app.js');

		self::assertSame([['id' => 'board.analytics']], $consent_cache->get_integrations('fingerprint'));
		self::assertSame('/assets/app.js', $consent_cache->get_asset_source('asset-key'));

		$consent_cache->invalidate();

		self::assertNull($consent_cache->get_integrations('fingerprint'));
		self::assertNull($consent_cache->get_asset_source('asset-key'));
	}

	public function test_put_asset_source_prunes_old_entries()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);

		for ($i = 0; $i <= \phpbb\consentmanager\service\consent_cache::MAX_ASSET_SOURCES; $i++)
		{
			$consent_cache->put_asset_source('asset-' . $i, '/assets/' . $i . '.js');
		}

		self::assertNull($consent_cache->get_asset_source('asset-0'));
		self::assertSame('/assets/' . \phpbb\consentmanager\service\consent_cache::MAX_ASSET_SOURCES . '.js', $consent_cache->get_asset_source('asset-' . \phpbb\consentmanager\service\consent_cache::MAX_ASSET_SOURCES));
	}

	public function test_put_asset_source_replaces_existing_entry()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);

		$consent_cache->put_asset_source('asset-key', '/assets/first.js');
		$consent_cache->put_asset_source('asset-key', '/assets/second.js');

		self::assertSame('/assets/second.js', $consent_cache->get_asset_source('asset-key'));
	}

	protected function get_consent_cache(array &$cache_store = [])
	{
		return new \phpbb\consentmanager\service\consent_cache($this->get_cache_service($cache_store));
	}

	protected function get_cache_service(array &$cache_store = [])
	{
		$cache = $this->getMockBuilder('\phpbb\cache\service')
			->disableOriginalConstructor()
			->setMethods(['get', 'put', 'destroy'])
			->getMock();

		$cache->method('get')
			->willReturnCallback(function ($key) use (&$cache_store) {
				return array_key_exists($key, $cache_store) ? $cache_store[$key] : false;
			});
		$cache->method('put')
			->willReturnCallback(function ($key, $value) use (&$cache_store) {
				$cache_store[$key] = $value;
				return true;
			});
		$cache->method('destroy')
			->willReturnCallback(function ($key) use (&$cache_store) {
				unset($cache_store[$key]);
				return true;
			});

		return $cache;
	}
}
