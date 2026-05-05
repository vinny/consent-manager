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
class frontend_test extends \phpbb_functional_test_case
{
	protected static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	protected function setUp(): void
	{
		parent::setUp();

		$this->reset_consent_manager_state();
	}

	public function test_frontend_markup_is_injected_on_board_pages()
	{
		$crawler = self::request('GET', 'index.php');
		$content = self::get_content();
		$payload = $this->extract_payload($content);

		$this->assertStringContainsString('consent-manager-root', $content);
		$this->assertStringContainsString('Privacy settings', $crawler->filter('#consent-manager-link')->text());
		$this->assertSame(1, $payload['version']);
		$this->assertSame('phpbb_consent_manager', $payload['storageKey']);
		$this->assertSame(array('necessary'), $payload['requiredCategories']);
		$this->assertContains('analytics', $payload['optionalCategories']);
		$this->assertStringContainsString('app.php/consent/log', $payload['logEndpoint']);
	}

	public function test_log_endpoint_rejects_invalid_json_payload()
	{
		$payload = $this->extract_payload(self::request('GET', 'index.php') ? self::get_content() : '');

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

	public function test_log_endpoint_accepts_valid_anonymous_submission_without_persisting_it()
	{
		$payload = $this->extract_payload(self::request('GET', 'index.php') ? self::get_content() : '');

		self::$client->request(
			'POST',
			$payload['logEndpoint'],
			array(),
			array(),
			array('CONTENT_TYPE' => 'application/json'),
			json_encode(array(
				'hash' => $payload['logHash'],
				'version' => $payload['version'],
				'categories' => array('analytics', 'analytics', 'unknown'),
			))
		);

		$response = json_decode(self::$client->getResponse()->getContent(), true);
		$this->assertSame(200, self::$client->getResponse()->getStatus());
		$this->assertSame(array('necessary', 'analytics'), $response['categories']);
		$this->assertSame($payload['version'], $response['version']);

		$sql = 'SELECT COUNT(*) AS log_count
			FROM phpbb_consentmanager_logs';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$this->assertSame(0, (int) $row['log_count']);
	}

	public function test_log_endpoint_persists_valid_authenticated_submission()
	{
		$this->create_user('consentuser');
		$this->login('consentuser');

		$payload = $this->extract_payload(self::request('GET', 'index.php') ? self::get_content() : '');

		self::$client->request(
			'POST',
			$payload['logEndpoint'],
			array(),
			array(),
			array('CONTENT_TYPE' => 'application/json'),
			json_encode(array(
				'hash' => $payload['logHash'],
				'version' => $payload['version'],
				'categories' => array('analytics', 'analytics', 'unknown'),
			))
		);

		$response = json_decode(self::$client->getResponse()->getContent(), true);
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
		$payload = $this->extract_payload(self::request('GET', 'index.php') ? self::get_content() : '');

		self::$client->request(
			'POST',
			$payload['logEndpoint'],
			array(),
			array(),
			array('CONTENT_TYPE' => 'application/json'),
			json_encode(array(
				'hash' => $payload['logHash'],
				'version' => $payload['version'] + 1,
				'categories' => array('analytics'),
			))
		);

		$response = json_decode(self::$client->getResponse()->getContent(), true);
		$this->assertSame(409, self::$client->getResponse()->getStatus());
		$this->assertSame('version_mismatch', $response['error']);
	}

	protected function extract_payload($content)
	{
		preg_match('/(?:var|let|const) payload = (.*?);\s*(?:var|let|const) requiredCategories/s', $content, $matches);
		$this->assertNotEmpty($matches[1]);

		return json_decode($matches[1], true);
	}

	protected function reset_consent_manager_state()
	{
		$this->db->sql_query('UPDATE ' . CONFIG_TABLE . "
    		SET config_value = '1'
    		WHERE " . $this->db->sql_in_set('config_name', array(
				'consentmanager_analytics_enabled',
				'consentmanager_marketing_enabled',
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
