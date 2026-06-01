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

class translation_manager_test extends \phpbb_database_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	public static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	protected function setUp(): void
	{
		parent::setUp();

		global $auth, $cache, $config, $db, $phpbb_container, $phpbb_dispatcher, $phpbb_root_path, $phpEx, $user;

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
		$this->language->set_user_language('en');
		$this->language->add_lang('common', 'phpbb/consentmanager');
		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');

		$db = $this->db = $this->new_dbal();
		$this->db->sql_query('DELETE FROM phpbb_consentmanager_translations');
		$this->db->sql_query('DELETE FROM ' . LANG_TABLE);
		$this->insert_language('en', 'British English');
		$this->insert_language('de', 'German');

		$config = new \phpbb\config\config(['allow_nocensors' => false]);
		set_config(null, null, null, $config);

		$cache = new \phpbb_mock_cache();
		$phpbb_container = new \phpbb_mock_container_builder();
		$phpbb_container->set('config', $config);
		$this->get_test_case_helpers()->set_s9e_services($phpbb_container);

		$phpbb_dispatcher = new \phpbb_mock_event_dispatcher();
		$auth = $this->createMock('\phpbb\auth\auth');
		$auth->method('acl_get')->willReturn(false);
		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data['user_options'] = 230271;
	}

	public function getDataSet()
	{
		return new \PHPUnit\DbUnit\DataSet\DefaultDataSet();
	}

	public function test_no_translations_fall_back()
	{
		$manager = $this->create_manager();

		self::assertSame($this->language->lang('CONSENTMANAGER_DEFAULT_BANNER_TEXT'), $manager->get_translation('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT'));

		$display = $manager->get_translation_for_display('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT');
		self::assertStringContainsString($this->language->lang('CONSENTMANAGER_DEFAULT_BANNER_TEXT'), $display);
	}

	public function test_custom_translation_overrides_language_default_and_renders_bbcode()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertTrue($manager->save_translations([
			'en' => [
				'banner_message' => 'Custom [b]English[/b] message',
			],
		], ['banner_message'], $errors));
		self::assertSame([], $errors);

		self::assertSame('Custom [b]English[/b] message', $manager->get_translation('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT', 'en'));

		$display = $manager->get_translation_for_display('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT', 'en');
		self::assertStringContainsString('Custom', $display);
		self::assertStringContainsString('English', $display);
		self::assertStringNotContainsString('[b]', $display);
	}

	public function test_blank_translation_deletes_custom_value_and_restores_fallback()
	{
		$manager = $this->create_manager();
		$errors = [];

		$manager->save_translations([
			'en' => [
				'banner_title' => 'Custom title',
			],
		], ['banner_title'], $errors);
		$manager->save_translations([
			'en' => [
				'banner_title' => '',
			],
		], ['banner_title'], $errors);

		self::assertSame(
			$this->language->lang('CONSENTMANAGER_DEFAULT_BANNER_TITLE'),
			$manager->get_translation('banner_title', 'CONSENTMANAGER_DEFAULT_BANNER_TITLE', 'en')
		);
		$this->assertSqlResultEquals([], 'SELECT translation_key FROM phpbb_consentmanager_translations');
	}

	public function test_banner_template_data_contains_all_installed_languages_and_current_values()
	{
		$manager = $this->create_manager();
		$errors = [];
		$manager->save_translations([
			'de' => [
				'banner_title' => 'Eigener Titel',
			],
		], ['banner_title'], $errors);

		$data = $manager->get_banner_template_data();

		self::assertSame(['banner_title', 'banner_message', 'banner_subtext'], array_column($data['CONSENTMANAGER_BANNER_FIELDS'], 'KEY'));
		self::assertSame(['en', 'de'], array_column($data['CONSENTMANAGER_BANNER_LANGUAGES'], 'ISO'));
		self::assertSame('Eigener Titel', $data['CONSENTMANAGER_BANNER_LANGUAGES'][1]['TRANSLATIONS'][0]['VALUE']);
		self::assertSame(
			$this->language->lang('CONSENTMANAGER_DEFAULT_BANNER_TEXT'),
			$data['CONSENTMANAGER_BANNER_LANGUAGES'][1]['TRANSLATIONS'][1]['VALUE']
		);
	}

	public function test_banner_template_data_prefers_submitted_values_after_errors()
	{
		$manager = $this->create_manager();
		$submitted = [
			'en' => [
				'banner_title' => 'Submitted title',
			],
		];

		$data = $manager->get_banner_template_data($submitted);

		self::assertSame('Submitted title', $data['CONSENTMANAGER_BANNER_LANGUAGES'][0]['TRANSLATIONS'][0]['VALUE']);
		self::assertSame('', $data['CONSENTMANAGER_BANNER_LANGUAGES'][0]['TRANSLATIONS'][1]['VALUE']);
	}

	public function test_four_byte_emoji_is_encoded_for_database_storage_and_decoded_for_editing()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertTrue($manager->save_translations([
			'de' => [
				'banner_title' => 'We value your privacy 🐳',
			],
		], ['banner_title'], $errors));
		self::assertSame([], $errors);

		self::assertSame('We value your privacy 🐳', $manager->get_translation('banner_title', 'CONSENTMANAGER_DEFAULT_BANNER_TITLE', 'de'));
		$this->assertSqlResultEquals(
			[['translation_text' => 'We value your privacy &#128051;']],
			"SELECT translation_text FROM phpbb_consentmanager_translations WHERE translation_key = 'banner_title' AND lang_iso = 'de'"
		);
	}

	public function test_existing_translation_is_updated()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertTrue($manager->save_translations([
			'en' => [
				'banner_title' => 'First title',
			],
		], ['banner_title'], $errors));
		self::assertTrue($manager->save_translations([
			'en' => [
				'banner_title' => 'Second title',
			],
		], ['banner_title'], $errors));

		self::assertSame('Second title', $manager->get_translation('banner_title', 'CONSENTMANAGER_DEFAULT_BANNER_TITLE', 'en'));
		$this->assertSqlResultEquals(
			[['translation_text' => 'Second title']],
			"SELECT translation_text FROM phpbb_consentmanager_translations WHERE translation_key = 'banner_title' AND lang_iso = 'en'"
		);
	}

	public function test_rejects_oversized_translation_text()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertFalse($manager->save_translations([
			'en' => [
				'banner_message' => str_repeat('a', \phpbb\consentmanager\service\translation_manager::MAX_TRANSLATION_LENGTH + 1),
			],
		], ['banner_message'], $errors));

		self::assertNotEmpty($errors);
		$this->assertSqlResultEquals([], 'SELECT translation_key FROM phpbb_consentmanager_translations');
	}

	public function test_parser_errors_abort_save()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertFalse($manager->save_translations([
			'en' => [
				'banner_message' => '[img]https://example.com/image.png[/img]',
			],
		], ['banner_message'], $errors));

		self::assertNotEmpty($errors);
		$this->assertSqlResultEquals([], 'SELECT translation_key FROM phpbb_consentmanager_translations');
	}

	public function test_accepts_translation_text_at_maximum_length()
	{
		$manager = $this->create_manager();
		$errors = [];
		$text = str_repeat('a', \phpbb\consentmanager\service\translation_manager::MAX_TRANSLATION_LENGTH);

		self::assertTrue($manager->save_translations([
			'en' => [
				'banner_message' => $text,
			],
		], ['banner_message'], $errors));

		self::assertSame([], $errors);
		self::assertSame($text, $manager->get_translation('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT', 'en'));
	}

	public function test_ignores_allowed_keys_outside_banner_field_definitions()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertTrue($manager->save_translations([
			'en' => [
				'unexpected_key' => 'Unexpected',
			],
		], ['unexpected_key'], $errors));

		self::assertSame([], $errors);
		$this->assertSqlResultEquals([], 'SELECT translation_key FROM phpbb_consentmanager_translations');
	}

	public function test_ignores_unallowed_language_iso()
	{
		$manager = $this->create_manager();
		$errors = [];

		self::assertTrue($manager->save_translations([
			'foo' => [
				'banner_message' => 'Not installed language',
			],
		], ['banner_message'], $errors));

		self::assertSame([], $errors);
		$this->assertSqlResultEquals([], 'SELECT translation_key FROM phpbb_consentmanager_translations');
	}

	public function test_custom_translations_are_cached_between_manager_instances()
	{
		$cache_store = [];
		$manager = $this->create_manager($cache_store);
		$errors = [];

		$manager->save_translations([
			'en' => [
				'banner_message' => 'Cached message',
			],
		], ['banner_message'], $errors);
		self::assertSame('Cached message', $manager->get_translation('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT', 'en'));

		$this->db->sql_query('DELETE FROM phpbb_consentmanager_translations');

		$cached_manager = $this->create_manager($cache_store);
		self::assertSame('Cached message', $cached_manager->get_translation('banner_message', 'CONSENTMANAGER_DEFAULT_BANNER_TEXT', 'en'));
	}

	public function test_cached_translation_text_is_decoded_for_editing()
	{
		$cache_store = [
			\phpbb\consentmanager\service\consent_cache::TRANSLATIONS_CACHE_KEY => [
				'banner_title' => [
					'en' => [
						'translation_text' => 'We value your privacy &#128051;',
						'translation_text_parsed' => '',
						'translation_uid' => '',
						'translation_bitfield' => '',
						'translation_options' => 0,
					],
				],
			],
		];
		$manager = $this->create_manager($cache_store);

		self::assertSame('We value your privacy 🐳', $manager->get_translation('banner_title', 'CONSENTMANAGER_DEFAULT_BANNER_TITLE', 'en'));
	}

	protected function create_manager(array &$cache_store = [])
	{
		global $phpbb_root_path, $phpEx;

		return new \phpbb\consentmanager\service\translation_manager(
			$this->db,
			new \phpbb\consentmanager\service\consent_cache($this->get_cache_service($cache_store)),
			$this->language,
			$phpbb_root_path,
			$phpEx,
			'phpbb_consentmanager_translations'
		);
	}

	protected function insert_language($iso, $local_name)
	{
		$this->db->sql_query('INSERT INTO ' . LANG_TABLE . ' ' . $this->db->sql_build_array('INSERT', [
			'lang_iso' => $iso,
			'lang_dir' => $iso,
			'lang_english_name' => $local_name,
			'lang_local_name' => $local_name,
			'lang_author' => 'phpBB',
		]));
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
}
