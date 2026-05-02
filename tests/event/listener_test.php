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

	protected function setUp(): void
	{
		parent::setUp();

		global $user;

		$this->language = $this->createMock('\phpbb\language\language');

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = [
			'user_id' => ANONYMOUS,
			'user_form_salt' => 'listener-salt',
		];
	}

	public function test_get_subscribed_events()
	{
		self::assertSame([
			'core.page_header_after' => 'inject_frontend',
		], \phpbb\consentmanager\event\listener::getSubscribedEvents());
	}

	public function inject_frontend_assigns_template_payload_data()
	{
		return [
			'front end'  => [false, false],
			'in admin'   => [true, false],
			'in install' => [false, true],
		];
	}

	/**
	 * @dataProvider inject_frontend_assigns_template_payload_data
	 * @runInSeparateProcess
	 */
	public function test_inject_frontend_assigns_template_payload($in_admin, $in_install)
	{
		if ($in_admin)
		{
			define('ADMIN_START', true);
		}

		if ($in_install)
		{
			define('IN_INSTALL', true);
		}

		$invoke = !($in_admin || $in_install);

		$helper = $this->createMock('\phpbb\controller\helper');
		$helper->expects($invoke ? self::once() : self::never())
			->method('route')
			->with('phpbb_consentmanager_log_controller')
			->willReturn('/app.php/consent/log');

		$this->language->expects($invoke ? self::once() : self::never())
			->method('add_lang')
			->with('common', 'phpbb/consentmanager');

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects($invoke ? self::once() : self::never())
			->method('has_optional_categories')
			->willReturn(true);
		$consent_manager->expects($invoke ? self::once() : self::never())
			->method('get_frontend_template_data')
			->with('/app.php/consent/log', generate_link_hash('phpbb.consentmanager.log'))
			->willReturn([
				'S_CONSENTMANAGER_ENABLED' => true,
				'CONSENTMANAGER_PAYLOAD' => '{"version":1}',
			]);
		$consent_manager->expects($invoke ? self::once() : self::never())
			->method('get_frontend_category_data')
			->willReturn([
				[
					'ID'          => 'necessary',
					'LABEL'       => 'Necessary',
					'DESCRIPTION' => 'Required cookies.',
					'REQUIRED'    => true,
					'services'    => [
						0 => [
							'LABEL'			=> 'Cookie baker',
							'DESCRIPTION'	=> 'Delicious cookies',
						]
					],
				],
			]);

		$template = $this->createMock('\phpbb\template\template');
		$template->expects($invoke ? self::once() : self::never())
			->method('assign_vars')
			->with([
				'S_CONSENTMANAGER_ENABLED' => true,
				'CONSENTMANAGER_PAYLOAD' => '{"version":1}',
			]);
		$template->expects($invoke ? self::exactly(2) : self::never())
			->method('assign_block_vars')
			->withConsecutive(
				['CONSENTMANAGER_CATEGORIES', [
					'ID'          => 'necessary',
					'LABEL'       => 'Necessary',
					'DESCRIPTION' => 'Required cookies.',
					'REQUIRED'    => true,
					'services'    => [
						0 => [
							'LABEL'			=> 'Cookie baker',
							'DESCRIPTION'	=> 'Delicious cookies',
						]
					],
				]],
				['CONSENTMANAGER_CATEGORIES.CONSENTMANAGER_SERVICES', [
					'LABEL'			=> 'Cookie baker',
					'DESCRIPTION'	=> 'Delicious cookies',
				]]
			);

		$listener = new \phpbb\consentmanager\event\listener(
			$helper,
			$this->language,
			$consent_manager,
			$template
		);

		$listener->inject_frontend();
	}

	public function test_inject_frontend_skips_category_blocks_when_frontend_disabled()
	{
		$helper = $this->createMock('\phpbb\controller\helper');
		$helper->expects(self::never())
			->method('route');

		$this->language->expects(self::never())
			->method('add_lang');

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('has_optional_categories')
			->willReturn(false);
		$consent_manager->expects(self::never())
			->method('get_frontend_template_data');
		$consent_manager->expects(self::never())
			->method('get_frontend_category_data');

		$template = $this->createMock('\phpbb\template\template');
		$template->expects(self::never())
			->method('assign_vars');
		$template->expects(self::never())
			->method('assign_block_vars');

		$listener = new \phpbb\consentmanager\event\listener(
			$helper,
			$this->language,
			$consent_manager,
			$template
		);

		$listener->inject_frontend();
	}
}
