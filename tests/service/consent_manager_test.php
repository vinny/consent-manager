<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\service;

class consent_manager_test extends \phpbb_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\filesystem\filesystem */
	protected $filesystem;

	/** @var \phpbb\path_helper */
	protected $path_helper;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx, $user;

		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);
		$this->language->add_lang('common', 'phpbb/consentmanager');
		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');

		$this->filesystem = new \phpbb\filesystem\filesystem();

		$request = new \phpbb_mock_request(array(), array(), array(), array(
			'HTTP_HOST' => 'example.com',
			'REQUEST_URI' => '/index.php',
			'SCRIPT_NAME' => '/index.php',
		));
		$symfony_request = new \phpbb\symfony_request($request);
		$this->path_helper = new \phpbb\path_helper(
			$symfony_request,
			$this->filesystem,
			$request,
			$phpbb_root_path,
			$phpEx
		);

		$user = new \phpbb_mock_user();
		$user->data = array(
			'user_id' => ANONYMOUS,
			'user_form_salt' => 'consent-salt',
		);
	}

	public function test_public_metadata_methods()
	{
		$manager = $this->get_manager(array(
			'consentmanager_analytics_enabled' => 1,
			'consentmanager_marketing_enabled' => 0,
			'consentmanager_consent_version' => 7,
		));

		self::assertSame('phpbb_consent_manager', $manager->get_storage_key());
		self::assertSame('phpbb_consent_manager', $manager->get_cookie_name());
		self::assertSame(7, $manager->get_version());
		self::assertTrue($manager->is_supported_category('analytics'));
		self::assertFalse($manager->is_supported_category('foobar'));
		self::assertTrue($manager->is_category_enabled('analytics'));
		self::assertFalse($manager->is_category_enabled('marketing'));

		$categories = $manager->get_categories();
		self::assertSame('necessary', $categories['necessary']['id']);
		self::assertTrue($categories['necessary']['required']);
		self::assertTrue($categories['necessary']['enabled']);
	}

	/**
	 * @dataProvider invalid_registration_data
	 */
	public function test_register_rejects_invalid_definitions($id, array $definition)
	{
		$manager = $this->get_manager();

		self::assertFalse($manager->register($id, $definition));
		self::assertSame(array(), $manager->get_services());
	}

	public function invalid_registration_data()
	{
		return array(
			'invalid id' => array('bad id', array(
				'category' => 'analytics',
				'src' => 'https://cdn.example.com/script.js',
			)),
			'unsupported category' => array('vendor.bundle', array(
				'category' => 'ads',
				'src' => 'https://cdn.example.com/script.js',
			)),
		);
	}

	/**
	 * @dataProvider invalid_script_source_data
	 */
	public function test_register_discards_invalid_script_sources(array $definition)
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.bundle', $definition));
		self::assertSame(array(), $this->get_service('vendor.bundle', $manager)['scripts']);
	}

	public function invalid_script_source_data()
	{
		return array(
			'multiple sources' => array(array(
				'category' => 'analytics',
				'src' => 'https://cdn.example.com/script.js',
				'inline' => 'console.log("bad");',
			)),
			'unsafe remote source' => array(array(
				'category' => 'analytics',
				'src' => 'javascript:alert(1)',
			)),
			'invalid local asset' => array(array(
				'category' => 'analytics',
				'asset' => 'https://cdn.example.com/script.js',
			)),
		);
	}

	public function test_register_normalizes_scripts_and_strips_unsafe_attributes()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.bundle', array(
			'category' => 'analytics',
			'label' => '  Vendor Bundle  ',
			'description' => '  Deferred scripts  ',
			'scripts' => array(
				array(
					'src' => 'https://cdn.example.com/a.js',
					'async' => false,
					'attributes' => array(
						'data-site' => 123,
						'onload' => 'evil()',
						'src' => 'https://ignored.example.com',
					),
				),
				array(
					'inline' => 'window.testConsent = true;',
					'wait_for_dom_ready' => 1,
					'attributes' => array(
						'data-inline' => 'ok',
						'type' => 'ignored',
					),
				),
				array(
					'src' => 'javascript:alert(1)',
				),
			),
		)));

		$service = $this->get_service('vendor.bundle', $manager);

		self::assertSame('Vendor Bundle', $service['label']);
		self::assertSame('Deferred scripts', $service['description']);
		self::assertCount(2, $service['scripts']);

		self::assertSame('vendor.bundle.1', $service['scripts'][0]['id']);
		self::assertSame('https://cdn.example.com/a.js', $service['scripts'][0]['src']);
		self::assertFalse($service['scripts'][0]['async']);
		self::assertSame(array('data-site' => '123'), $service['scripts'][0]['attributes']);

		self::assertSame('vendor.bundle.2', $service['scripts'][1]['id']);
		self::assertSame('', $service['scripts'][1]['src']);
		self::assertSame('window.testConsent = true;', $service['scripts'][1]['inline']);
		self::assertTrue($service['scripts'][1]['wait_for_dom_ready']);
		self::assertSame(array('data-inline' => 'ok'), $service['scripts'][1]['attributes']);
	}

	public function test_register_resolves_local_assets()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.asset', array(
			'category' => 'analytics',
			'asset' => './ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js',
		)));

		$script = $this->get_service('vendor.asset', $manager)['scripts'][0];

		self::assertStringContainsString('ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js', $script['src']);
		self::assertStringContainsString('assets_version=42', $script['src']);
		self::assertTrue($script['async']);
	}

	public function test_build_frontend_payload_collects_registered_and_configured_integrations()
	{
		$dispatcher = $this->get_collect_registrations_dispatcher(function ($data) {
			$data['consent_manager']->register('vendor.bundle', array(
				'category' => 'analytics',
				'label' => 'Vendor bundle',
				'scripts' => array(
					array('src' => 'https://cdn.example.com/analytics.js', 'category' => 'analytics'),
					array('src' => 'https://cdn.example.com/marketing.js', 'category' => 'marketing'),
				),
			));

			return $data;
		});

		$manager = $this->get_manager(array(
			'consentmanager_marketing_enabled' => 0,
			'consentmanager_consent_version' => 7,
		), $this->get_submitted_integrations_json(), $dispatcher);

		$payload = $manager->build_frontend_payload('/app.php/consent/log', 'deadbeef');

		self::assertSame(array('necessary'), $payload['requiredCategories']);
		self::assertSame(array('necessary', 'analytics'), $payload['enabledCategories']);
		self::assertSame(array('analytics'), $payload['optionalCategories']);
		self::assertSame('/app.php/consent/log', $payload['logEndpoint']);
		self::assertSame('deadbeef', $payload['logHash']);
		self::assertSame(array('vendor.bundle', 'board.analytics'), array_column($payload['services'], 'id'));
		self::assertSame(array('vendor.bundle.1', 'board.analytics'), array_column($payload['scripts'], 'id'));
	}

	public function test_get_frontend_template_data_returns_json_payload()
	{
		$manager = $this->get_manager();
		$data = $manager->get_frontend_template_data('/app.php/consent/log?x=<test>', 'abc123');
		$payload = json_decode($data['CONSENTMANAGER_PAYLOAD'], true);

		self::assertTrue($data['S_CONSENTMANAGER_ENABLED']);
		self::assertTrue($data['S_CONSENTMANAGER_ANALYTICS_ENABLED']);
		self::assertTrue($data['S_CONSENTMANAGER_MARKETING_ENABLED']);
		self::assertSame('/app.php/consent/log?x=<test>', $payload['logEndpoint']);
		self::assertSame('abc123', $payload['logHash']);
		self::assertSame($this->language->lang('CONSENTMANAGER_SETTINGS_TITLE'), $payload['strings']['settingsTitle']);
	}

	public function test_get_configured_integrations_normalizes_stored_data()
	{
		$manager = $this->get_manager(array(), $this->get_submitted_integrations_json());
		$integration = $manager->get_configured_integrations()[0];

		self::assertSame('board.analytics', $integration['id']);
		self::assertSame('Board Analytics', $integration['label']);
		self::assertSame('analytics', $integration['category']);
		self::assertSame('Loads a simple analytics library after consent.', $integration['description']);
		self::assertSame('https://cdn.example.com/analytics.js', $integration['scripts'][0]['src']);
		self::assertTrue($integration['scripts'][0]['async']);
		self::assertFalse($integration['scripts'][0]['defer']);
	}

	public function test_get_configured_integrations_returns_empty_array_for_invalid_data()
	{
		self::assertSame(array(), $this->get_manager(array(), '{not json')->get_configured_integrations());
	}

	public function test_get_acp_template_data_pretty_prints_stored_integrations()
	{
		$template_data = $this->get_manager(array(), $this->get_submitted_integrations_json())->get_acp_template_data();

		self::assertSame($this->get_pretty_integrations_json(), $template_data['CONSENTMANAGER_INTEGRATIONS']);
		self::assertSame(1, $template_data['CONSENTMANAGER_VERSION']);
	}

	public function test_get_acp_template_data_keeps_invalid_json_verbatim()
	{
		$template_data = $this->get_manager(array(), '{not json')->get_acp_template_data();

		self::assertSame('{not json', $template_data['CONSENTMANAGER_INTEGRATIONS']);
	}

	public function test_save_acp_settings_updates_flags_and_integrations()
	{
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->expects(self::once())
			->method('set')
			->with('consentmanager_integrations', trim($this->get_pretty_integrations_json()));

		$manager = $this->get_manager(array(), '', null, $config_text);
		$errors = array();

		self::assertTrue($manager->save_acp_settings(array(
			'analytics_enabled' => 0,
			'marketing_enabled' => 1,
			'integrations' => $this->get_pretty_integrations_json(),
		), $errors));
		self::assertSame(array(), $errors);
		self::assertFalse($manager->is_category_enabled('analytics'));
		self::assertTrue($manager->is_category_enabled('marketing'));
	}

	public function test_save_acp_settings_stores_empty_integrations_as_empty_string()
	{
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->expects(self::once())
			->method('set')
			->with('consentmanager_integrations', '');

		$manager = $this->get_manager(array(), '', null, $config_text);
		$errors = array();

		self::assertTrue($manager->save_acp_settings(array(
			'analytics_enabled' => 1,
			'marketing_enabled' => 0,
			'integrations' => '',
		), $errors));
		self::assertSame(array(), $errors);
	}

	/**
	 * @dataProvider invalid_integrations_data
	 */
	public function test_save_acp_settings_rejects_invalid_integrations($json)
	{
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->expects(self::never())
			->method('set');

		$manager = $this->get_manager(array(), '', null, $config_text);
		$errors = array();

		self::assertFalse($manager->save_acp_settings(array(
			'analytics_enabled' => 1,
			'marketing_enabled' => 1,
			'integrations' => $json,
		), $errors));
		self::assertSame(array($this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS')), $errors);
	}

	public function invalid_integrations_data()
	{
		return array(
			'malformed json' => array('{not json'),
			'top level object' => array($this->get_non_array_integrations_json()),
		);
	}

	public function test_normalize_integrations_reports_invalid_entries_and_keeps_last_duplicate()
	{
		$manager = $this->get_manager();
		$errors = array();

		$integrations = $manager->normalize_integrations(array(
			array(
				'id' => 'board.analytics',
				'category' => 'analytics',
				'label' => 'Old label',
				'src' => 'https://cdn.example.com/one.js',
			),
			array(
				'id' => 'bad id',
				'category' => 'analytics',
				'src' => 'https://cdn.example.com/two.js',
			),
			array(
				'id' => 'board.analytics',
				'category' => 'analytics',
				'label' => 'New label',
				'src' => 'https://cdn.example.com/three.js',
				'defer' => true,
			),
		), $errors);

		self::assertCount(1, $integrations);
		self::assertSame('New label', $integrations[0]['label']);
		self::assertSame('https://cdn.example.com/three.js', $integrations[0]['scripts'][0]['src']);
		self::assertTrue($integrations[0]['scripts'][0]['defer']);
		self::assertSame(
			array($this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY', 2)),
			$errors
		);
	}

	public function test_normalize_categories_keeps_required_and_enabled_categories()
	{
		$manager = $this->get_manager(array(
			'consentmanager_marketing_enabled' => 0,
		));

		self::assertSame(
			array('necessary', 'analytics'),
			$manager->normalize_categories(array('analytics', 'necessary', 'marketing', 'analytics', 'unknown'))
		);
	}

	public function test_validate_log_payload_accepts_valid_hash_and_normalizes_categories()
	{
		$manager = $this->get_manager(array(
			'consentmanager_marketing_enabled' => 0,
			'consentmanager_consent_version' => 4,
		));

		$submission = $manager->validate_log_payload(array(
			'hash' => generate_link_hash('phpbb.consentmanager.log'),
			'version' => 4,
			'categories' => array('analytics', 'necessary', 'marketing', 'analytics'),
		));

		self::assertTrue($submission['success']);
		self::assertSame(array('necessary', 'analytics'), $submission['categories']);
		self::assertSame(4, $submission['version']);
	}

	public function test_validate_log_payload_rejects_invalid_hash()
	{
		$submission = $this->get_manager()->validate_log_payload(array(
			'hash' => 'deadbeef',
			'version' => 1,
			'categories' => array('analytics'),
		));

		self::assertSame(array(
			'success' => false,
			'error' => 'invalid_hash',
		), $submission);
	}

	public function test_validate_log_payload_rejects_version_mismatch()
	{
		$submission = $this->get_manager(array(
			'consentmanager_consent_version' => 9,
		))->validate_log_payload(array(
			'hash' => generate_link_hash('phpbb.consentmanager.log'),
			'version' => 1,
			'categories' => array('analytics'),
		));

		self::assertSame(array(
			'success' => false,
			'error' => 'version_mismatch',
		), $submission);
	}

	protected function get_manager(array $config_values = array(), $stored_integrations = '', $dispatcher = null, $config_text = null)
	{
		if ($config_text === null)
		{
			$config_text = $this->get_config_text($stored_integrations);
		}

		if ($dispatcher === null)
		{
			$dispatcher = new \phpbb_mock_event_dispatcher();
		}

		$config = new \phpbb\config\config(array_merge(array(
			'consentmanager_analytics_enabled' => 1,
			'consentmanager_marketing_enabled' => 1,
			'consentmanager_consent_version' => 1,
			'assets_version' => '42',
			'rand_seed' => 'seed',
		), $config_values));

		$twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'findTemplate'))
			->getMock();
		$twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);

		return new \phpbb\consentmanager\service\consent_manager(
			$config,
			$config_text,
			$this->language,
			$dispatcher,
			$twig_environment,
			$this->path_helper,
			$this->filesystem
		);
	}

	protected function get_config_text($stored_integrations = '')
	{
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->method('get')
			->willReturnMap(array(
				array('consentmanager_integrations', $stored_integrations),
			));

		return $config_text;
	}

	protected function get_service($id, \phpbb\consentmanager\service\consent_manager $manager)
	{
		$services = $manager->get_services();
		self::assertArrayHasKey($id, $services);

		return $services[$id];
	}

	protected function get_collect_registrations_dispatcher(callable $callback)
	{
		$dispatcher = $this->createMock('phpbb\\event\\dispatcher_interface');
		$dispatcher->expects(self::once())
			->method('trigger_event')
			->with(
				'phpbb.consentmanager.collect_registrations',
				$this->callback(function ($vars) {
					return isset($vars['consent_manager'])
						&& $vars['consent_manager'] instanceof \phpbb\consentmanager\service\consent_manager;
				})
			)
			->willReturnCallback(function ($event_name, $data = array()) use ($callback) {
				return $callback($data);
			});

		return $dispatcher;
	}

	protected function get_submitted_integrations_json()
	{
		return '[{"id":"board.analytics","category":"analytics","label":"Board Analytics","description":"Loads a simple analytics library after consent.","src":"https://cdn.example.com/analytics.js","async":true}]';
	}

	protected function get_pretty_integrations_json()
	{
		return <<<'JSON'
[
    {
        "id": "board.analytics",
        "category": "analytics",
        "label": "Board Analytics",
        "description": "Loads a simple analytics library after consent.",
        "src": "https://cdn.example.com/analytics.js",
        "async": true
    }
]
JSON;
	}

	protected function get_non_array_integrations_json()
	{
		return '{"id":"board.analytics","category":"analytics","label":"Board Analytics","description":"Loads a simple analytics library after consent.","src":"https://cdn.example.com/analytics.js","async":true}';
	}
}
