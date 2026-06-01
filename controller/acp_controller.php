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

use phpbb\consentmanager\service\acp_manager;
use phpbb\consentmanager\service\translation_manager;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\request\request_interface;
use phpbb\template\template;

class acp_controller
{
	/** @var language */
	protected $language;

	/** @var acp_manager */
	protected $acp_manager;

	/** @var translation_manager */
	protected $translation_manager;

	/** @var request */
	protected $request;

	/** @var template */
	protected $template;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var string */
	protected $u_action = '';

	/**
	 * Constructor.
	 *
	 * @param language            $language Language service
	 * @param acp_manager         $acp_manager ACP manager service
	 * @param translation_manager $translation_manager Translation manager service
	 * @param request             $request Request service
	 * @param template            $template Template service
	 * @param string              $root_path phpBB root path
	 * @param string              $php_ext PHP file extension
	 */
	public function __construct(language $language, acp_manager $acp_manager, translation_manager $translation_manager, request $request, template $template, $root_path, $php_ext)
	{
		$this->language = $language;
		$this->acp_manager = $acp_manager;
		$this->translation_manager = $translation_manager;
		$this->request = $request;
		$this->template = $template;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;

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
			$this->validate_form_key('phpbb_consentmanager_acp');

			$errors = [];
			$saved = $this->acp_manager->save_settings([
				'analytics_enabled' => $this->request->variable('consentmanager_analytics_enabled', 0),
				'marketing_enabled' => $this->request->variable('consentmanager_marketing_enabled', 0),
				'media_enabled' => $this->request->variable('consentmanager_media_enabled', 0),
				'integrations' => trim($this->request->raw_variable('consentmanager_integrations', '')),
			], $errors);

			if (!$saved)
			{
				$this->assign_template_vars($errors);
				return;
			}

			$this->acp_manager->log_admin_action('LOG_CONSENTMANAGER_UPDATED');
			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('reset_consent'))
		{
			$this->validate_form_key('phpbb_consentmanager_acp');

			$this->acp_manager->reset_consent_version();
			$this->acp_manager->log_admin_action('LOG_CONSENTMANAGER_REPROMPT');

			trigger_error($this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS') . adm_back_link($this->u_action));
		}

		$this->assign_template_vars();
	}

	/**
	 * Handle the ACP consent text page request.
	 *
	 * @return void
	 */
	public function handle_consent_text()
	{
		add_form_key('phpbb_consentmanager_banner');

		if ($this->request->is_set_post('submit'))
		{
			$this->validate_form_key('phpbb_consentmanager_banner');

			$translations = $this->request->variable('translations', ['' => ['' => '']], true, request_interface::POST);
			unset($translations['']);
			$errors = [];
			$saved = $this->translation_manager->save_translations(
				is_array($translations) ? $translations : [],
				array_keys(translation_manager::BANNER_FIELDS),
				$errors
			);

			if (!$saved)
			{
				$this->assign_banner_template_vars($errors, is_array($translations) ? $translations : []);
				return;
			}

			$this->acp_manager->log_admin_action('LOG_CONSENTMANAGER_BANNER_UPDATED');
			trigger_error($this->language->lang('ACP_CONSENTMANAGER_BANNER_UPDATED') . adm_back_link($this->u_action));
		}

		$this->assign_banner_template_vars();
	}

	/**
	 * Handle the ACP consent logs page request.
	 *
	 * @return void
	 */
	public function handle_logs()
	{
		add_form_key('phpbb_consentmanager_logs');
		$form_data = $this->get_logs_form_data();

		if ($this->request->is_set_post('download_csv'))
		{
			$this->validate_form_key('phpbb_consentmanager_logs');

			$errors = [];
			$filters = $this->parse_export_filters($form_data, $errors);

			if (!empty($errors))
			{
				$this->assign_export_template_vars($form_data, $errors);
				return;
			}

			$this->acp_manager->log_admin_action('LOG_CONSENTMANAGER_EXPORT');
			$this->send_csv_download($filters);
		}
		else if ($this->request->is_set_post('delete_logs'))
		{
			$this->validate_form_key('phpbb_consentmanager_logs');

			$errors = [];
			$filters = $this->parse_export_filters($form_data, $errors);

			if (!empty($errors))
			{
				$this->assign_export_template_vars($form_data, $errors);
				return;
			}

			if (confirm_box(true))
			{
				$this->acp_manager->delete_logs($filters);
				$this->acp_manager->log_admin_action('LOG_CONSENTMANAGER_DELETE');

				trigger_error($this->language->lang('ACP_CONSENTMANAGER_DELETE_SUCCESS') . adm_back_link($this->u_action));
			}
			else
			{
				confirm_box(
					false,
					$this->language->lang('ACP_CONSENTMANAGER_DELETE_CONFIRM'),
					build_hidden_fields(array_merge([
						'mode'                   => 'export',
						'delete_logs'            => 1,
					], $form_data, $this->get_current_form_token_fields()))
				);
			}
		}

		$this->assign_export_template_vars($form_data);
	}

