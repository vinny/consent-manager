<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\migrations;

class m1_initial extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'consentmanager_logs');
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v33x\v335'];
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'consentmanager_logs' => [
					'COLUMNS' => [
						'consent_log_id' => ['UINT', null, 'auto_increment'],
						'anonymized_id' => ['VCHAR:64', ''],
						'consent_version' => ['UINT', 1],
						'accepted_categories' => ['MTEXT_UNI', ''],
						'consent_time' => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'consent_log_id',
					'KEYS' => [
						'consent_time' => ['INDEX', 'consent_time'],
						'anonymized_id' => ['INDEX', 'anonymized_id'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'consentmanager_logs',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['consentmanager_analytics_enabled', 1]],
			['config.add', ['consentmanager_marketing_enabled', 1]],
			['config.add', ['consentmanager_consent_version', 1]],
			['config_text.add', ['consentmanager_integrations', '']],
			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_CONSENTMANAGER']],
			['module.add', ['acp', 'ACP_CONSENTMANAGER', [
				'module_basename'	=> '\phpbb\consentmanager\acp\consentmanager_module',
				'modes'				=> ['settings', 'export'],
			]]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['consentmanager_analytics_enabled']],
			['config.remove', ['consentmanager_marketing_enabled']],
			['config.remove', ['consentmanager_consent_version']],
			['config_text.remove', ['consentmanager_integrations']],
			['module.remove', ['acp', 'ACP_CONSENTMANAGER', 'ACP_CONSENTMANAGER_EXPORT']],
			['module.remove', ['acp', 'ACP_CONSENTMANAGER', 'ACP_CONSENTMANAGER_SETTINGS']],
			['module.remove', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_CONSENTMANAGER']],
		];
	}
}
