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
class acp_test extends \phpbb_functional_test_case
{
	protected static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	public function test_acp_page_renders_consent_manager_settings()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', $this->get_module_url());

		$this->assertStringContainsString('Consent categories', $crawler->filter('#main')->text());
		$this->assertStringContainsString('ACP-managed integrations', $crawler->filter('#main')->text());
		$this->assertStringContainsString('Current consent version', $crawler->filter('#main')->text());
	}

	public function test_acp_form_saves_settings_and_integrations()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', $this->get_module_url());
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$form['consentmanager_analytics_enabled']->select('0');
		$form['consentmanager_marketing_enabled']->select('1');
		$form['consentmanager_media_enabled']->select('1');
		$form['consentmanager_integrations']->setValue('[{"id":"board.analytics","category":"analytics","label":"Board Analytics","src":"https://cdn.example.com/analytics.js"}]');

		$crawler = self::submit($form);

		$this->assertStringContainsString($this->lang('CONFIG_UPDATED'), $crawler->text());

		$sql = 'SELECT config_name, config_value
			FROM ' . CONFIG_TABLE . '
			WHERE config_name IN (\'consentmanager_analytics_enabled\', \'consentmanager_marketing_enabled\', \'consentmanager_media_enabled\')';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$config = array();
		foreach ($rows as $row)
		{
			$config[$row['config_name']] = $row['config_value'];
		}

		$this->assertSame('0', $config['consentmanager_analytics_enabled']);
		$this->assertSame('1', $config['consentmanager_marketing_enabled']);
		$this->assertSame('1', $config['consentmanager_media_enabled']);

		$sql = 'SELECT config_value
			FROM ' . CONFIG_TEXT_TABLE . '
			WHERE config_name = \'consentmanager_integrations\'';
		$result = $this->db->sql_query($sql);
		$stored_integrations = $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);

		$this->assertSame('[{"id":"board.analytics","category":"analytics","label":"Board Analytics","src":"https://cdn.example.com/analytics.js"}]', $stored_integrations);
	}

	public function test_acp_force_reprompt_increments_version()
	{
		$this->login();
		$this->admin_login();

		$before = $this->get_consent_version();
		$crawler = self::request('GET', $this->get_module_url());
		$form = $crawler->selectButton('Force re-prompt')->form();
		$crawler = self::submit($form);

		$this->assertStringContainsString('Consent version increased. Visitors will be asked to review their settings again.', $crawler->text());
		$this->assertSame($before + 1, $this->get_consent_version());
	}

	protected function get_module_url()
	{
		return 'adm/index.php?i=%5Cphpbb%5Cconsentmanager%5Cacp%5Cconsentmanager_module&mode=settings&sid=' . $this->sid;
	}

	protected function get_consent_version()
	{
		$sql = 'SELECT config_value
			FROM ' . CONFIG_TABLE . '
			WHERE config_name = \'consentmanager_consent_version\'';
		$result = $this->db->sql_query($sql);
		$value = (int) $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);

		return $value;
	}
}
