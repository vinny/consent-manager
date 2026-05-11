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
	/** @var bool */
	public static $valid_form = true;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\template\template|\PHPUnit\Framework\MockObject\MockObject */
	protected $template;

	/** @var \phpbb\consentmanager\service\acp_manager|\PHPUnit\Framework\MockObject\MockObject */
	protected $acp_manager;

	protected function setUp(): void
	{
		parent::setUp();

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
		$this->user->lang = $this->language->get_lang_array();

		$this->template        = $this->createMock('\phpbb\template\template');
		$this->acp_manager     = $this->createMock('\phpbb\consentmanager\service\acp_manager');
	}

	protected function create_controller($request, $u_action = 'adm.php?i=test')
	{
		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$this->acp_manager,
			$request,
			$this->template
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
				'CONSENTMANAGER_VERSION' => 1,
			]);
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'S_CONSENTMANAGER_ANALYTICS' => true,
				'CONSENTMANAGER_VERSION' => 1,
				'S_ERROR' => false,
				'ERROR_MSG' => '',
				'U_ACTION' => 'adm.php?i=test',
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
		$this->acp_manager->expects(self::never())->method('log_admin_settings_updated');

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR']
				&& $vars['ERROR_MSG'] === 'Invalid integrations'
				&& $vars['U_ACTION'] === 'adm.php?i=test'
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
		$this->acp_manager->expects(self::once())->method('log_admin_settings_updated');
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
		$this->acp_manager->expects(self::once())->method('log_admin_reprompt');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS'));

		$this->create_controller($this->create_request_mock(['reset_consent' => 1]))->handle();
	}

	public function test_handle_rejects_invalid_form_key()
	{
		self::$valid_form = false;

		$this->acp_manager->expects(self::never())->method('save_settings');
		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$this->create_controller($this->create_request_mock(['submit' => 1]))->handle();
	}

	public function test_handle_reset_consent_rejects_invalid_form_key()
	{
		self::$valid_form = false;

		$this->acp_manager->expects(self::never())->method('reset_consent_version');
		$this->acp_manager->expects(self::never())->method('log_admin_reprompt');
		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$this->create_controller($this->create_request_mock(['reset_consent' => 1]))->handle();
	}

	public function test_handle_export_shows_empty_form()
	{
		$this->template->expects(self::once())
			->method('assign_vars')
			->with([
				'S_ERROR'            => false,
				'ERROR_MSG'          => '',
				'EXPORT_DATE_FROM'   => '',
				'EXPORT_DATE_TO'     => '',
				'EXPORT_USER_ID'     => 0,
				'EXPORT_CONSENT_VER' => 0,
				'U_ACTION'           => 'adm.php?i=test&mode=export',
			]);

		$this->create_controller($this->create_request_mock(), 'adm.php?i=test&mode=export')->handle_export();
	}

	public function test_handle_export_rejects_invalid_form_key()
	{
		self::$valid_form = false;

		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$this->create_controller($this->create_request_mock(['download_csv' => 1]), 'adm.php?i=test&mode=export')->handle_export();
	}

	public function test_handle_export_invalid_date_from_shows_error()
	{
		self::$valid_form = true;

		$this->acp_manager->method('parse_date_filter')->willReturn(false);
		$this->acp_manager->expects(self::never())->method('stream_logs_csv');
		$this->acp_manager->expects(self::never())->method('log_admin_export');

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR'] === true && strpos($vars['ERROR_MSG'], 'Date from') !== false;
		})];
		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$request = $this->create_request_mock([
			'download_csv' => 1,
			'export_date_from' => 'not-a-date',
			'export_date_to' => '',
			'export_user_id' => 0,
			'export_consent_version' => 0,
		]);
		$this->create_controller($request, 'adm.php?i=test&mode=export')->handle_export();
	}

	public function test_handle_export_invalid_date_to_shows_error()
	{
		self::$valid_form = true;

		$this->acp_manager->method('parse_date_filter')->willReturn(false);
		$this->acp_manager->expects(self::never())->method('stream_logs_csv');
		$this->acp_manager->expects(self::never())->method('log_admin_export');

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR'] === true && strpos($vars['ERROR_MSG'], 'Date to') !== false;
		})];
		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$request = $this->create_request_mock([
			'download_csv' => 1,
			'export_date_from' => '',
			'export_date_to' => '2024-13-01',
			'export_user_id' => 0,
			'export_consent_version' => 0,
		]);
		$this->create_controller($request, 'adm.php?i=test&mode=export')->handle_export();
	}

	public function test_handle_export_reversed_date_range_shows_error()
	{
		self::$valid_form = true;

		$this->acp_manager->method('parse_date_filter')
			->willReturnOnConsecutiveCalls(1735603200, 1704067200);
		$this->acp_manager->expects(self::never())->method('stream_logs_csv');
		$this->acp_manager->expects(self::never())->method('log_admin_export');

		$args = [self::callback(static function ($vars) {
			return $vars['S_ERROR'] === true && strpos($vars['ERROR_MSG'], '"Date from"') !== false;
		})];
		$this->template->expects(self::once())
			->method('assign_vars')
			->with(...$args);

		$request = $this->create_request_mock([
			'download_csv' => 1,
			'export_date_from' => '2024-12-31',
			'export_date_to' => '2024-01-01',
			'export_user_id' => 0,
			'export_consent_version' => 0,
		]);
		$this->create_controller($request, 'adm.php?i=test&mode=export')->handle_export();
	}

	public function test_handle_export_success_logs_and_passes_filters_to_download()
	{
		self::$valid_form = true;

		$this->acp_manager->method('parse_date_filter')
			->willReturnOnConsecutiveCalls(1704067200, 1735689599);
		$this->acp_manager->expects(self::once())->method('log_admin_export');

		$request = $this->create_request_mock([
			'download_csv' => 1,
			'export_date_from' => '2024-01-01',
			'export_date_to' => '2024-12-31',
			'export_user_id' => 42,
			'export_consent_version' => 2,
		]);

		$controller = new \phpbb\consentmanager\tests\controller\testable_acp_controller(
			$this->language,
			$this->acp_manager,
			$request,
			$this->template
		);
		$controller->set_page_url('adm.php?i=test&mode=export');
		$controller->handle_export();

		self::assertSame([
			'date_from'       => 1704067200,
			'date_to'         => 1735689599,
			'user_id'         => 42,
			'consent_version' => 2,
		], $controller->captured_filters);
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
			->willReturnCallback(function ($name, $default) use ($values, $raw_values) {
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

function adm_back_link()
{
	return '';
}
