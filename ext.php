<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager;

class ext extends \phpbb\extension\base
{
	/**
	 * Check whether the extension can be enabled.
	 *
	 * @return bool|array
	 * @access public
	 */
	public function is_enableable()
	{
		$enableable = $this->check_phpbb_version() && $this->check_php_version();

		if (!$enableable && phpbb_version_compare(PHPBB_VERSION, '3.3.0-b1', '>='))
		{
			$language = $this->container->get('language');
			$language->add_lang('install', 'phpbb/consentmanager');
			return $language->lang('CONSENTMANAGER_NOT_ENABLEABLE');
		}

		return $enableable;
	}

	/**
	 * Require phpBB 3.3.0
	 *
	 * @return bool
	 */
	protected function check_phpbb_version()
	{
		return phpbb_version_compare(PHPBB_VERSION, '3.3.0', '>=')
			&& phpbb_version_compare(PHPBB_VERSION, '4.0.0-dev', '<');
	}

	/**
	 * Requires PHP 7.2 due to spl_object_id().
	 *
	 * @return bool
	 */
	protected function check_php_version()
	{
		return PHP_VERSION_ID >= 70200;
	}
}
