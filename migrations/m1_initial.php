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
		return array('\phpbb\db\migration\data\v33x\v3312rc1');
	}

	public function update_schema()
	{
		return array(
			'add_tables' => array(
				$this->table_prefix . 'consentmanager_logs' => array(
					'COLUMNS' => array(
						'consent_log_id' => array('UINT', null, 'auto_increment'),
						'anonymized_id' => array('VCHAR:64', ''),
						'consent_version' => array('UINT', 1),
						'accepted_categories' => array('MTEXT_UNI', ''),
						'consent_time' => array('TIMESTAMP', 0),
					),
					'PRIMARY_KEY' => 'consent_log_id',
					'KEYS' => array(
						'consent_time' => array('INDEX', 'consent_time'),
						'anonymized_id' => array('INDEX', 'anonymized_id'),
					),
				),
			),
		);
	}

	public function revert_schema()
	{
		return array(
			'drop_tables' => array(
				$this->table_prefix . 'consentmanager_logs',
			),
		);
	}

	public function update_data()
	{
		return array(
			array('config.add', array('consentmanager_analytics_enabled', 1)),
			array('config.add', array('consentmanager_marketing_enabled', 1)),
			array('config.add', array('consentmanager_consent_version', 1)),
			array('config_text.add', array('consentmanager_integrations', '[]')),
			array('module.add', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_CONSENTMANAGER')),
			array('module.add', array('acp', 'ACP_CONSENTMANAGER', array(
				'module_basename'	=> '\phpbb\consentmanager\acp\consentmanager_module',
				'modes'				=> array('settings'),
			))),
		);
	}

	public function revert_data()
	{
		return array(
			array('config.remove', array('consentmanager_analytics_enabled')),
			array('config.remove', array('consentmanager_marketing_enabled')),
			array('config.remove', array('consentmanager_consent_version')),
			array('config_text.remove', array('consentmanager_integrations')),
			array('module.remove', array('acp', 'ACP_CONSENTMANAGER', 'ACP_CONSENTMANAGER_SETTINGS')),
			array('module.remove', array('acp', 'ACP_CAT_DOT_MODS', 'ACP_CONSENTMANAGER')),
		);
	}
}