	/**
	 * Parse and validate filter inputs from the export form.
	 *
	 * @param array $form_data Array of form field values
	 * @param array $errors Reference — validation errors are appended here
	 *
	 * @return array Validated filter map (date_from, date_to, user_id, consent_version)
	 */
	protected function parse_export_filters(array $form_data, array &$errors)
	{
		$date_from_str = $form_data['export_date_from'];
		$date_to_str   = $form_data['export_date_to'];
		$username      = $form_data['export_username'];
		$consent_ver   = $form_data['export_consent_version'];

		$filters   = [];
		$date_from = $this->acp_manager->parse_date_filter($date_from_str);
		$date_to   = $this->acp_manager->parse_date_filter($date_to_str, true);

		if ($date_from_str !== '' && $date_from === false)
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_EXPORT_INVALID_DATE_FROM');
		}

		if ($date_to_str !== '' && $date_to === false)
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_EXPORT_INVALID_DATE_TO');
		}

		if ($date_from !== false && $date_to !== false && $date_from > $date_to)
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_EXPORT_DATE_RANGE_INVALID');
		}

		if (empty($errors))
		{
			if ($date_from !== false)
			{
				$filters['date_from'] = $date_from;
			}

			if ($date_to !== false)
			{
				$filters['date_to'] = $date_to;
			}
		}

		if ($username !== '')
		{
			$user_id = $this->acp_manager->get_user_id_by_username($username);

			if ($user_id === false)
			{
				$errors[] = $this->language->lang('ACP_CONSENTMANAGER_EXPORT_INVALID_USERNAME', $username);
			}
			else
			{
				$filters['user_id'] = $user_id;
			}
		}

		if ($consent_ver > 0)
		{
			$filters['consent_version'] = $consent_ver;
		}

		return $filters;
	}

	protected function send_csv_download(array $filters)
	{
		if (ob_get_level())
		{
			ob_end_clean();
		}

		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="consent_logs_' . gmdate('Y-m-d_His') . '.csv"');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');

		$handle = fopen('php://output', 'wb');
		fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility
		fputcsv($handle, ['anonymized_id', 'timestamp', 'consent_version', 'categories']);
		$this->acp_manager->stream_logs_csv($handle, $filters);
		fclose($handle);

		exit;
	}

	protected function get_logs_form_data()
	{
		return [
			'export_date_from'       => trim($this->request->variable('export_date_from', '')),
			'export_date_to'         => trim($this->request->variable('export_date_to', '')),
			'export_username'        => trim($this->request->variable('export_username', '')),
			'export_consent_version' => $this->request->variable('export_consent_version', 0),
		];
	}

	protected function get_current_form_token_fields()
	{
		if (!$this->request->is_set_post('creation_time') || !$this->request->is_set_post('form_token'))
		{
			return [];
		}

		return [
			'creation_time' => $this->request->variable('creation_time', 0),
			'form_token'    => $this->request->variable('form_token', ''),
		];
	}

	protected function assign_template_vars(array $errors = [])
	{
		$this->template->assign_vars(array_merge(
			$this->acp_manager->get_settings_template_data(),
			[
				'S_ERROR'	=> !empty($errors),
				'ERROR_MSG'	=> implode('<br>', $errors),
				'U_ACTION'	=> $this->u_action,
			]
		));
	}

	protected function assign_export_template_vars(array $form_data, array $errors = [])
	{
		$this->template->assign_vars([
			'S_ERROR'            => !empty($errors),
			'ERROR_MSG'          => implode('<br>', $errors),
			'EXPORT_DATE_FROM'   => $form_data['export_date_from'],
			'EXPORT_DATE_TO'     => $form_data['export_date_to'],
			'EXPORT_USERNAME'    => $form_data['export_username'],
			'EXPORT_CONSENT_VER' => $form_data['export_consent_version'],
			'U_FIND_USERNAME'    => $this->get_find_username_url(),
			'U_ACTION'           => $this->u_action,
		]);
	}

	protected function assign_banner_template_vars(array $errors = [], array $submitted_translations = null)
	{
		$this->template->assign_vars(array_merge(
			$this->translation_manager->get_banner_template_data($submitted_translations),
			[
				'S_ERROR'	=> !empty($errors),
				'ERROR_MSG'	=> implode('<br>', $errors),
				'U_ACTION'	=> $this->u_action,
			]
		));
	}

	protected function get_find_username_url()
	{
		return append_sid(
			"{$this->root_path}memberlist.$this->php_ext",
			'mode=searchuser&amp;form=acp_consentmanager_export&amp;field=export_username&amp;select_single=true'
		);
	}

	protected function validate_form_key($form_key)
	{
		if (!check_form_key($form_key))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}
}
