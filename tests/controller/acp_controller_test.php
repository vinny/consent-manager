<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\controller;

require_once __DIR__ . '/../../../../../includes/functions_acp.php';

class acp_controller_test extends \phpbb_test_case
{
	protected const ACP_URL = 'adm.php?i=test';
	protected const EXPORT_URL = 'adm.php?i=test&mode=export';

	/** @var bool */
	public static $valid_form = true;

	/** @var bool */
	public static $confirm_result = false;

	/** @var string */
	public static $confirm_title = '';

	/** @var mixed */
	public static $confirm_hidden_fields;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\template\template|\PHPUnit\Framework\MockObject\MockObject */
	protected $template;

	/** @var \phpbb\consentmanager\service\acp_manager|\PHPUnit\Framework\MockObject\MockObject */
	protected $acp_manager;

	/** @var \phpbb\consentmanager\service\translation_manager|\PHPUnit\Framework\MockObject\MockObject */
	protected $translation_manager;

	protected function setUp(): void
	{
		parent::setUp();
		$_POST = [];

		global $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$lang_loader->set_extension_manager(new \phpbb_mock_extension_manager(
			$phpbb_root_path,
			[
				'phpbb/consentmanager' => [
					'ext_name'   => 'phpbb/consentmanager',
					'ext_active' => '1',
					'ext_path'   => 'ext/phpbb/consentmanager/',
				],
			]
		));
		$this->language = new \phpbb\language\language($lang_loader);
		$this->language->add_lang('common');
		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');

		$this->user = new \phpbb\user($this->language, '\phpbb\datetime');
		$this->user->data = [
			'user_id' => 2,
			'user_form_salt' => 'form-salt',
		];
		$this->user->session_id = 'session-id';

		$this->template        = $this->createMock('\phpbb\template\template');
		$this->acp_manager     = $this->createMock('\phpbb\consentmanager\service\acp_manager');
		$this->translation_manager = $this->createMock('\phpbb\consentmanager\service\translation_manager');
		self::$confirm_result = false;
		self::$confirm_title = '';
		self::$confirm_hidden_fields = null;
	}

