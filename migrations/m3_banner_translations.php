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

class m3_banner_translations extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'consentmanager_translations');
	}

	public static function depends_on()
	{
		return ['\phpbb\consentmanager\migrations\m2_media_category'];
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'consentmanager_translations' => [
					'COLUMNS' => [
						'translation_id' => ['UINT', null, 'auto_increment'],
						'translation_key' => ['VCHAR:100', ''],
						'lang_iso' => ['VCHAR:30', ''],
						'translation_text' => ['MTEXT_UNI', ''],
						'translation_text_parsed' => ['MTEXT_UNI', ''],
						'translation_uid' => ['VCHAR:8', ''],
						'translation_bitfield' => ['VCHAR:255', ''],
						'translation_options' => ['UINT', 0],
						'updated_at' => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'translation_id',
					'KEYS' => [
						'lookup' => ['UNIQUE', ['translation_key', 'lang_iso']],
						'lang_iso' => ['INDEX', 'lang_iso'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'consentmanager_translations',
			],
		];
	}

	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_CONSENTMANAGER', [
				'module_basename'	=> '\phpbb\consentmanager\acp\consentmanager_module',
				'modes'				=> ['banner'],
			]]],
		];
	}

	public function revert_data()
	{
		return [
			['module.remove', ['acp', 'ACP_CONSENTMANAGER', 'ACP_CONSENTMANAGER_BANNER']],
		];
	}
}
