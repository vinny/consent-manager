<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\acp;

class consentmanager_info
{
	public function module()
	{
		return [
			'filename'	=> '\phpbb\consentmanager\acp\consentmanager_module',
			'title'		=> 'ACP_CONSENTMANAGER',
			'modes'		=> [
				'settings'	=> [
					'title' => 'ACP_CONSENTMANAGER_SETTINGS',
					'auth' => 'ext_phpbb/consentmanager && acl_a_board',
					'cat' => ['ACP_CONSENTMANAGER'],
				],
				'banner'	=> [
					'title' => 'ACP_CONSENTMANAGER_BANNER',
					'auth' => 'ext_phpbb/consentmanager && acl_a_board',
					'cat' => ['ACP_CONSENTMANAGER'],
				],
				'export'	=> [
					'title' => 'ACP_CONSENTMANAGER_EXPORT',
					'auth' => 'ext_phpbb/consentmanager && acl_a_board',
					'cat' => ['ACP_CONSENTMANAGER'],
				],
			],
		];
	}
}
