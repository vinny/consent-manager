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

class consentmanager_module
{
	public $page_title;
	public $tpl_name;
	public $u_action;

	public function main($id, $mode)
	{
		global $phpbb_container;

		/** @var \phpbb\consentmanager\controller\acp_controller $controller */
		$controller = $phpbb_container->get('phpbb.consentmanager.controller.acp');
		$controller->set_page_url($this->u_action);

		switch ($mode)
		{
			case 'banner':
				$this->tpl_name = 'consentmanager_acp_banner';
				$this->page_title = 'ACP_CONSENTMANAGER_BANNER';
				$controller->handle_consent_text();
			break;

			case 'export':
				$this->tpl_name = 'consentmanager_acp_export';
				$this->page_title = 'ACP_CONSENTMANAGER_EXPORT';
				$controller->handle_logs();
			break;

			default:
				$this->tpl_name = 'consentmanager_acp';
				$this->page_title = 'ACP_CONSENTMANAGER';
				$controller->handle();
			break;
		}
	}
}
