<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\functional;

/**
 * @group functional
 */
class frontend_test extends functional_base
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->add_lang_ext('phpbb/consentmanager', 'common');

		$this->reset_consent_manager_state();
	}

	public function test_frontend_markup_is_injected_on_board_pages()
	{
		$crawler = self::request('GET', 'index.php');
		$content = self::get_content();
		$payload = $this->extract_payload($content);

		$this->assertStringContainsString('consent-manager-root', $content);
		$this->assertContainsLang('CONSENTMANAGER_SETTINGS_TITLE', $crawler->filter('#consent-manager-link')->text());
		$this->assertSame(2, $crawler->filter('.consent-manager-policy-link')->count());
		$this->assertSame(1, $payload['version']);
		$this->assertSame('phpbb_consent_manager', $payload['storageKey']);
		$this->assertSame($this->lang('CONSENTMANAGER_MEDIA_PLACEHOLDER'), $this->extract_media_placeholder_label($content));
		$this->assertSame(array('necessary'), $payload['requiredCategories']);
		$this->assertContains('analytics', $payload['optionalCategories']);
		$this->assertContains('media', $payload['optionalCategories']);
		$this->assertStringContainsString('/consent/log', $payload['logEndpoint']);
	}

	public function test_log_endpoint_rejects_invalid_json_payload()
	{
		$payload = $this->fetch_frontend_payload();

		self::$client->request(
			'POST',
			$payload['logEndpoint'],
			array(),
			array(),
			array('CONTENT_TYPE' => 'application/json'),
			'{invalid'
		);

		$this->assertSame(400, self::$client->getResponse()->getStatus());
		$this->assertSame(array(
			'success' => false,
			'error' => 'invalid_payload',
		), json_decode(self::$client->getResponse()->getContent(), true));
	}

	public function test_log_endpoint_persists_valid_anonymous_submission()
	{
		$payload = $this->fetch_frontend_payload();
		$response = $this->post_log_request($payload, array('analytics', 'analytics', 'unknown'));

		$this->assertSame(200, self::$client->getResponse()->getStatus());
		$this->assertSame(array('necessary', 'analytics'), $response['categories']);
		$this->assertSame($payload['version'], $response['version']);

		$sql = 'SELECT COUNT(*) AS log_count
			FROM phpbb_consentmanager_logs';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$this->assertSame(1, (int) $row['log_count']);
	}

	public function test_log_endpoint_persists_valid_authenticated_submission()
	{
		$this->create_user('consentuser');
		$this->login('consentuser');

		$payload = $this->fetch_frontend_payload();
		$response = $this->post_log_request($payload, array('analytics', 'analytics', 'unknown'));

		$this->assertSame(200, self::$client->getResponse()->getStatus());
		$this->assertSame(array('necessary', 'analytics'), $response['categories']);
		$this->assertSame($payload['version'], $response['version']);

		$sql = 'SELECT consent_version, accepted_categories
			FROM phpbb_consentmanager_logs
			ORDER BY consent_log_id DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$this->assertSame((int) $payload['version'], (int) $row['consent_version']);
		$this->assertSame('["necessary","analytics"]', $row['accepted_categories']);
	}

	public function test_log_endpoint_rejects_stale_version()
	{
		$payload = $this->fetch_frontend_payload();
		$response = $this->post_log_request($payload, array('analytics'), $payload['version'] + 1);

		$this->assertSame(409, self::$client->getResponse()->getStatus());
		$this->assertSame('version_mismatch', $response['error']);
	}

	protected function fetch_frontend_payload()
	{
		return $this->extract_payload(self::request('GET', 'index.php') ? self::get_content() : '');
	}

	protected function post_log_request(array $payload, array $categories, $version = null)
	{
		self::$client->request(
			'POST',
			$payload['logEndpoint'],
			array(),
			array(),
			array('CONTENT_TYPE' => 'application/json'),
			json_encode(array(
				'hash' => $payload['logHash'],
				'version' => $version ?? $payload['version'],
				'categories' => $categories,
			))
		);

		return json_decode(self::$client->getResponse()->getContent(), true);
	}

	protected function extract_payload($content)
	{
		preg_match('/(?:var|let|const) payload = (.*?);\s*(?:var|let|const) requiredCategories/s', $content, $matches);
		$this->assertNotEmpty($matches[1]);

		return json_decode($matches[1], true);
	}

	protected function extract_media_placeholder_label($content)
	{
		preg_match("/mediaPlaceholderLabel:\\s+'((?:\\\\.|[^'])*)'/", $content, $matches);
		$this->assertNotEmpty($matches[1]);

		return json_decode('"' . $matches[1] . '"');
	}

	protected function reset_consent_manager_state()
	{
		$this->db->sql_query('UPDATE ' . CONFIG_TABLE . "
    		SET config_value = '1'
    		WHERE " . $this->db->sql_in_set('config_name', array(
				'consentmanager_analytics_enabled',
				'consentmanager_marketing_enabled',
				'consentmanager_media_enabled',
				'consentmanager_consent_version',
			))
		);
		$this->db->sql_query('UPDATE ' . CONFIG_TEXT_TABLE . "
			SET config_value = ''
			WHERE config_name = 'consentmanager_integrations'");
		$this->db->sql_query('DELETE FROM phpbb_consentmanager_logs');

		$this->purge_cache();
	}
}