	protected function create_controller($request, $u_action = self::ACP_URL)
	{
		global $phpbb_root_path, $phpEx;
		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$this->acp_manager,
			$this->translation_manager,
			$request,
			$this->template,
			$phpbb_root_path,
			$phpEx
		);
		$controller->set_page_url($u_action);
		return $controller;
	}

	public function test_handle_assigns_existing_template_data()
	{
		$this->acp_manager->expects(self::once())
			->method('get_settings_template_data')
			->willReturn([
				'S_CONSENTMANAGER_ANALYTICS' => true,
				'CONSENTMANAGER_INTEGRATIONS_EXAMPLE' => '[{}]',
				'CONSENTMANAGER_VERSION' => 1,
			]);
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'S_CONSENTMANAGER_ANALYTICS' => true,
				'CONSENTMANAGER_INTEGRATIONS_EXAMPLE' => '[{}]',
				'CONSENTMANAGER_VERSION' => 1,
				'S_ERROR' => false,
				'ERROR_MSG' => '',
				'U_ACTION' => self::ACP_URL,
			]);

		$this->create_controller($this->create_request_mock())->handle();
	}

	public function test_handle_submit_validation_errors_reassigns_form_data()
	{
		self::$valid_form = true;

		$this->acp_manager->expects(self::once())
			->method('save_settings')
			->willReturnCallback(function (array $settings, array &$errors) {
				self::assertSame([
					'analytics_enabled' => 1,
					'marketing_enabled' => 0,
					'media_enabled' => 0,
					'integrations' => 'invalid json',
				], $settings);
				$errors = ['Invalid integrations'];
				return false;
			});
		$this->acp_manager->expects(self::once())
			->method('get_settings_template_data')
			->willReturn(['CONSENTMANAGER_VERSION' => 3]);
		$this->acp_manager->expects(self::never())->method('log_admin_action');

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR']
				&& $vars['ERROR_MSG'] === 'Invalid integrations'
				&& $vars['U_ACTION'] === self::ACP_URL
				&& isset($vars['CONSENTMANAGER_VERSION']);
		})];

		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$request = $this->create_request_mock(
			[
				'submit' => 1,
				'consentmanager_analytics_enabled' => 1,
				'consentmanager_marketing_enabled' => 0,
				'consentmanager_media_enabled' => 0
			],
			['consentmanager_integrations' => "  invalid json  \n"]
		);
		$this->create_controller($request)->handle();
	}

	public function test_handle_submit_success_logs_and_triggers_success_notice()
	{
		self::$valid_form = true;

		$this->acp_manager->expects(self::once())->method('save_settings')->willReturn(true);
		$this->acp_manager->expects(self::once())->method('log_admin_action')->with('LOG_CONSENTMANAGER_UPDATED');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('CONFIG_UPDATED'));

		$request = $this->create_request_mock(
			[
				'submit' => 1,
				'consentmanager_analytics_enabled' => 0,
				'consentmanager_marketing_enabled' => 1,
				'consentmanager_media_enabled' => 1
			],
			['consentmanager_integrations' => '[]']
		);
		$this->create_controller($request)->handle();
	}

	public function test_handle_reset_consent_logs_and_triggers_success_notice()
	{
		self::$valid_form = true;

		$this->acp_manager->expects(self::once())->method('reset_consent_version');
		$this->acp_manager->expects(self::once())->method('log_admin_action')->with('LOG_CONSENTMANAGER_REPROMPT');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS'));

		$this->create_controller($this->create_request_mock(['reset_consent' => 1]))->handle();
	}

	public function test_handle_consent_text_assigns_template_data()
	{
		$this->translation_manager->expects(self::once())
			->method('get_banner_template_data')
			->with(null)
			->willReturn([
				'CONSENTMANAGER_BANNER_FIELDS' => [],
				'CONSENTMANAGER_BANNER_LANGUAGES' => [],
			]);
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'CONSENTMANAGER_BANNER_FIELDS' => [],
				'CONSENTMANAGER_BANNER_LANGUAGES' => [],
				'S_ERROR' => false,
				'ERROR_MSG' => '',
				'U_ACTION' => self::ACP_URL,
			]);

		$this->create_controller($this->create_request_mock())->handle_consent_text();
	}

	public function test_handle_consent_text_saves_submitted_translations()
	{
		$translations = [
			'en' => [
				'banner_message' => 'Custom message',
			],
		];

		$this->translation_manager->expects(self::once())
			->method('save_translations')
			->with($translations, ['banner_title', 'banner_message', 'banner_subtext'], self::anything())
			->willReturn(true);
		$this->acp_manager->expects(self::once())
			->method('log_admin_action')
			->with('LOG_CONSENTMANAGER_BANNER_UPDATED');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('ACP_CONSENTMANAGER_BANNER_UPDATED'));

		$this->create_controller($this->create_request_mock(['submit' => 1], ['translations' => $translations]))->handle_consent_text();
	}

	public function test_handle_consent_text_uses_sanitized_multibyte_request_variable()
	{
		$translations = [
			'en' => [
				'banner_message' => 'Custom message 🧑🏽‍💻',
			],
		];
		$expected_default = ['' => ['' => '']];

		$request = $this->getMockBuilder('\phpbb\request\request')
			->disableOriginalConstructor()
			->setMethods(['is_set_post', 'variable', 'raw_variable'])
			->getMock();
		$request->method('is_set_post')
			->willReturnCallback(static function ($name) {
				return $name === 'submit';
			});
		$request->expects(self::once())
			->method('variable')
			->with('translations', $expected_default, true, \phpbb\request\request_interface::POST)
			->willReturn(array_merge($expected_default, $translations));
		$request->expects(self::never())
			->method('raw_variable');

		$this->translation_manager->expects(self::once())
			->method('save_translations')
			->with($translations, ['banner_title', 'banner_message', 'banner_subtext'], self::anything())
			->willReturn(true);
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('ACP_CONSENTMANAGER_BANNER_UPDATED'));

		$this->create_controller($request)->handle_consent_text();
	}

	public function test_handle_consent_text_validation_errors_reassigns_submitted_translations()
	{
		self::$valid_form = true;

		$translations = [
			'en' => [
				'banner_message' => 'Broken [quote]message',
			],
		];

		$this->translation_manager->expects(self::once())
			->method('save_translations')
			->willReturnCallback(function (array $submitted, array $allowed_keys, array &$errors) use ($translations) {
				self::assertSame($translations, $submitted);
				self::assertSame(['banner_title', 'banner_message', 'banner_subtext'], $allowed_keys);
				$errors = ['Invalid banner text'];
				return false;
			});
		$this->translation_manager->expects(self::once())
			->method('get_banner_template_data')
			->with($translations)
			->willReturn([
				'CONSENTMANAGER_BANNER_FIELDS' => [],
				'CONSENTMANAGER_BANNER_LANGUAGES' => [],
			]);
		$this->acp_manager->expects(self::never())->method('log_admin_action');

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR'] === true
				&& $vars['ERROR_MSG'] === 'Invalid banner text'
				&& $vars['U_ACTION'] === self::ACP_URL;
		})];
		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$this->create_controller($this->create_request_mock(['submit' => 1], ['translations' => $translations]))->handle_consent_text();
	}

	public function test_handle_consent_text_invalid_form_key_stops_before_processing()
	{
		self::$valid_form = false;

		$this->translation_manager->expects(self::never())->method('save_translations');
		$this->translation_manager->expects(self::never())->method('get_banner_template_data');
		$this->acp_manager->expects(self::never())->method('log_admin_action');
		$this->template->expects(self::never())->method('assign_vars');
		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$this->create_controller($this->create_request_mock(['submit' => 1]))->handle_consent_text();
	}

	/**
	 * @dataProvider handle_invalid_form_key_data
	 */
	public function test_handle_invalid_form_key($action, $manager_method, $log_action = null)
	{
		self::$valid_form = false;

		$this->acp_manager->expects(self::never())->method($manager_method);

		if ($log_action !== null)
		{
			$this->acp_manager->expects(self::never())->method('log_admin_action')->with($log_action);
		}

		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$this->create_controller($this->create_request_mock([$action => 1]))->handle();
	}

	public function handle_invalid_form_key_data()
	{
		return [
			'submit' => ['submit', 'save_settings'],
			'reset consent' => ['reset_consent', 'reset_consent_version', 'LOG_CONSENTMANAGER_REPROMPT'],
		];
	}

	public function test_handle_logs_export_shows_empty_form()
	{
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'S_ERROR'            => false,
				'ERROR_MSG'          => '',
				'EXPORT_DATE_FROM'   => '',
				'EXPORT_DATE_TO'     => '',
				'EXPORT_USERNAME'    => '',
				'EXPORT_CONSENT_VER' => 0,
				'U_FIND_USERNAME'    => 'u_find_username',
				'U_ACTION'           => self::EXPORT_URL,
			]);

		$this->create_controller($this->create_request_mock(), self::EXPORT_URL)->handle_logs();
	}

	/**
	 * @dataProvider handle_logs_invalid_form_key_data
	 */
	public function test_handle_logs_invalid_form_key_before_processing(array $request_values, $confirm_result)
	{
		self::$valid_form = false;
		self::$confirm_result = $confirm_result;

		$this->acp_manager->expects(self::never())->method('stream_logs_csv');
		$this->acp_manager->expects(self::never())->method('delete_logs');
		$this->acp_manager->expects(self::never())->method('log_admin_action');
		$this->acp_manager->expects(self::never())->method('get_user_id_by_username');
		$this->acp_manager->expects(self::never())->method('parse_date_filter');
		$this->template->expects(self::never())->method('assign_vars');
		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$this->create_controller($this->create_request_mock($request_values), self::EXPORT_URL)->handle_logs();
	}

	public function handle_logs_invalid_form_key_data()
	{
		return [
			'download csv' => [
				['download_csv' => 1],
				false,
			],
			'delete logs before confirmation' => [
				[
					'delete_logs' => 1,
					'export_date_from' => '2024-01-01',
					'export_date_to' => '2024-12-31',
					'export_username' => 'Alice',
					'export_consent_version' => 2,
				],
				false,
			],
			'delete logs after confirmation' => [
				[
					'delete_logs' => 1,
					'export_date_from' => '2024-01-01',
					'export_date_to' => '2024-12-31',
					'export_username' => 'Alice',
					'export_consent_version' => 2,
					'creation_time' => 1234567890,
					'form_token' => 'form-token-value',
				],
				true,
			],
		];
	}

	/**
	 * @dataProvider handle_logs_delete_confirmation_data
	 */
	public function test_handle_logs_delete_requests_confirmation_with_current_filters(array $request_overrides, array $expected_token_fields)
	{
		self::$valid_form = true;
		self::$confirm_result = false;

		$this->acp_manager->expects(self::once())->method('get_user_id_by_username')->with('Alice')->willReturn(42);
		$this->acp_manager->method('parse_date_filter')
			->willReturnOnConsecutiveCalls(1704067200, 1735689599);
		$this->acp_manager->expects(self::never())->method('delete_logs');
		$this->acp_manager->expects(self::never())->method('log_admin_action');
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'S_ERROR'            => false,
				'ERROR_MSG'          => '',
				'EXPORT_DATE_FROM'   => '2024-01-01',
				'EXPORT_DATE_TO'     => '2024-12-31',
				'EXPORT_USERNAME'    => 'Alice',
				'EXPORT_CONSENT_VER' => 2,
				'U_FIND_USERNAME'    => 'u_find_username',
				'U_ACTION'           => self::EXPORT_URL,
			]);

		$request = $this->create_request_mock(array_merge([
			'delete_logs' => 1,
			'export_date_from' => '2024-01-01',
			'export_date_to' => '2024-12-31',
			'export_username' => 'Alice',
			'export_consent_version' => 2,
		], $request_overrides));
		$this->create_controller($request, self::EXPORT_URL)->handle_logs();

		self::assertSame($this->language->lang('ACP_CONSENTMANAGER_DELETE_CONFIRM'), self::$confirm_title);
		self::assertSame(array_merge([
			'mode' => 'export',
			'delete_logs' => 1,
			'export_date_from' => '2024-01-01',
			'export_date_to' => '2024-12-31',
			'export_username' => 'Alice',
			'export_consent_version' => 2,
		], $expected_token_fields), self::$confirm_hidden_fields);
	}

	public function handle_logs_delete_confirmation_data()
	{
		return [
			'with current csrf tokens' => [
				[
					'creation_time' => 1234567890,
					'form_token' => 'form-token-value',
				],
				[
					'creation_time' => 1234567890,
					'form_token' => 'form-token-value',
				],
			],
			'without current csrf tokens' => [
				[],
				[],
			],
		];
	}

	public function test_handle_logs_delete_cancel_returns_to_form_without_invalid_form_error()
	{
		self::$valid_form = true;
		self::$confirm_result = false;
		$_POST = [
			'cancel' => 1,
			'confirm_key' => 'existing-confirm-key',
		];

		$this->acp_manager->expects(self::never())->method('delete_logs');
		$this->acp_manager->expects(self::never())->method('log_admin_action');
		$this->acp_manager->expects(self::once())->method('get_user_id_by_username')->with('Alice')->willReturn(42);
		$this->acp_manager->expects(self::exactly(2))
			->method('parse_date_filter')
			->willReturnOnConsecutiveCalls(1704067200, 1735689599);
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'S_ERROR'            => false,
				'ERROR_MSG'          => '',
				'EXPORT_DATE_FROM'   => '2024-01-01',
				'EXPORT_DATE_TO'     => '2024-12-31',
				'EXPORT_USERNAME'    => 'Alice',
				'EXPORT_CONSENT_VER' => 2,
				'U_FIND_USERNAME'    => 'u_find_username',
				'U_ACTION'           => self::EXPORT_URL,
			]);

		$reopened_request = $this->create_request_mock([
			'delete_logs' => 1,
			'confirm_key' => 'existing-confirm-key',
			'export_date_from' => '2024-01-01',
			'export_date_to' => '2024-12-31',
			'export_username' => 'Alice',
			'export_consent_version' => 2,
			'creation_time' => 1234567890,
			'form_token' => 'form-token-value',
		]);
		$this->create_controller($reopened_request, self::EXPORT_URL)->handle_logs();

		self::assertSame('', self::$confirm_title);
		self::assertNull(self::$confirm_hidden_fields);
	}

	/**
	 * @dataProvider handle_logs_invalid_filter_data
	 */
	public function test_handle_logs_invalid_filters_show_error($action, array $request_values, array $parse_results, $error_substring)
	{
		self::$valid_form = true;

		$this->acp_manager->expects(self::never())->method('stream_logs_csv');
		$this->acp_manager->expects(self::never())->method('delete_logs');
		$this->acp_manager->expects(self::never())->method('log_admin_action');

		if (count($parse_results) === 1)
		{
			$this->acp_manager->method('parse_date_filter')->willReturn($parse_results[0]);
		}
		else
		{
			$this->acp_manager->method('parse_date_filter')
				->willReturnOnConsecutiveCalls(...$parse_results);
		}

		$error_substring = $this->language->lang($error_substring);
		$args = [self::callback(static function ($vars) use ($error_substring) {
			return $vars['S_ERROR'] === true && strpos($vars['ERROR_MSG'], $error_substring) !== false;
		})];
		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$request_values[$action] = 1;
		$this->create_controller($this->create_request_mock($request_values), self::EXPORT_URL)->handle_logs();
	}

	public function handle_logs_invalid_filter_data()
	{
		$cases = [
			'invalid date from' => [
				[
					'export_date_from' => 'not-a-date',
					'export_date_to' => '',
					'export_username' => '',
					'export_consent_version' => 0,
				],
				[false],
				'ACP_CONSENTMANAGER_EXPORT_DATE_FROM',
			],
			'invalid date to' => [
				[
					'export_date_from' => '',
					'export_date_to' => '2024-13-01',
					'export_username' => '',
					'export_consent_version' => 0,
				],
				[false],
				'ACP_CONSENTMANAGER_EXPORT_DATE_TO',
			],
			'reversed date range' => [
				[
					'export_date_from' => '2024-12-31',
					'export_date_to' => '2024-01-01',
					'export_username' => '',
					'export_consent_version' => 0,
				],
				[1735603200, 1704067200],
				'ACP_CONSENTMANAGER_EXPORT_DATE_FROM',
			],
		];

		$data = [];

		foreach (['download_csv', 'delete_logs'] as $action)
		{
			foreach ($cases as $name => $case)
			{
				$data[$action . ' ' . $name] = array_merge([$action], $case);
			}
		}

		return $data;
	}

	public function test_handle_logs_export_success_logs_and_passes_filters_to_download()
	{
		self::$valid_form = true;

		$this->acp_manager->expects(self::once())->method('get_user_id_by_username')->with('Alice')->willReturn(42);
		$this->acp_manager->method('parse_date_filter')
			->willReturnOnConsecutiveCalls(1704067200, 1735689599);
		$this->acp_manager->expects(self::once())->method('log_admin_action')->with('LOG_CONSENTMANAGER_EXPORT');

		$request = $this->create_request_mock([
			'download_csv' => 1,
			'export_date_from' => '2024-01-01',
			'export_date_to' => '2024-12-31',
			'export_username' => 'Alice',
			'export_consent_version' => 2,
		]);

		global $phpbb_root_path, $phpEx;
		$controller = new \phpbb\consentmanager\tests\controller\testable_acp_controller(
			$this->language,
			$this->acp_manager,
			$this->translation_manager,
			$request,
			$this->template,
			$phpbb_root_path,
			$phpEx
		);
		$controller->set_page_url(self::EXPORT_URL);
		$controller->handle_logs();

		self::assertSame([
			'date_from'       => 1704067200,
			'date_to'         => 1735689599,
			'user_id'         => 42,
			'consent_version' => 2,
		], $controller->captured_filters);
	}

	public function test_handle_logs_delete_confirmed_logs_and_triggers_success_notice()
	{
		self::$valid_form = true;
		self::$confirm_result = true;

		$this->acp_manager->expects(self::once())->method('get_user_id_by_username')->with('Alice')->willReturn(42);
		$this->acp_manager->method('parse_date_filter')
			->willReturnOnConsecutiveCalls(1704067200, 1735689599);
		$this->acp_manager->expects(self::once())
			->method('delete_logs')
			->with([
				'date_from'       => 1704067200,
				'date_to'         => 1735689599,
				'user_id'         => 42,
				'consent_version' => 2,
			])
			->willReturn(5);
		$this->acp_manager->expects(self::once())->method('log_admin_action')->with('LOG_CONSENTMANAGER_DELETE');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('ACP_CONSENTMANAGER_DELETE_SUCCESS'));

		$request = $this->create_request_mock([
			'delete_logs' => 1,
			'export_date_from' => '2024-01-01',
			'export_date_to' => '2024-12-31',
			'export_username' => 'Alice',
			'export_consent_version' => 2,
			'creation_time' => 1234567890,
			'form_token' => 'form-token-value',
		]);
		$this->create_controller($request, self::EXPORT_URL)->handle_logs();
	}

	/**
	 * @dataProvider handle_logs_unknown_username_data
	 */
	public function test_handle_logs_unknown_username_shows_error($action)
	{
		self::$valid_form = true;

		$this->acp_manager->expects(self::once())->method('get_user_id_by_username')->with('MissingUser')->willReturn(false);
		$this->acp_manager->expects(self::never())->method('stream_logs_csv');
		$this->acp_manager->expects(self::never())->method('delete_logs');
		$this->acp_manager->expects(self::never())->method('log_admin_action');
		$this->acp_manager->method('parse_date_filter')
			->willReturn(false);

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR'] === true
				&& strpos($vars['ERROR_MSG'], 'MissingUser') !== false
				&& $vars['EXPORT_USERNAME'] === 'MissingUser'
				&& $vars['U_FIND_USERNAME'] === 'u_find_username';
		})];
		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$this->create_controller($this->create_request_mock([
			$action => 1,
			'export_username' => 'MissingUser',
		]), self::EXPORT_URL)->handle_logs();
	}

	public function handle_logs_unknown_username_data()
	{
		return [
			'download csv' => ['download_csv'],
			'delete logs' => ['delete_logs'],
		];
	}

	protected function create_request_mock(array $values = [], array $raw_values = [])
	{
		$request = $this->getMockBuilder('\phpbb\request\request')
			->disableOriginalConstructor()
			->setMethods(['is_set_post', 'variable', 'raw_variable'])
			->getMock();

		$request->method('is_set_post')
			->willReturnCallback(function ($name) use ($values, $raw_values) {
				return array_key_exists($name, $values) || array_key_exists($name, $raw_values);
			});

		$request->method('variable')
			->willReturnCallback(function ($name, $default) use ($values, $raw_values) {
				if (array_key_exists($name, $values))
				{
					return $values[$name];
				}

				if (array_key_exists($name, $raw_values))
				{
					return $raw_values[$name];
				}

				return $default;
			});

		$request->method('raw_variable')
			->willReturnCallback(function ($name, $default, $super_global = null) use ($values, $raw_values) {
				if (array_key_exists($name, $raw_values))
				{
					return $raw_values[$name];
				}

				if (array_key_exists($name, $values))
				{
					return $values[$name];
				}

				return $default;
			});

		return $request;
	}
}

