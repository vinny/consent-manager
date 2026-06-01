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

class acp_manager_test extends \phpbb_database_test_case
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

		global $db, $phpbb_root_path, $phpEx;

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
		$this->language->add_lang('common', 'phpbb/consentmanager');
		$this->language->add_lang('acp_consentmanager', 'phpbb/consentmanager');

		$db = $this->db = $this->new_dbal();
		$this->db->sql_query('DELETE FROM phpbb_consentmanager_logs');
		$this->db->sql_query("DELETE FROM phpbb_users WHERE username_clean = 'lookupuser'");
	}

	public function getDataSet()
	{
		return new \PHPUnit\DbUnit\DataSet\DefaultDataSet();
	}

	/**
	 * @dataProvider log_admin_action_data
	 */
	public function test_log_admin_action_delegates_to_phpbb_log($log_action)
	{
		$log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->setMethods(array('add'))
			->getMock();
		$log->expects(self::once())
			->method('add')
			->with('admin', 7, '127.0.0.1', $log_action);

		$this->create_manager(7, 'admin-session', $log)->log_admin_action($log_action);
	}

	public function log_admin_action_data()
	{
		return [
			['LOG_CONSENTMANAGER_UPDATED'],
			['LOG_CONSENTMANAGER_REPROMPT'],
			['LOG_CONSENTMANAGER_EXPORT'],
			['LOG_CONSENTMANAGER_DELETE'],
		];
	}

	public function test_hash_user_id_returns_hmac_of_user_prefix()
	{
		$manager = $this->create_manager(1, 'session');
		$expected = hash_hmac('sha256', 'u:42', 'random-seed');

		self::assertSame($expected, $manager->hash_user_id(42));
	}

	public function test_hash_user_id_is_consistent()
	{
		$manager = $this->create_manager(1, 'session');

		self::assertSame($manager->hash_user_id(99), $manager->hash_user_id(99));
		self::assertNotSame($manager->hash_user_id(1), $manager->hash_user_id(2));
	}

	public function test_get_user_id_by_username_returns_matching_user_id()
	{
		$sql = 'INSERT INTO ' . USERS_TABLE . ' ' . $this->db->sql_build_array('INSERT', [
			'user_type'			=> USER_NORMAL,
			'group_id'			=> 2,
			'username'			=> 'LookupUser',
			'username_clean'	=> 'lookupuser',
			'user_permissions'	=> '',
			'user_email'		=> 'lookup@example.com',
			'user_lang'			=> 'en',
			'user_style'		=> 1,
			'user_sig'			=> '',
		]);
		$this->db->sql_query($sql);
		$user_id = (int) $this->db->sql_last_inserted_id();

		$manager = $this->create_manager(1, 'session');

		self::assertSame($user_id, $manager->get_user_id_by_username('LookupUser'));
	}

	public function test_get_user_id_by_username_returns_false_when_user_is_missing()
	{
		$manager = $this->create_manager(1, 'session');

		self::assertFalse($manager->get_user_id_by_username('MissingUser'));
		self::assertFalse($manager->get_user_id_by_username(''));
	}

	public function test_get_settings_template_data_pretty_prints_stored_integrations()
	{
		$manager = $this->create_manager(1, 'session', null, $this->get_config_text($this->get_submitted_integrations_json()));
		$template_data = $manager->get_settings_template_data();

		self::assertSame($this->get_pretty_integrations_json(), $template_data['CONSENTMANAGER_INTEGRATIONS']);
		self::assertSame($this->get_example_integrations_json(), $template_data['CONSENTMANAGER_INTEGRATIONS_EXAMPLE']);
		self::assertSame(1, $template_data['CONSENTMANAGER_VERSION']);
	}

	public function test_get_settings_template_data_returns_empty_integrations_when_none_are_stored()
	{
		$manager = $this->create_manager(1, 'session');
		$template_data = $manager->get_settings_template_data();

		self::assertSame('', $template_data['CONSENTMANAGER_INTEGRATIONS']);
		self::assertSame($this->get_example_integrations_json(), $template_data['CONSENTMANAGER_INTEGRATIONS_EXAMPLE']);
	}

	public function test_get_settings_template_data_keeps_invalid_json_verbatim()
	{
		$manager = $this->create_manager(1, 'session', null, $this->get_config_text('{not json'));
		$template_data = $manager->get_settings_template_data();

		self::assertSame('{not json', $template_data['CONSENTMANAGER_INTEGRATIONS']);
	}

	public function test_normalize_integrations_json_returns_trimmed_json_string()
	{
		$submitted_json = "\n" . $this->get_pretty_integrations_json() . "\n";
		$trimmed_json = trim($submitted_json);
		$normalize_args = [$trimmed_json, self::anything()];
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('normalize_integrations')
			->with(...$normalize_args);

		$manager = $this->create_manager(1, 'session', null, null, $consent_manager);
		$errors = [];

		self::assertSame($trimmed_json, $this->invoke_method($manager, 'normalize_integrations_json', [$submitted_json, &$errors]));
		self::assertSame([], $errors);
	}

	public function test_normalize_integrations_json_returns_empty_string_for_blank_input()
	{
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::never())
			->method('normalize_integrations');

		$manager = $this->create_manager(1, 'session', null, null, $consent_manager);
		$errors = [];

		self::assertSame('', $this->invoke_method($manager, 'normalize_integrations_json', [" \n\t ", &$errors]));
		self::assertSame([], $errors);
	}

	public function test_normalize_integrations_json_reports_encoding_failure_for_array_input()
	{
		$integrations = [[
			'id' => 'board.analytics',
			'category' => 'analytics',
			'label' => "\xB1\x31",
			'src' => 'https://cdn.example.com/analytics.js',
		]];
		$normalize_args = [$integrations, self::anything()];
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('normalize_integrations')
			->with(...$normalize_args);

		$manager = $this->create_manager(1, 'session', null, null, $consent_manager);
		$errors = [];

		self::assertSame('', $this->invoke_method($manager, 'normalize_integrations_json', [$integrations, &$errors]));
		self::assertSame([$this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS')], $errors);
	}

	public function test_save_settings_updates_flags_and_integrations()
	{
		[$config_text, $consent_manager, $consent_cache, $text_formatter_cache] = $this->create_save_settings_mocks(
			trim($this->get_pretty_integrations_json()),
			self::once(),
			self::never()
		);
		$consent_manager->expects(self::once())
			->method('normalize_integrations')
			->with(trim($this->get_pretty_integrations_json()), self::anything());

		$manager = $this->create_manager(1, 'session', null, $config_text, $consent_manager, $consent_cache, $text_formatter_cache);
		$errors = [];

		self::assertTrue($manager->save_settings([
			'analytics_enabled' => 0,
			'marketing_enabled' => 1,
			'media_enabled' => 1,
			'integrations' => $this->get_pretty_integrations_json(),
		], $errors));
		self::assertSame([], $errors);
		$template_data = $manager->get_settings_template_data();
		self::assertFalse($template_data['S_CONSENTMANAGER_ANALYTICS']);
		self::assertTrue($template_data['S_CONSENTMANAGER_MEDIA']);
		self::assertTrue($template_data['S_CONSENTMANAGER_MARKETING']);
	}

	public function test_save_settings_invalidates_text_formatter_cache_when_media_setting_changes()
	{
		[$config_text, $consent_manager, $consent_cache, $text_formatter_cache] = $this->create_save_settings_mocks(
			'',
			self::once(),
			self::once()
		);
		$consent_manager->expects(self::never())
			->method('normalize_integrations');

		$manager = $this->create_manager(1, 'session', null, $config_text, $consent_manager, $consent_cache, $text_formatter_cache, [
			'consentmanager_media_enabled' => 1,
		]);

		self::assertTrue($manager->save_settings([
			'analytics_enabled' => 1,
			'marketing_enabled' => 1,
			'media_enabled' => 0,
			'integrations' => '',
		]));
	}

	public function test_save_settings_does_not_invalidate_text_formatter_cache_when_media_setting_is_unchanged()
	{
		[$config_text, $consent_manager, $consent_cache, $text_formatter_cache] = $this->create_save_settings_mocks(
			'',
			self::once(),
			self::never()
		);
		$consent_manager->expects(self::never())
			->method('normalize_integrations');

		$manager = $this->create_manager(1, 'session', null, $config_text, $consent_manager, $consent_cache, $text_formatter_cache, [
			'consentmanager_media_enabled' => 1,
		]);

		self::assertTrue($manager->save_settings([
			'analytics_enabled' => 0,
			'marketing_enabled' => 0,
			'media_enabled' => 1,
			'integrations' => '',
		]));
	}

	public function test_save_settings_accepts_array_integrations()
	{
		$integrations = [
			[
				'id' => 'board.analytics',
				'category' => 'analytics',
				'label' => 'Board Analytics',
				'description' => 'Loads a simple analytics library after consent.',
				'src' => 'https://cdn.example.com/analytics.js',
				'async' => true,
			],
		];

		[$config_text, , $consent_cache, $text_formatter_cache] = $this->create_save_settings_mocks(
			json_encode($integrations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('normalize_integrations')
			->with($integrations, self::anything())
			->willReturn([]);

		$manager = $this->create_manager(1, 'session', null, $config_text, $consent_manager, $consent_cache, $text_formatter_cache);
		$errors = [];

		self::assertTrue($manager->save_settings([
			'analytics_enabled' => 1,
			'marketing_enabled' => 1,
			'media_enabled' => 1,
			'integrations' => $integrations,
		], $errors));
		self::assertSame([], $errors);
	}

	/**
	 * @dataProvider invalid_integrations_data
	 */
	public function test_save_settings_rejects_invalid_integrations($json)
	{
		[$config_text, , $consent_cache] = $this->create_save_settings_mocks(null, self::never());
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('normalize_integrations')
			->willReturnCallback(function ($input, array &$errors) {
				$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
				return [];
			});

		$manager = $this->create_manager(1, 'session', null, $config_text, $consent_manager, $consent_cache);
		$errors = [];

		self::assertFalse($manager->save_settings([
			'analytics_enabled' => 1,
			'marketing_enabled' => 1,
			'media_enabled' => 1,
			'integrations' => $json,
		], $errors));
		self::assertSame([$this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS')], $errors);
	}

	public function invalid_integrations_data()
	{
		return [
			'malformed json' => ['{not json'],
			'top level object' => ['{"id":"board.analytics"}'],
		];
	}

	/**
	 * @dataProvider invalid_array_integrations_data
	 */
	public function test_save_settings_rejects_invalid_array_integrations($integrations, array $expected_error_specs)
	{
		[$config_text, , $consent_cache] = $this->create_save_settings_mocks(null, self::never());

		$expected_errors = $this->get_language_messages($expected_error_specs);
		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('normalize_integrations')
			->willReturnCallback(function ($input, array &$errors) use ($expected_errors) {
				$errors = $expected_errors;
				return [];
			});

		$manager = $this->create_manager(1, 'session', null, $config_text, $consent_manager, $consent_cache);
		$errors = [];

		self::assertFalse($manager->save_settings([
			'analytics_enabled' => 1,
			'marketing_enabled' => 1,
			'media_enabled' => 1,
			'integrations' => $integrations,
		], $errors));
		self::assertSame($expected_errors, $errors);
	}

	public function invalid_array_integrations_data()
	{
		return [
			'invalid entry' => [
				['not-an-array'],
				[['ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY', 1]],
			],
			'encoding failure' => [
				[[
					'id' => 'board.analytics',
					'category' => 'analytics',
					'label' => "\xB1\x31",
					'src' => 'https://cdn.example.com/analytics.js',
				]],
				[['ACP_CONSENTMANAGER_INVALID_INTEGRATIONS']],
			],
		];
	}

	public function test_reset_consent_version_increments_config_value()
	{
		$manager = $this->create_manager(1, 'session', null, null, null, null, null, [
			'consentmanager_consent_version' => 7,
		]);
		$manager->reset_consent_version();

		self::assertSame(8, $manager->get_settings_template_data()['CONSENTMANAGER_VERSION']);
	}

	public function test_stream_logs_csv_empty_table_writes_no_rows()
	{
		$manager = $this->create_manager(1, 'session');

		$handle = fopen('php://memory', 'wb+');
		$manager->stream_logs_csv($handle);
		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		self::assertSame('', $content);
	}

	public function test_stream_logs_csv_writes_all_rows_unfiltered()
	{
		$log_manager_a = $this->create_log_manager(10, 'session-a');
		$log_manager_a->log_consent(array('necessary', 'analytics'), 2);

		$log_manager_b = $this->create_log_manager(20, 'session-b');
		$log_manager_b->log_consent(array('necessary'), 2);

		$handle = fopen('php://memory', 'wb+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle);
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(2, $rows);
	}

	public function test_stream_logs_csv_filters_by_consent_version()
	{
		$log_manager = $this->create_log_manager(10, 'session');
		$log_manager->log_consent(array('necessary'), 1);
		$log_manager->log_consent(array('necessary', 'analytics'), 2);
		$log_manager->log_consent(array('necessary'), 1);

		$handle = fopen('php://memory', 'wb+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, array('consent_version' => 1));
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(2, $rows);
		foreach ($rows as $row)
		{
			self::assertStringContainsString(',1,', $row);
		}
	}

	public function test_stream_logs_csv_filters_by_date_range()
	{
		$now  = time();
		$past = $now - 7200; // 2 hours ago

		$this->db->sql_query('INSERT INTO phpbb_consentmanager_logs
			(anonymized_id, consent_version, accepted_categories, consent_time)
			VALUES
			(\'' . $this->db->sql_escape('hash-old') . '\', 1, \'["necessary"]\', ' . $past . '),
			(\'' . $this->db->sql_escape('hash-new') . '\', 1, \'["necessary","analytics"]\', ' . $now . ')');

		$handle = fopen('php://memory', 'wb+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, array(
			'date_from' => $now - 3600, // 1 hour ago
			'date_to'   => $now + 3600,
		));
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(1, $rows);
		self::assertStringContainsString('hash-new', reset($rows));
	}

	public function test_stream_logs_csv_filters_by_user_id()
	{
		$manager_target = $this->create_log_manager(42, 'any-session');
		$manager_target->log_consent(array('necessary'), 1);

		$manager_other = $this->create_log_manager(99, 'other-session');
		$manager_other->log_consent(array('necessary', 'analytics'), 1);

		$reader = $this->create_manager(1, 'session');

		$handle = fopen('php://memory', 'wb+');
		$reader->stream_logs_csv($handle, array('user_id' => 42));
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(1, $rows);

		$expected_hash = hash_hmac('sha256', 'u:42', 'random-seed');
		self::assertStringContainsString($expected_hash, reset($rows));
	}

	public function test_stream_logs_csv_row_format_is_correct()
	{
		$log_manager = $this->create_log_manager(5, 'session');
		$log_manager->log_consent(array('necessary', 'analytics'), 3);

		$handle = fopen('php://memory', 'wb+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle);
		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$row = str_getcsv(trim($content), ',', '"', '\\');
		self::assertCount(4, $row);

		// anonymized_id: 64-char hex
		self::assertRegExp('/^[0-9a-f]{64}$/', $row[0]);

		// timestamp: ISO 8601 UTC
		self::assertRegExp('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $row[1]);

		// consent_version
		self::assertSame('3', $row[2]);

		// categories as comma-separated string
		self::assertSame('necessary,analytics', $row[3]);
	}

	public function test_stream_logs_csv_batch_pagination_retrieves_all_rows()
	{
		$log_manager = $this->create_log_manager(10, 'session');

		for ($i = 0; $i < 5; $i++)
		{
			$log_manager->log_consent(array('necessary'), 1);
		}

		$handle = fopen('php://memory', 'wb+');
		// Use a batch size of 2 to exercise the pagination loop
		$this->create_manager(1, 'session')->stream_logs_csv($handle, [], 2);
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(5, $rows);
	}

	public function test_stream_logs_csv_sanitizes_formula_injection_in_categories()
	{
		// Insert a row whose accepted_categories begins with '=' — a formula injection attempt
		$this->db->sql_query('INSERT INTO phpbb_consentmanager_logs
			(anonymized_id, consent_version, accepted_categories, consent_time)
			VALUES (\'hash-x\', 1, \'["=DANGEROUS()"]\', ' . time() . ')');

		$handle = fopen('php://memory', 'wb+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle);
		rewind($handle);
		/** @noinspection PhpRedundantOptionalArgumentInspection */
		$row = str_getcsv(trim(stream_get_contents($handle)), ',', '"', '\\');
		fclose($handle);

		// category cell must be prefixed with a tab to defuse the formula
		self::assertStringStartsWith("\t", $row[3]);
		self::assertStringContainsString('=DANGEROUS()', $row[3]);
	}

	public function test_delete_logs_deletes_all_rows_when_no_filters_are_provided()
	{
		$log_manager_a = $this->create_log_manager(10, 'session-a');
		$log_manager_a->log_consent(array('necessary', 'analytics'), 2);

		$log_manager_b = $this->create_log_manager(20, 'session-b');
		$log_manager_b->log_consent(array('necessary'), 3);

		$manager = $this->create_manager(1, 'session');

		self::assertSame(2, $manager->delete_logs());

		$handle = fopen('php://memory', 'wb+');
		$manager->stream_logs_csv($handle);
		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		self::assertSame('', $content);
	}

	public function test_delete_logs_filters_by_user_id()
	{
		$manager_target = $this->create_log_manager(42, 'any-session');
		$manager_target->log_consent(array('necessary'), 1);

		$manager_other = $this->create_log_manager(99, 'other-session');
		$manager_other->log_consent(array('necessary', 'analytics'), 1);

		$manager = $this->create_manager(1, 'session');

		self::assertSame(1, $manager->delete_logs(array('user_id' => 42)));

		$handle = fopen('php://memory', 'wb+');
		$manager->stream_logs_csv($handle);
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(1, $rows);

		$remaining_hash = hash_hmac('sha256', 'u:99', 'random-seed');
		self::assertStringContainsString($remaining_hash, reset($rows));
	}

	public function test_parse_date_filter_returns_false_for_empty_string()
	{
		$manager = $this->create_manager(1, 'session');
		self::assertFalse($manager->parse_date_filter(''));
	}

	public function test_parse_date_filter_returns_false_for_invalid_date()
	{
		$manager = $this->create_manager(1, 'session');
		self::assertFalse($manager->parse_date_filter('not-a-date'));
		self::assertFalse($manager->parse_date_filter('2024-13-01'));
		self::assertFalse($manager->parse_date_filter('2024-02-31'));
	}

	public function test_parse_date_filter_returns_start_of_day_timestamp()
	{
		$manager = $this->create_manager(1, 'session');
		$ts = $manager->parse_date_filter('2024-06-15');
		self::assertSame(
			\DateTimeImmutable::createFromFormat('!Y-m-d', '2024-06-15', new \DateTimeZone('UTC'))->getTimestamp(),
			$ts
		);
	}

	public function test_parse_date_filter_returns_end_of_day_timestamp_when_flag_set()
	{
		$manager  = $this->create_manager(1, 'session');
		$start    = $manager->parse_date_filter('2024-06-15');
		$end      = $manager->parse_date_filter('2024-06-15', true);
		self::assertSame(86399, $end - $start); // 23h 59m 59s difference
	}

	protected function create_manager($user_id, $session_id, $log = null, $config_text = null, $consent_manager = null, $consent_cache = null, $text_formatter_cache = null, array $config_values = [])
	{
		global $phpbb_root_path, $phpEx;

		$config = new \phpbb\config\config(array_merge(array(
			'rand_seed' => 'random-seed',
			'consentmanager_analytics_enabled' => 1,
			'consentmanager_marketing_enabled' => 1,
			'consentmanager_media_enabled' => 1,
			'consentmanager_consent_version' => 1,
		), $config_values));
		if ($log === null)
		{
			$log = $this->getMockBuilder('\phpbb\log\log')
				->disableOriginalConstructor()
				->getMock();
		}

		if ($config_text === null)
		{
			$config_text = $this->get_config_text();
		}

		if ($consent_manager === null)
		{
			$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
			$consent_manager->method('normalize_integrations')
				->willReturnCallback(function ($input, array &$errors) {
					return [];
				});
		}

		if ($text_formatter_cache === null)
		{
			$text_formatter_cache = $this->createMock('\phpbb\textformatter\cache_interface');
		}

		if ($consent_cache === null)
		{
			$consent_cache = $this->createMock('\phpbb\consentmanager\service\consent_cache');
		}

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = array(
			'user_id' => $user_id,
		);
		$user->session_id = $session_id;
		$user->ip = '127.0.0.1';

		return new \phpbb\consentmanager\service\acp_manager(
			$config,
			$this->db,
			$config_text,
			$this->language,
			$log,
			$consent_manager,
			$consent_cache,
			$text_formatter_cache,
			$user,
			$phpbb_root_path,
			$phpEx,
			'phpbb_consentmanager_logs'
		);
	}

	/**
	 * Creates the four mocks common to all save_settings tests.
	 *
	 * @param string|null $expected_stored_value  Value expected to be passed to config_text->set(), or null to expect never.
	 * @param mixed       $cache_invocation       Invocation rule for consent_cache->invalidate_integrations() (default: once).
	 * @param mixed       $text_formatter_invocation Invocation rule for text_formatter_cache->invalidate_integrations() (default: never).
	 */
	protected function create_save_settings_mocks($expected_stored_value, $cache_invocation = null, $text_formatter_invocation = null)
	{
		$config_text = $this->createMock('\phpbb\config\db_text');
		if ($expected_stored_value === null)
		{
			$config_text->expects(self::never())->method('set');
		}
		else
		{
			$config_text->expects(self::once())->method('set')
				->with('consentmanager_integrations', $expected_stored_value);
		}

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->method('normalize_integrations')
			->willReturn([]);

		$consent_cache = $this->createMock('\phpbb\consentmanager\service\consent_cache');
		$consent_cache->expects($cache_invocation ?? self::once())->method('invalidate_integrations');

		$text_formatter_cache = $this->createMock('\phpbb\textformatter\cache_interface');
		$text_formatter_cache->expects($text_formatter_invocation ?? self::never())->method('invalidate');

		return [$config_text, $consent_manager, $consent_cache, $text_formatter_cache];
	}

	protected function get_config_text($stored_integrations = '')
	{
		$config_text = $this->createMock('\phpbb\config\db_text');
		$config_text->method('get')
			->willReturnMap([
				['consentmanager_integrations', $stored_integrations],
			]);

		return $config_text;
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

	protected function get_example_integrations_json()
	{
		return <<<'JSON'
[
    {
        "id": "example.analytics",
        "category": "analytics",
        "label": "Example Analytics",
        "description": "Loads a simple analytics library after consent.",
        "src": "https://cdn.example.com/analytics.js",
        "async": true
    }
]
JSON;
	}

	protected function get_language_messages(array $message_specs)
	{
		return array_map(function ($message_spec) {
			$key = array_shift($message_spec);
			return call_user_func_array([$this->language, 'lang'], array_merge([$key], $message_spec));
		}, $message_specs);
	}

	protected function invoke_method($object, $method_name, array $arguments = [])
	{
		$method = new \ReflectionMethod($object, $method_name);
		$method->setAccessible(true);

		return $method->invokeArgs($object, $arguments);
	}

	protected function create_log_manager($user_id, $session_id)
	{
		$config = new \phpbb\config\config(array(
			'rand_seed' => 'random-seed',
		));

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = array(
			'user_id' => $user_id,
		);
		$user->session_id = $session_id;
		$user->ip = '127.0.0.1';

		return new \phpbb\consentmanager\service\log_manager(
			$config,
			$this->db,
			$user,
			'phpbb_consentmanager_logs'
		);
	}
}
