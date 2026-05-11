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
			'consentmanager_media_enabled' => 1,
			'consentmanager_consent_version' => 7,
		));

		self::assertSame('phpbb_consent_manager', $manager->get_storage_key());
		self::assertSame('phpbb_consent_manager', $manager->get_cookie_name());
		self::assertSame(7, $manager->get_version());
		self::assertTrue($manager->is_supported_category('analytics'));
		self::assertTrue($manager->is_supported_category('media'));
		self::assertFalse($manager->is_supported_category('foobar'));
		self::assertTrue($manager->is_category_enabled('analytics'));
		self::assertFalse($manager->is_category_enabled('marketing'));
		self::assertTrue($manager->is_category_enabled('media'));
		self::assertTrue($manager->has_optional_categories());

		$categories = $manager->get_categories();
		self::assertSame('necessary', $categories['necessary']['id']);
		self::assertTrue($categories['necessary']['required']);
		self::assertTrue($categories['necessary']['enabled']);
	}

	public function test_get_categories_are_translated_after_language_is_loaded()
	{
		$language = new \phpbb\language\language(
			new \phpbb\language\language_file_loader($this->phpbb_root_path, $this->php_ext)
		);
		$manager = $this->get_manager([], '', null, null, null, null, $language);

		self::assertTrue($manager->has_optional_categories());

		$language->add_lang('common', 'phpbb/consentmanager');
		$categories = $manager->get_categories();

		self::assertSame($language->lang('CONSENTMANAGER_CATEGORY_ANALYTICS'), $categories['analytics']['label']);
		self::assertSame($language->lang('CONSENTMANAGER_CATEGORY_ANALYTICS_EXPLAIN'), $categories['analytics']['description']);
	}

	public function test_get_configured_integrations_memoizes_results_per_request()
	{
		$config_get_args = ['consentmanager_integrations'];
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->expects(self::once())
			->method('get')
			->with(...$config_get_args)
			->willReturn($this->get_submitted_integrations_json());

		$manager = $this->get_manager([], '', null, $config_text);

		self::assertCount(1, $manager->get_configured_integrations());
		self::assertCount(1, $manager->get_configured_integrations());
		self::assertArrayHasKey('board.analytics', $manager->get_services());
	}

	public function test_get_configured_integrations_uses_persistent_cache()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);
		$config_text = $this->get_config_text($this->get_submitted_integrations_json());

		$this->get_manager([], '', null, $config_text, null, null, null, $consent_cache)
			->get_configured_integrations();

		$cached_manager = $this->getMockBuilder('\phpbb\consentmanager\service\consent_manager')
			->setConstructorArgs($this->get_manager_constructor_args([], $config_text, new \phpbb_mock_event_dispatcher(), null, null, $this->language, $consent_cache))
			->setMethods(['normalize_integrations'])
			->getMock();
		$cached_manager->expects(self::never())
			->method('normalize_integrations');

		self::assertCount(1, $cached_manager->get_configured_integrations());
	}

	public function test_get_configured_integrations_reloads_after_persistent_cache_invalidation()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);
		$config_text = $this->get_config_text($this->get_submitted_integrations_json());

		$this->get_manager([], '', null, $config_text, null, null, null, $consent_cache)
			->get_configured_integrations();

		$consent_cache->invalidate();

		$refreshed_manager = $this->getMockBuilder('\phpbb\consentmanager\service\consent_manager')
			->setConstructorArgs($this->get_manager_constructor_args([], $config_text, new \phpbb_mock_event_dispatcher(), null, null, $this->language, $consent_cache))
			->setMethods(['normalize_integrations'])
			->getMock();
		$normalize_args = [$this->get_submitted_integrations_json(), self::anything()];
		$refreshed_manager->expects(self::once())
			->method('normalize_integrations')
			->with(...$normalize_args)
			->willReturn([[
				'id' => 'board.analytics',
				'label' => 'Board Analytics',
				'category' => 'analytics',
				'description' => 'Loads a simple analytics library after consent.',
				'scripts' => [[
					'id' => 'board.analytics',
					'category' => 'analytics',
					'src' => 'https://cdn.example.com/analytics.js',
					'inline' => '',
					'async' => true,
					'defer' => false,
					'attributes' => [],
				]],
			]]);

		self::assertCount(1, $refreshed_manager->get_configured_integrations());
	}

	public function test_get_configured_integrations_cache_ignores_asset_version_changes()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);
		$config_text = $this->get_config_text($this->get_submitted_integrations_json());

		$this->get_manager(['assets_version' => 42], '', null, $config_text, null, null, null, $consent_cache)
			->get_configured_integrations();

		$cached_manager = $this->getMockBuilder('\phpbb\consentmanager\service\consent_manager')
			->setConstructorArgs($this->get_manager_constructor_args(['assets_version' => 99], $config_text, new \phpbb_mock_event_dispatcher(), null, null, $this->language, $consent_cache))
			->setMethods(['normalize_integrations'])
			->getMock();
		$cached_manager->expects(self::never())
			->method('normalize_integrations');

		self::assertCount(1, $cached_manager->get_configured_integrations());
	}

	public function test_get_services_memoizes_results_after_first_collection()
	{
		$config_get_args = ['consentmanager_integrations'];
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->expects(self::once())
			->method('get')
			->with(...$config_get_args)
			->willReturn('');

		$dispatcher = $this->get_collect_registrations_dispatcher(function ($data) {
			$data['consent_manager']->register('vendor.memoized', array(
				'category' => 'analytics',
				'src' => 'https://cdn.example.com/memoized.js',
			));

			return $data;
		});
		$manager = $this->get_manager([], '', $dispatcher, $config_text);

		self::assertArrayHasKey('vendor.memoized', $manager->get_services());
		self::assertArrayHasKey('vendor.memoized', $manager->get_services());
	}

	public function test_get_services_keeps_manual_registrations_after_collection()
	{
		$dispatcher = $this->get_collect_registrations_dispatcher(function ($data) {
			$data['consent_manager']->register('vendor.collected', array(
				'category' => 'analytics',
				'src' => 'https://cdn.example.com/collected.js',
			));

			return $data;
		});
		$manager = $this->get_manager([], '', $dispatcher);

		self::assertArrayHasKey('vendor.collected', $manager->get_services());

		self::assertTrue($manager->register('vendor.manual', array(
			'category' => 'analytics',
			'src' => 'https://cdn.example.com/manual.js',
		)));

		$services = $manager->get_services();
		self::assertArrayHasKey('vendor.collected', $services);
		self::assertArrayHasKey('vendor.manual', $services);
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
			'remote source with forbidden characters' => array(array(
				'category' => 'analytics',
				'src' => 'https://cdn.example.com/<bad>.js',
			)),
			'remote source parse failure' => array(array(
				'category' => 'analytics',
				'src' => 'http://example.com:99999/script.js',
			)),
			'invalid single-script id' => array(array(
				'category' => 'analytics',
				'id' => 'bad id',
				'src' => 'https://cdn.example.com/script.js',
			)),
			'invalid local asset' => array(array(
				'category' => 'analytics',
				'asset' => 'https://cdn.example.com/script.js',
			)),
			'invalid local asset characters' => array(array(
				'category' => 'analytics',
				'asset' => 'foo<bar.js',
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
				'not-an-array',
				array(
					'category' => 'ads',
					'src' => 'https://cdn.example.com/rejected-category.js',
				),
				array(
					'inline' => 'window.testConsentFallback = true;',
					'attributes' => 'not-an-array',
				),
				array(
					'src' => 'javascript:alert(1)',
				),
			),
		)));

		$service = $this->get_service('vendor.bundle', $manager);

		self::assertSame('Vendor Bundle', $service['label']);
		self::assertSame('Deferred scripts', $service['description']);
		self::assertCount(3, $service['scripts']);

		self::assertSame('vendor.bundle.1', $service['scripts'][0]['id']);
		self::assertSame('https://cdn.example.com/a.js', $service['scripts'][0]['src']);
		self::assertFalse($service['scripts'][0]['async']);
		self::assertSame(array('data-site' => '123'), $service['scripts'][0]['attributes']);

		self::assertSame('vendor.bundle.2', $service['scripts'][1]['id']);
		self::assertSame('', $service['scripts'][1]['src']);
		self::assertSame('window.testConsent = true;', $service['scripts'][1]['inline']);
		self::assertTrue($service['scripts'][1]['wait_for_dom_ready']);
		self::assertSame(array('data-inline' => 'ok'), $service['scripts'][1]['attributes']);

		self::assertSame('vendor.bundle.5', $service['scripts'][2]['id']);
		self::assertSame('', $service['scripts'][2]['src']);
		self::assertSame('window.testConsentFallback = true;', $service['scripts'][2]['inline']);
		self::assertFalse($service['scripts'][2]['wait_for_dom_ready']);
		self::assertSame(array(), $service['scripts'][2]['attributes']);
	}

	public function test_register_preserves_explicit_script_ids_in_script_lists()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.bundle', array(
			'category' => 'analytics',
			'scripts' => array(
				array(
					'id' => 'vendor.bundle.loader',
					'src' => 'https://cdn.example.com/a.js',
				),
				array(
					'inline' => 'window.testConsent = true;',
				),
			),
		)));

		$service = $this->get_service('vendor.bundle', $manager);

		self::assertSame('vendor.bundle.loader', $service['scripts'][0]['id']);
		self::assertSame('vendor.bundle.2', $service['scripts'][1]['id']);
	}

	public function test_register_renames_later_script_when_explicit_id_matches_registration_id()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.bundle', array(
			'category' => 'analytics',
			'scripts' => array(
				array(
					'id' => 'vendor.bundle.loader',
					'src' => 'https://cdn.example.com/a.js',
				),
				array(
					'id' => 'vendor.bundle',
					'inline' => 'window.testConsent = true;',
				),
			),
		)));

		$service = $this->get_service('vendor.bundle', $manager);

		self::assertSame('vendor.bundle.loader', $service['scripts'][0]['id']);
		self::assertSame('vendor.bundle.2', $service['scripts'][1]['id']);
	}

	public function test_register_allows_reregistering_same_registration_id()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.bundle', array(
			'category' => 'analytics',
			'scripts' => array(
				array(
					'id' => 'vendor.bundle.loader',
					'src' => 'https://cdn.example.com/original.js',
				),
			),
		)));

		self::assertTrue($manager->register('vendor.bundle', array(
			'category' => 'analytics',
			'scripts' => array(
				array(
					'id' => 'vendor.bundle.loader',
					'src' => 'https://cdn.example.com/updated.js',
				),
				array(
					'id' => 'vendor.bundle.settings',
					'inline' => 'window.updatedConsent = true;',
				),
			),
		)));

		$service = $this->get_service('vendor.bundle', $manager);

		self::assertCount(2, $service['scripts']);
		self::assertSame('vendor.bundle.loader', $service['scripts'][0]['id']);
		self::assertSame('https://cdn.example.com/updated.js', $service['scripts'][0]['src']);
		self::assertSame('vendor.bundle.settings', $service['scripts'][1]['id']);
		self::assertSame('window.updatedConsent = true;', $service['scripts'][1]['inline']);
	}

	public function test_register_skips_duplicate_script_ids()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->register('vendor.first', array(
			'category' => 'analytics',
			'scripts' => array(
				array(
					'id' => 'vendor.shared.loader',
					'src' => 'https://cdn.example.com/first.js',
				),
			),
		)));

		self::assertTrue($manager->register('vendor.second', array(
			'category' => 'analytics',
			'scripts' => array(
				array(
					'id' => 'vendor.second.loader',
					'src' => 'https://cdn.example.com/second.js',
				),
				array(
					'id' => 'vendor.shared.loader',
					'src' => 'https://cdn.example.com/duplicate.js',
				),
				array(
					'id' => 'vendor.second.loader',
					'inline' => 'window.duplicate = true;',
				),
			),
		)));

		$service = $this->get_service('vendor.second', $manager);

		self::assertCount(1, $service['scripts']);
		self::assertSame('vendor.second.loader', $service['scripts'][0]['id']);
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

	public function test_register_memoizes_template_assets_within_request()
	{
		$twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'getNamespaceLookUpOrder', 'findTemplate'))
			->getMock();
		$twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$twig_environment->method('getNamespaceLookUpOrder')
			->willReturn(['__main__']);
		$find_template_args = ['styles/prosilver/template/consentmanager.js'];
		$twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willReturn($this->phpbb_root_path . 'ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js');

		$manager = $this->get_manager([], '', null, null, $twig_environment);
		self::assertTrue($manager->register('vendor.asset.first', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/consentmanager.js',
		)));
		self::assertTrue($manager->register('vendor.asset.second', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/consentmanager.js',
		)));

		self::assertNotSame('', $this->get_service('vendor.asset.first', $manager)['scripts'][0]['src']);
		self::assertNotSame('', $this->get_service('vendor.asset.second', $manager)['scripts'][0]['src']);
	}

	public function test_register_re_resolves_template_assets_across_requests()
	{
		$first_twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'getNamespaceLookUpOrder', 'findTemplate'))
			->getMock();
		$first_twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$first_twig_environment->method('getNamespaceLookUpOrder')
			->willReturn(['__main__']);
		$find_template_args = ['styles/prosilver/template/consentmanager.js'];
		$first_twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willReturn($this->phpbb_root_path . 'ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js');

		$first_manager = $this->get_manager([], '', null, null, $first_twig_environment);
		self::assertTrue($first_manager->register('vendor.asset.first', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/consentmanager.js',
		)));
		self::assertNotSame('', $this->get_service('vendor.asset.first', $first_manager)['scripts'][0]['src']);

		$second_twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'getNamespaceLookUpOrder', 'findTemplate'))
			->getMock();
		$second_twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$second_twig_environment->method('getNamespaceLookUpOrder')
			->willReturn(['__main__']);
		$second_twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willReturn($this->phpbb_root_path . 'ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js');

		$second_manager = $this->get_manager([], '', null, null, $second_twig_environment);
		self::assertTrue($second_manager->register('vendor.asset.second', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/consentmanager.js',
		)));
		self::assertNotSame('', $this->get_service('vendor.asset.second', $second_manager)['scripts'][0]['src']);
	}

	public function test_register_does_not_persist_failed_asset_resolution_across_requests()
	{
		$failing_twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'getNamespaceLookUpOrder', 'findTemplate'))
			->getMock();
		$failing_twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$failing_twig_environment->method('getNamespaceLookUpOrder')
			->willReturn(['__main__']);
		$find_template_args = ['styles/prosilver/template/missing-consentmanager.js'];
		$failing_twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willThrowException(new \Twig\Error\LoaderError('Missing template asset'));

		$failing_manager = $this->get_manager([], '', null, null, $failing_twig_environment);
		self::assertTrue($failing_manager->register('vendor.asset.missing', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/missing-consentmanager.js',
		)));
		self::assertCount(0, $this->get_service('vendor.asset.missing', $failing_manager)['scripts']);

		$working_twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'getNamespaceLookUpOrder', 'findTemplate'))
			->getMock();
		$working_twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$working_twig_environment->method('getNamespaceLookUpOrder')
			->willReturn(['__main__']);
		$working_twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willReturn($this->phpbb_root_path . 'ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js');

		$working_manager = $this->get_manager([], '', null, null, $working_twig_environment);
		self::assertTrue($working_manager->register('vendor.asset.fixed', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/missing-consentmanager.js',
		)));
		self::assertNotSame('', $this->get_service('vendor.asset.fixed', $working_manager)['scripts'][0]['src']);
	}

	public function test_build_frontend_payload_collects_registered_and_configured_integrations()
	{
		$dispatcher = $this->get_collect_registrations_dispatcher(function ($data) {
			$data['consent_manager']->register('vendor.bundle', array(
				'category' => 'analytics',
				'label' => 'Vendor bundle',
				'scripts' => array(
					array('id' => 'vendor.bundle.loader', 'src' => 'https://cdn.example.com/analytics.js', 'category' => 'analytics'),
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
		self::assertSame(array('necessary', 'analytics', 'media'), $payload['enabledCategories']);
		self::assertSame(array('analytics', 'media'), $payload['optionalCategories']);
		self::assertSame(array(
			array(
				'id' => 'necessary',
				'required' => true,
				'enabled' => true,
			),
			array(
				'id' => 'analytics',
				'required' => false,
				'enabled' => true,
			),
			array(
				'id' => 'marketing',
				'required' => false,
				'enabled' => false,
			),
			array(
				'id' => 'media',
				'required' => false,
				'enabled' => true,
			),
		), $payload['categories']);
		self::assertSame('/app.php/consent/log', $payload['logEndpoint']);
		self::assertSame('deadbeef', $payload['logHash']);
		self::assertArrayNotHasKey('services', $payload);
		self::assertSame(array('vendor.bundle.loader', 'board.analytics'), array_column($payload['scripts'], 'id'));
	}

	public function test_get_frontend_template_data_returns_json_payload()
	{
		$manager = $this->get_manager();
		$data = $manager->get_frontend_template_data('/app.php/consent/log?x=<test>', 'abc123');
		$payload = json_decode($data['CONSENTMANAGER_PAYLOAD'], true);

		self::assertTrue($data['S_CONSENTMANAGER_ENABLED']);
		self::assertTrue($data['S_CONSENTMANAGER_ANALYTICS_ENABLED']);
		self::assertTrue($data['S_CONSENTMANAGER_MEDIA_ENABLED']);
		self::assertTrue($data['S_CONSENTMANAGER_MARKETING_ENABLED']);
		self::assertFalse($data['S_COOKIE_NOTICE']);
		self::assertSame('/app.php/consent/log?x=<test>', $payload['logEndpoint']);
		self::assertSame('abc123', $payload['logHash']);
		self::assertArrayNotHasKey('label', $payload['categories'][0]);
		self::assertArrayNotHasKey('description', $payload['categories'][0]);
		self::assertArrayNotHasKey('banner', $payload);
		self::assertArrayNotHasKey('services', $payload);
	}

	public function test_get_frontend_template_data_disables_frontend_without_optional_categories()
	{
		$manager = $this->get_manager(array(
			'consentmanager_analytics_enabled' => 0,
			'consentmanager_marketing_enabled' => 0,
			'consentmanager_media_enabled' => 0,
		));
		$data = $manager->get_frontend_template_data('/app.php/consent/log', 'abc123');

		self::assertFalse($data['S_CONSENTMANAGER_ENABLED']);
		self::assertFalse($data['S_CONSENTMANAGER_ANALYTICS_ENABLED']);
		self::assertFalse($data['S_CONSENTMANAGER_MARKETING_ENABLED']);
		self::assertFalse($data['S_CONSENTMANAGER_MEDIA_ENABLED']);
		self::assertSame('', $data['CONSENTMANAGER_PAYLOAD']);
		self::assertArrayNotHasKey('S_COOKIE_NOTICE', $data);
	}

	public function test_has_frontend_ui_returns_false_without_optional_categories()
	{
		$manager = $this->get_manager(array(
			'consentmanager_analytics_enabled' => 0,
			'consentmanager_marketing_enabled' => 0,
			'consentmanager_media_enabled' => 0,
		));

		self::assertFalse($manager->has_optional_categories());
	}

	public function test_get_frontend_category_data_groups_services_by_enabled_category()
	{
		$dispatcher = $this->get_collect_registrations_dispatcher(function ($data) {
			$data['consent_manager']->register('vendor.bundle', array(
				'category' => 'analytics',
				'label' => 'Vendor Analytics',
				'description' => 'Tracks analytics after consent.',
				'src' => 'https://cdn.example.com/analytics.js',
			));
			$data['consent_manager']->register('vendor.marketing', array(
				'category' => 'marketing',
				'label' => 'Vendor Marketing',
				'description' => 'Should be filtered because marketing is disabled.',
				'src' => 'https://cdn.example.com/marketing.js',
			));

			return $data;
		});

		$manager = $this->get_manager(array(
			'consentmanager_marketing_enabled' => 0,
		), $this->get_submitted_integrations_json(), $dispatcher);

		self::assertSame(array(
			array(
				'ID' => 'necessary',
				'LABEL' => $this->language->lang('CONSENTMANAGER_CATEGORY_NECESSARY'),
				'DESCRIPTION' => $this->language->lang('CONSENTMANAGER_CATEGORY_NECESSARY_EXPLAIN'),
				'REQUIRED' => true,
				'services' => array(),
			),
			array(
				'ID' => 'analytics',
				'LABEL' => $this->language->lang('CONSENTMANAGER_CATEGORY_ANALYTICS'),
				'DESCRIPTION' => $this->language->lang('CONSENTMANAGER_CATEGORY_ANALYTICS_EXPLAIN'),
				'REQUIRED' => false,
				'services' => array(
					array(
						'LABEL' => 'Vendor Analytics',
						'DESCRIPTION' => 'Tracks analytics after consent.',
					),
					array(
						'LABEL' => 'Board Analytics',
						'DESCRIPTION' => 'Loads a simple analytics library after consent.',
					),
				),
			),
			array(
				'ID' => 'media',
				'LABEL' => $this->language->lang('CONSENTMANAGER_CATEGORY_MEDIA'),
				'DESCRIPTION' => $this->language->lang('CONSENTMANAGER_CATEGORY_MEDIA_EXPLAIN'),
				'REQUIRED' => false,
				'services' => array(),
			),
		), $manager->get_frontend_category_data());
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
			array('necessary', 'analytics', 'media'),
			$manager->normalize_categories(array('analytics', 'necessary', 'media', 'marketing', 'analytics', 'unknown'))
		);
	}

	/**
	 * @dataProvider normalize_integrations_edge_case_data
	 */
	public function test_normalize_integrations_handles_edge_cases($input, array $expected_integrations, array $expected_error_specs)
	{
		$manager = $this->get_manager();
		$errors = array();

		self::assertSame($expected_integrations, $manager->normalize_integrations($input, $errors));
		self::assertSame($this->get_language_messages($expected_error_specs), $errors);
	}

	public function normalize_integrations_edge_case_data()
	{
		return array(
			'empty string' => array(
				'   ',
				array(),
				array(),
			),
			'empty array' => array(
				array(),
				array(),
				array(),
			),
			'scalar input' => array(
				1,
				array(),
				array(array('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS')),
			),
			'non-array item' => array(
				array('not-an-array'),
				array(),
				array(array('ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY', 1)),
			),
		);
	}

	public function test_normalize_integrations_rejects_top_level_object_json_string()
	{
		$manager = $this->get_manager();
		$errors = array();

		self::assertSame(array(), $manager->normalize_integrations($this->get_non_array_integrations_json(), $errors));
		self::assertSame(
			array($this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS')),
			$errors
		);
	}

	public function test_normalize_integrations_accepts_empty_json_array_string()
	{
		$manager = $this->get_manager();
		$errors = array();

		self::assertSame(array(), $manager->normalize_integrations(' [] ', $errors));
		self::assertSame(array(), $errors);
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

	public function test_has_server_consent_accepts_valid_cookie_and_normalizes_categories()
	{
		$request = $this->get_cookie_request(json_encode([
			'categories' => ['media', 'analytics', 'unknown'],
			'timestamp' => '2026-05-06T10:00:00.000Z',
			'version' => 3,
		]));
		$manager = $this->get_manager([
			'consentmanager_consent_version' => 3,
			'consentmanager_marketing_enabled' => 0,
		], '', null, null, null, $request);

		self::assertTrue($manager->has_server_consent('media'));
		self::assertTrue($manager->has_server_consent('analytics'));
		self::assertFalse($manager->has_server_consent('marketing'));
	}

	public function test_has_server_consent_returns_true_for_required_category_and_false_for_unsupported_category()
	{
		$manager = $this->get_manager();

		self::assertTrue($manager->has_server_consent('necessary'));
		self::assertFalse($manager->has_server_consent('unsupported'));
	}

	public function test_has_server_consent_reuses_cached_cookie_state()
	{
		$raw_variable_args = [
			\phpbb\consentmanager\service\consent_manager::COOKIE_NAME,
			'',
			\phpbb\request\request_interface::COOKIE,
		];
		$request = $this->createMock('\phpbb\request\request_interface');
		$request->expects(self::once())
			->method('raw_variable')
			->with(...$raw_variable_args)
			->willReturn(json_encode([
				'categories' => ['analytics'],
				'version' => 3,
			]));

		$manager = $this->get_manager([
			'consentmanager_consent_version' => 3,
		], '', null, null, null, $request);

		self::assertTrue($manager->has_server_consent('analytics'));
		self::assertFalse($manager->has_server_consent('marketing'));
		self::assertTrue($manager->has_server_consent('analytics'));
	}

	/**
	 * @dataProvider invalid_server_consent_cookie_data
	 */
	public function test_has_server_consent_rejects_invalid_cookie_state($cookie_value, array $config_values, $category)
	{
		$manager = $this->get_manager($config_values, '', null, null, null, $this->get_cookie_request($cookie_value));

		self::assertFalse($manager->has_server_consent($category));
	}

	public function invalid_server_consent_cookie_data()
	{
		return [
			'stale version' => [
				json_encode([
					'categories' => ['media'],
					'version' => 1,
				]),
				[
					'consentmanager_consent_version' => 2,
				],
				'media',
			],
			'invalid json' => [
				'{not json',
				[],
				'media',
			],
			'empty cookie' => [
				'',
				[],
				'media',
			],
			'invalid categories shape' => [
				json_encode([
					'categories' => 'analytics',
					'version' => 2,
				]),
				[
					'consentmanager_consent_version' => 2,
				],
				'analytics',
			],
		];
	}

	public function test_register_resolves_template_assets_via_twig_lookup()
	{
		$twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'findTemplate'))
			->getMock();
		$twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$find_template_args = ['styles/prosilver/template/consentmanager.js'];
		$twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willReturn($this->phpbb_root_path . 'ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js');

		$manager = $this->get_manager(array(), '', null, null, $twig_environment);

		self::assertTrue($manager->register('vendor.template', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/consentmanager.js',
		)));

		$script = $this->get_service('vendor.template', $manager)['scripts'][0];
		self::assertStringContainsString('ext/phpbb/consentmanager/styles/all/template/js/consentmanager.js', $script['src']);
	}

	public function test_register_discards_missing_template_assets()
	{
		$twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
			->disableOriginalConstructor()
			->setMethods(array('get_phpbb_root_path', 'findTemplate'))
			->getMock();
		$twig_environment->method('get_phpbb_root_path')
			->willReturn($this->phpbb_root_path);
		$find_template_args = ['styles/prosilver/template/missing-consentmanager.js'];
		$twig_environment->expects(self::once())
			->method('findTemplate')
			->with(...$find_template_args)
			->willThrowException(new \Twig\Error\LoaderError('missing template asset'));

		$manager = $this->get_manager(array(), '', null, null, $twig_environment);

		self::assertTrue($manager->register('vendor.missing-template', array(
			'category' => 'analytics',
			'asset' => 'styles/prosilver/template/missing-consentmanager.js',
		)));
		self::assertSame(array(), $this->get_service('vendor.missing-template', $manager)['scripts']);
	}

	protected function get_manager(array $config_values = array(), $stored_integrations = '', $dispatcher = null, $config_text = null, $twig_environment = null, $request = null, $language = null, $consent_cache = null)
	{
		return new \phpbb\consentmanager\service\consent_manager(...$this->get_manager_constructor_args(
			$config_values,
			$config_text ?: $this->get_config_text($stored_integrations),
			$dispatcher,
			$twig_environment,
			$request,
			$language,
			$consent_cache
		));
	}

	protected function get_manager_constructor_args(array $config_values = array(), $config_text = null, $dispatcher = null, $twig_environment = null, $request = null, $language = null, $consent_cache = null)
	{
		if ($dispatcher === null)
		{
			$dispatcher = new \phpbb_mock_event_dispatcher();
		}

		$config = new \phpbb\config\config(array_merge(array(
			'consentmanager_analytics_enabled' => 1,
			'consentmanager_marketing_enabled' => 1,
			'consentmanager_media_enabled' => 1,
			'consentmanager_consent_version' => 1,
			'assets_version' => '42',
			'rand_seed' => 'seed',
		), $config_values));

		if ($twig_environment === null)
		{
			$twig_environment = $this->getMockBuilder('\phpbb\template\twig\environment')
				->disableOriginalConstructor()
				->setMethods(array('get_phpbb_root_path', 'getNamespaceLookUpOrder', 'findTemplate'))
				->getMock();
			$twig_environment->method('get_phpbb_root_path')
				->willReturn($this->phpbb_root_path);
			$twig_environment->method('getNamespaceLookUpOrder')
				->willReturn(['__main__']);
		}

		if ($request === null)
		{
			$request = $this->get_cookie_request('');
		}

		if ($language === null)
		{
			$language = $this->language;
		}

		if ($consent_cache === null)
		{
			$cache_store = [];
			$consent_cache = $this->get_consent_cache($cache_store);
		}

		return [
			$consent_cache,
			$config,
			$config_text,
			$language,
			$dispatcher,
			$twig_environment,
			$this->path_helper,
			$this->filesystem,
			$request,
		];
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

	protected function get_cookie_request($cookie_value)
	{
		$request = $this->createMock('\phpbb\request\request_interface');
		$request->method('raw_variable')
			->willReturnCallback(function ($name, $default, $super_global = null) use ($cookie_value) {
				if ($name === \phpbb\consentmanager\service\consent_manager::COOKIE_NAME && $super_global === \phpbb\request\request_interface::COOKIE)
				{
					return $cookie_value;
				}

				return $default;
			});

		return $request;
	}

	protected function get_consent_cache(array &$cache_store = [])
	{
		return new \phpbb\consentmanager\service\consent_cache($this->get_cache_service($cache_store));
	}

	protected function get_cache_service(array &$cache_store = [])
	{
		$driver = $this->createMock('\phpbb\cache\driver\driver_interface');
		$driver->method('get')
			->willReturnCallback(function ($key) use (&$cache_store) {
				return array_key_exists($key, $cache_store) ? $cache_store[$key] : false;
			});
		$driver->method('put')
			->willReturnCallback(function ($key, $value) use (&$cache_store) {
				$cache_store[$key] = $value;
				return true;
			});
		$driver->method('destroy')
			->willReturnCallback(function ($key) use (&$cache_store) {
				unset($cache_store[$key]);
				return true;
			});

		return new \phpbb\cache\service(
			$driver,
			new \phpbb\config\config([]),
			$this->createMock('\phpbb\db\driver\driver_interface'),
			new \phpbb_mock_event_dispatcher(),
			'',
			'php'
		);
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
		$trigger_event_args = [
			'phpbb.consentmanager.collect_registrations',
			$this->callback(function ($vars) {
				return isset($vars['consent_manager'])
					&& $vars['consent_manager'] instanceof \phpbb\consentmanager\service\consent_manager;
			}),
		];
		$dispatcher->expects(self::once())
			->method('trigger_event')
			->with(...$trigger_event_args)
			->willReturnCallback(function ($event_name, $data = array()) use ($callback) {
				return $callback($data);
			});

		return $dispatcher;
	}

	protected function get_submitted_integrations_json()
	{
		return '[{"id":"board.analytics","category":"analytics","label":"Board Analytics","description":"Loads a simple analytics library after consent.","src":"https://cdn.example.com/analytics.js","async":true}]';
	}

	protected function get_language_messages(array $message_specs)
	{
		return array_map(function ($message_spec) {
			$key = array_shift($message_spec);
			return call_user_func_array(array($this->language, 'lang'), array_merge(array($key), $message_spec));
		}, $message_specs);
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
