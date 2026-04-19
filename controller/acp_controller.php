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

use phpbb\config\config;
use phpbb\config\db_text;
use phpbb\consentmanager\service\consent_manager;
use phpbb\language\language;
use phpbb\log\log;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;

class acp_controller
{
	/** @var config */
	protected $config;

	/** @var db_text */
	protected $config_text;

	/** @var language */
	protected $language;

	/** @var log */
	protected $log;

	/** @var consent_manager */
	protected $consent_manager;

	/** @var request */
	protected $request;

	/** @var template */
	protected $template;

	/** @var user */
	protected $user;

	/** @var string */
	protected $u_action = '';

	public function __construct(
		config $config,
		db_text $config_text,
		language $language,
		log $log,
		consent_manager $consent_manager,
		request $request,
		template $template,
		user $user
	) {
		$this->config = $config;
		$this->config_text = $config_text;
		$this->language = $language;
		$this->log = $log;
		$this->consent_manager = $consent_manager;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;

		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');
	}

	public function set_page_url($u_action)
	{
		$this->u_action = $u_action;
	}

	public function handle()
	{
		add_form_key('phpbb_consentmanager_acp');

		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key('phpbb_consentmanager_acp'))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$errors = $this->save_settings();

			if (!empty($errors))
			{
				$this->assign_template_vars($errors);
				return;
			}

			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_UPDATED');
			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('reset_consent'))
		{
			if (!check_form_key('phpbb_consentmanager_acp'))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$this->config->set('consentmanager_consent_version', (int) $this->config['consentmanager_consent_version'] + 1);
			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_REPROMPT');

			trigger_error($this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS') . adm_back_link($this->u_action));
		}

		$this->assign_template_vars();
	}

	protected function assign_template_vars(array $errors = array())
	{
		$integrations = (string) $this->config_text->get('consentmanager_integrations');

		$this->template->assign_vars(array(
			'S_ERROR'						=> !empty($errors),
			'ERROR_MSG'						=> implode('<br>', $errors),
			'S_CONSENTMANAGER_ANALYTICS'	=> (bool) $this->config['consentmanager_analytics_enabled'],
			'S_CONSENTMANAGER_MARKETING'	=> (bool) $this->config['consentmanager_marketing_enabled'],
			'CONSENTMANAGER_INTEGRATIONS'	=> $integrations !== '' ? $integrations : "[]",
			'CONSENTMANAGER_VERSION'		=> (int) $this->config['consentmanager_consent_version'],
			'U_ACTION'						=> $this->u_action,
		));
	}

	protected function save_settings()
	{
		$errors = array();
		$analytics_enabled = $this->request->variable('consentmanager_analytics_enabled', 0);
		$marketing_enabled = $this->request->variable('consentmanager_marketing_enabled', 0);
		$integrations_input = trim($this->request->raw_variable('consentmanager_integrations', ''));
		$this->consent_manager->normalize_integrations($integrations_input, $errors);

		if (!empty($errors))
		{
			return $errors;
		}

		$stored_integrations = '[]';
		if ($integrations_input !== '')
		{
			$stored_integrations = json_encode(json_decode($integrations_input, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$this->config->set('consentmanager_analytics_enabled', $analytics_enabled);
		$this->config->set('consentmanager_marketing_enabled', $marketing_enabled);
		$this->config_text->set('consentmanager_integrations', $stored_integrations);

		return array();
	}
}
