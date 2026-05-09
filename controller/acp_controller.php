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
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;

class acp_controller
{
	/** @var language */
	protected $language;

	/** @var acp_manager */
	protected $acp_manager;

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
	 * @param acp_manager $acp_manager ACP manager service
	 * @param request     $request Request service
	 * @param template    $template Template service
	 */
	public function __construct(language $language, acp_manager $acp_manager, request $request, template $template)
	{
		$this->language = $language;
		$this->acp_manager = $acp_manager;
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

			$this->acp_manager->log_admin_settings_updated();
			trigger_error($this->language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('reset_consent'))
		{
			$this->validate_form_key('phpbb_consentmanager_acp');

			$this->acp_manager->reset_consent_version();
			$this->acp_manager->log_admin_reprompt();

			trigger_error($this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS') . adm_back_link($this->u_action));
		}

		$this->assign_template_vars();
	}

	/**
	 * Handle the ACP export page request.
	 *
	 * Displays the filter form on GET. On POST with download_csv, streams a
	 * CSV file of consent log records matching the supplied filters.
	 *
	 * @return void
	 */
	public function handle_export()
	{
		add_form_key('phpbb_consentmanager_export');

		if ($this->request->is_set_post('download_csv'))
		{
			$this->validate_form_key('phpbb_consentmanager_export');

			$errors = [];
			$filters = $this->parse_export_filters($errors);

			if (!empty($errors))
			{
				$this->assign_export_template_vars($errors);
				return;
			}

			$this->acp_manager->log_admin_export();
			$this->send_csv_download($filters);
		}

		$this->assign_export_template_vars();
	}

	/**
	 * Parse and validate filter inputs from the export form.
	 *
	 * @param array $errors Reference — validation errors are appended here
	 *
	 * @return array Validated filter map (date_from, date_to, user_id, consent_version)
	 */
	protected function parse_export_filters(array &$errors)
	{
		$date_from_str = trim($this->request->variable('export_date_from', ''));
		$date_to_str   = trim($this->request->variable('export_date_to', ''));
		$user_id       = $this->request->variable('export_user_id', 0);
		$consent_ver   = $this->request->variable('export_consent_version', 0);

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

		if ($user_id > 0)
		{
			$filters['user_id'] = $user_id;
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
		header('Content-Disposition: attachment; filename="consent_logs.csv"');
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');

		$handle = fopen('php://output', 'w');
		fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility
		fputcsv($handle, ['anonymized_id', 'timestamp', 'consent_version', 'categories']);
		$this->acp_manager->stream_logs_csv($handle, $filters);
		fclose($handle);

		exit;
	}

	protected function assign_export_template_vars(array $errors = [])
	{
		$this->template->assign_vars([
			'S_ERROR'            => !empty($errors),
			'ERROR_MSG'          => implode('<br>', $errors),
			'EXPORT_DATE_FROM'   => $this->request->variable('export_date_from', ''),
			'EXPORT_DATE_TO'     => $this->request->variable('export_date_to', ''),
			'EXPORT_USER_ID'     => $this->request->variable('export_user_id', 0),
			'EXPORT_CONSENT_VER' => $this->request->variable('export_consent_version', 0),
			'U_ACTION'           => $this->u_action,
		]);
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

	protected function validate_form_key($form_key)
	{
		if (!check_form_key($form_key))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}
}
