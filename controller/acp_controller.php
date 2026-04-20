<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\controller;

use phpbb\consentmanager\service\consent_manager_interface;
use phpbb\consentmanager\service\log_manager;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;

class acp_controller
{
	/** @var language */
	protected $language;

	/** @var consent_manager_interface */
	protected $consent_manager;

	/** @var log_manager */
	protected $log_manager;

	/** @var request */
	protected $request;

	/** @var template */
	protected $template;

	/** @var string */
	protected $u_action = '';

	/**
	 * Constructor.
	 *
	 * @param language                  $language Language service
	 * @param consent_manager_interface $consent_manager Consent manager service
	 * @param log_manager               $log_manager Consent log manager
	 * @param request                   $request Request service
	 * @param template                  $template Template service
	 */
	public function __construct(language $language, consent_manager_interface $consent_manager, log_manager $log_manager, request $request, template $template)
	{
		$this->language = $language;
		$this->consent_manager = $consent_manager;
		$this->log_manager = $log_manager;
		$this->request = $request;
		$this->template = $template;

		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');
	}

	/**
	 * Set the ACP page URL used by form actions and backlinks.
	 *
	 * @param string $u_action ACP page URL
	 *
	 * @return void
	 */
	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	/**
	 * Handle the ACP settings page request.
	 *
	 * @return void
	 */
	public function handle()
	{
		add_form_key('phpbb_consentmanager_acp');

		if ($this->request->is_set_post('submit'))
		{
			$this->validate_form_key();

			$errors = [];
			$saved = $this->consent_manager->save_acp_settings([
				'analytics_enabled' => $this->request->variable('consentmanager_analytics_enabled', 0),
				'marketing_enabled' => $this->request->variable('consentmanager_marketing_enabled', 0),
				'integrations' => trim($this->request->raw_variable('consentmanager_integrations', '')),
			], $errors);

			if (!$saved)
			{
				$this->assign_template_vars($errors);
				return;
			}

			$this->log_manager->log_admin_settings_updated();
			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('reset_consent'))
		{
			$this->validate_form_key();

			$this->consent_manager->reset_consent_version();
			$this->log_manager->log_admin_reprompt();

			trigger_error($this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS') . adm_back_link($this->u_action));
		}

		$this->assign_template_vars();
	}

	/**
	 * Assign consent manager settings to the ACP template.
	 *
	 * @param array $errors Validation errors to display
	 *
	 * @return void
	 */
	protected function assign_template_vars(array $errors = [])
	{
		$this->template->assign_vars(array_merge(
			$this->consent_manager->get_acp_template_data(),
			[
				'S_ERROR'	=> !empty($errors),
				'ERROR_MSG'	=> implode('<br>', $errors),
				'U_ACTION'	=> $this->u_action,
			]
		));
	}

	/**
	 * Ensure the ACP form key is valid before processing changes.
	 *
	 * @return void
	 */
	protected function validate_form_key()
	{
		if (!check_form_key('phpbb_consentmanager_acp'))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}
}
