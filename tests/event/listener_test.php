<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\event;

class listener_test extends \phpbb_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\user */
	protected $user;

	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx, $user;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);
		$this->language->add_lang('common', 'phpbb/consentmanager');
		$this->language->add_lang('common');

		$this->user = new \phpbb\user($this->language, '\phpbb\datetime');
		$this->user->data = array(
			'user_id' => ANONYMOUS,
			'user_form_salt' => 'listener-salt',
		);
		$this->user->session_id = 'listener-session';
		$user = $this->user;
	}

	public function test_get_subscribed_events()
	{
		self::assertSame(array(
			'core.page_header_after' => 'inject_frontend',
		), \phpbb\consentmanager\event\listener::getSubscribedEvents());
	}

	public function test_inject_frontend_assigns_template_payload()
	{
		$helper = $this->createMock('\phpbb\controller\helper');
		$helper->expects(self::once())
			->method('route')
			->with('phpbb_consentmanager_log_controller')
			->willReturn('/app.php/consent/log');

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('get_frontend_template_data')
			->with('/app.php/consent/log', generate_link_hash('phpbb.consentmanager.log'))
			->willReturn(array(
				'S_CONSENTMANAGER_ENABLED' => true,
				'CONSENTMANAGER_PAYLOAD' => '{"version":1}',
			));

		$template = $this->createMock('\phpbb\template\template');
		$template->expects(self::once())
			->method('assign_vars')
			->with(array(
				'S_CONSENTMANAGER_ENABLED' => true,
				'CONSENTMANAGER_PAYLOAD' => '{"version":1}',
			));

		$listener = new \phpbb\consentmanager\event\listener(
			$helper,
			$this->language,
			$consent_manager,
			$template
		);

		$listener->inject_frontend();
	}

	public function test_inject_frontend_loads_extension_language_before_assigning_payload()
	{
		$helper = $this->createMock('\phpbb\controller\helper');
		$helper->expects(self::once())
			->method('route')
			->willReturn('/app.php/consent/log');

		$language = $this->createMock('\phpbb\language\language');
		$language->expects(self::once())
			->method('add_lang');

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('get_frontend_template_data')
			->willReturn(array());

		$template = $this->createMock('\phpbb\template\template');
		$template->expects(self::once())
			->method('assign_vars')
			->with(array());

		$listener = new \phpbb\consentmanager\event\listener(
			$helper,
			$language,
			$consent_manager,
			$template
		);

		$listener->inject_frontend();
	}
}
