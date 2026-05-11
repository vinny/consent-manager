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
			'user_form_salt' => 'listener-test-salt',
		];
	}

	public function test_get_subscribed_events()
	{
		self::assertSame([
			'core.text_formatter_s9e_configure_after' => [['configure_iframe_embeds', -10]],
			'core.text_formatter_s9e_renderer_setup' => 'configure_iframe_renderer',
			'core.page_header_after' => 'inject_frontend',
		], \phpbb\consentmanager\event\listener::getSubscribedEvents());
	}

	public function test_configure_iframe_embeds_delegates_to_media_manager()
	{
		$configurator = new \s9e\TextFormatter\Configurator();

		$args = [$configurator];

		$media_manager = $this->createMock('\phpbb\consentmanager\service\media_manager');
		$media_manager->expects(self::once())
			->method('configure_iframe_embeds')
			->with(...$args);

		$listener = new \phpbb\consentmanager\event\listener(
			$this->createMock('\phpbb\controller\helper'),
			$this->language,
			$this->createMock('\phpbb\consentmanager\service\consent_manager_interface'),
			$this->createMock('\phpbb\template\template'),
			$media_manager
		);

		$listener->configure_iframe_embeds(new \phpbb\event\data([
			'configurator' => $configurator,
		]));
	}

	public function test_configure_iframe_renderer_delegates_to_media_manager()
	{
		$renderer = $this->getMockBuilder('\phpbb\textformatter\s9e\renderer')
			->disableOriginalConstructor()
			->getMock();

		$args = [$renderer];

		$media_manager = $this->createMock('\phpbb\consentmanager\service\media_manager');
		$media_manager->expects(self::once())
			->method('configure_iframe_renderer')
			->with(...$args);

		$listener = new \phpbb\consentmanager\event\listener(
			$this->createMock('\phpbb\controller\helper'),
			$this->language,
			$this->createMock('\phpbb\consentmanager\service\consent_manager_interface'),
			$this->createMock('\phpbb\template\template'),
			$media_manager
		);

		$listener->configure_iframe_renderer(new \phpbb\event\data([
			'renderer' => $renderer,
		]));
	}

	public function inject_frontend_assigns_template_payload_data()
	{
		return [
			'front end' => [true],
			'non front end' => [false],
		];
	}

	/**
	 * @dataProvider inject_frontend_assigns_template_payload_data
	 */
	public function test_inject_frontend_assigns_template_payload($invoke)
	{
		$helper = $this->createMock('\phpbb\controller\helper');
		$helper_args = ['phpbb_consentmanager_log_controller'];
		$helper->expects($invoke ? self::once() : self::never())
			->method('route')
			->with(...$helper_args)
			->willReturn('/app.php/consent/log');

		$language_args = ['common', 'phpbb/consentmanager'];
		$this->language->expects($invoke ? self::once() : self::never())
			->method('add_lang')
			->with(...$language_args);

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects($invoke ? self::once() : self::never())
			->method('has_optional_categories')
			->willReturn(true);
		$consent_manager_args = ['/app.php/consent/log', generate_link_hash('phpbb.consentmanager.log')];
		$consent_manager->expects($invoke ? self::once() : self::never())
			->method('get_frontend_template_data')
			->with(...$consent_manager_args)
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
						[
							'LABEL' => 'Cookie baker',
							'DESCRIPTION' => 'Delicious cookies',
						],
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
						[
							'LABEL' => 'Cookie baker',
							'DESCRIPTION' => 'Delicious cookies',
						],
					],
				]],
				['CONSENTMANAGER_CATEGORIES.CONSENTMANAGER_SERVICES', [
					'LABEL' => 'Cookie baker',
					'DESCRIPTION' => 'Delicious cookies',
				]]
			);

		$listener = new class($helper, $this->language, $consent_manager, $template, $this->createMock('\phpbb\consentmanager\service\media_manager'), $invoke) extends \phpbb\consentmanager\event\listener {
			/** @var bool */
			protected $is_frontend_context;

			public function __construct($helper, $language, $consent_manager, $template, $media_manager, $is_frontend_context)
			{
				parent::__construct($helper, $language, $consent_manager, $template, $media_manager);
				$this->is_frontend_context = $is_frontend_context;
			}

			protected function is_acp_or_installer()
			{
				return !$this->is_frontend_context;
			}
		};

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
			$template,
			$this->createMock('\phpbb\consentmanager\service\media_manager')
		);

		$listener->inject_frontend();
	}
}
