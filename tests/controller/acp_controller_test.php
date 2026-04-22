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

	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);
		$this->language->add_lang('common');
		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');

		$this->user = new \phpbb\user($this->language, '\phpbb\datetime');
		$this->user->data = array(
			'user_id' => 2,
			'user_form_salt' => 'form-salt',
		);
		$this->user->session_id = 'session-id';
		$this->user->lang = $this->language->get_lang_array();
	}

	public function test_handle_assigns_existing_template_data()
	{
		$request = $this->create_request_mock();
		$template = $this->createMock('\phpbb\template\template');
		$template->expects(self::once())
			->method('assign_vars')
			->with(array(
				'S_CONSENTMANAGER_ANALYTICS' => true,
				'CONSENTMANAGER_VERSION' => 1,
				'S_ERROR' => false,
				'ERROR_MSG' => '',
				'U_ACTION' => 'adm.php?i=test',
			));
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('get_acp_template_data')
			->willReturn(array(
				'S_CONSENTMANAGER_ANALYTICS' => true,
				'CONSENTMANAGER_VERSION' => 1,
			));

		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');
		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$consent_manager,
			$log_manager,
			$request,
			$template
		);
		$controller->set_page_url('adm.php?i=test');
		$controller->handle();
	}

	public function test_handle_submit_validation_errors_reassigns_form_data()
	{
		self::$valid_form = true;

		$request = $this->create_request_mock(
			array(
				'submit' => 1,
				'consentmanager_analytics_enabled' => 1,
				'consentmanager_marketing_enabled' => 0,
			),
			array(
				'consentmanager_integrations' => "  invalid json  \n",
			)
		);
		$template = $this->createMock('\phpbb\template\template');
		$template->expects(self::once())
			->method('assign_vars')
			->with(self::callback(function ($vars) {
				return $vars['S_ERROR']
					&& $vars['ERROR_MSG'] === 'Invalid integrations'
					&& $vars['U_ACTION'] === 'adm.php?i=test'
					&& isset($vars['CONSENTMANAGER_VERSION']);
			}));
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('save_acp_settings')
			->willReturnCallback(function (array $settings, array &$errors) {
				self::assertSame(array(
					'analytics_enabled' => 1,
					'marketing_enabled' => 0,
					'integrations' => 'invalid json',
				), $settings);

				$errors = array('Invalid integrations');
				return false;
			});
		$consent_manager->expects(self::once())
			->method('get_acp_template_data')
			->willReturn(array(
				'CONSENTMANAGER_VERSION' => 3,
			));

		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');
		$log_manager->expects(self::never())
			->method('log_admin_settings_updated');

		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$consent_manager,
			$log_manager,
			$request,
			$template
		);
		$controller->set_page_url('adm.php?i=test');
		$controller->handle();
	}

	public function test_handle_submit_success_logs_and_triggers_success_notice()
	{
		self::$valid_form = true;

		$request = $this->create_request_mock(
			array(
				'submit' => 1,
				'consentmanager_analytics_enabled' => 0,
				'consentmanager_marketing_enabled' => 1,
			),
			array(
				'consentmanager_integrations' => '[]',
			)
		);
		$template = $this->createMock('\phpbb\template\template');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('CONFIG_UPDATED'));

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('save_acp_settings')
			->willReturn(true);

		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');
		$log_manager->expects(self::once())
			->method('log_admin_settings_updated');

		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$consent_manager,
			$log_manager,
			$request,
			$template
		);
		$controller->set_page_url('adm.php?i=test');
		$controller->handle();
	}

	public function test_handle_reset_consent_logs_and_triggers_success_notice()
	{
		self::$valid_form = true;

		$request = $this->create_request_mock(array(
			'reset_consent' => 1,
		));
		$template = $this->createMock('\phpbb\template\template');
		$this->setExpectedTriggerError(E_USER_NOTICE, $this->language->lang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS'));

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('reset_consent_version');

		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');
		$log_manager->expects(self::once())
			->method('log_admin_reprompt');

		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$consent_manager,
			$log_manager,
			$request,
			$template
		);
		$controller->set_page_url('adm.php?i=test');
		$controller->handle();
	}

	public function test_handle_rejects_invalid_form_key()
	{
		self::$valid_form = false;

		$request = $this->create_request_mock(array(
			'submit' => 1,
		));
		$template = $this->createMock('\phpbb\template\template');
		$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::never())
			->method('save_acp_settings');

		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');

		$controller = new \phpbb\consentmanager\controller\acp_controller(
			$this->language,
			$consent_manager,
			$log_manager,
			$request,
			$template
		);
		$controller->set_page_url('adm.php?i=test');
		$controller->handle();
	}

	protected function create_request_mock(array $values = array(), array $raw_values = array())
	{
		$request = $this->getMockBuilder('\phpbb\request\request')
			->disableOriginalConstructor()
			->setMethods(array('is_set_post', 'variable', 'raw_variable'))
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
