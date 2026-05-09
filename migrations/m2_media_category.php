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

class m2_media_category extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['consentmanager_media_enabled']);
	}

	public static function depends_on()
	{
		return ['\phpbb\consentmanager\migrations\m1_initial'];
	}

	public function update_data()
	{
		return [
			['config.add', ['consentmanager_media_enabled', 1]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['consentmanager_media_enabled']],
		];
	}
}