namespace phpbb\consentmanager\tests\controller;

class testable_acp_controller extends \phpbb\consentmanager\controller\acp_controller
{
	/** @var array|null Filters captured from the last send_csv_download call */
	public $captured_filters;

	protected function send_csv_download(array $filters)
	{
		$this->captured_filters = $filters;
		// Do not stream or exit — just record the filters for assertions
	}
}

namespace phpbb\consentmanager\controller;

function add_form_key()
{
}

function check_form_key()
{
	return \phpbb\consentmanager\tests\controller\acp_controller_test::$valid_form;
}

function confirm_box($check, $title = '', $hidden = null)
{
	if ($check)
	{
		if (isset($_POST['cancel']))
		{
			return false;
		}

		return \phpbb\consentmanager\tests\controller\acp_controller_test::$confirm_result;
	}

	if (!empty($_POST['confirm_key']))
	{
		return false;
	}

	\phpbb\consentmanager\tests\controller\acp_controller_test::$confirm_title = $title;
	\phpbb\consentmanager\tests\controller\acp_controller_test::$confirm_hidden_fields = $hidden;
	return false;
}

function build_hidden_fields($fields)
{
	return $fields;
}

function adm_back_link()
{
	return '';
}

function append_sid()
{
	return 'u_find_username';
}
