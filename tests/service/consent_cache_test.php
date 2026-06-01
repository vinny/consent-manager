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

class consent_cache_test extends consent_manager_test
{
	public function test_invalidate_clears_cached_entries()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);

		$consent_cache->put_integrations('fingerprint', [['id' => 'board.analytics']]);
		$consent_cache->put_translations(['banner_message' => ['en' => ['translation_text' => 'Custom']]]);

		self::assertSame([['id' => 'board.analytics']], $consent_cache->get_integrations('fingerprint'));
		self::assertSame(['banner_message' => ['en' => ['translation_text' => 'Custom']]], $consent_cache->get_translations());

		$consent_cache->invalidate_integrations();
		$consent_cache->invalidate_translations();

		self::assertNull($consent_cache->get_integrations('fingerprint'));
		self::assertNull($consent_cache->get_translations());
	}
}
