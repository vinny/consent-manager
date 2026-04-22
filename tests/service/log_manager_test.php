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

class log_manager_test extends \phpbb_database_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	public static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);

		$db = $this->new_dbal();
		$db->sql_query('DELETE FROM phpbb_consentmanager_logs');
		$db->sql_close();
	}

	public function getDataSet()
	{
		return new \PHPUnit\DbUnit\DataSet\DefaultDataSet();
	}

	public function test_log_consent_persists_authenticated_subject()
	{
		$manager = $this->create_manager(42, 'ignored-session');
		$manager->log_consent(array('necessary', 'analytics'), 3);

		$this->assertSqlResultEquals(array(
			array(
				'anonymized_id' => hash_hmac('sha256', 'u:42', 'random-seed'),
				'consent_version' => '3',
				'accepted_categories' => '["necessary","analytics"]',
			),
		), 'SELECT anonymized_id, consent_version, accepted_categories
			FROM phpbb_consentmanager_logs');
	}

	public function test_log_consent_uses_session_identifier_for_guests()
	{
		$manager = $this->create_manager(ANONYMOUS, 'guest-session');
		$manager->log_consent(array('necessary'), 9);

		$this->assertSqlResultEquals(array(
			array(
				'anonymized_id' => hash_hmac('sha256', 's:guest-session', 'random-seed'),
				'consent_version' => '9',
				'accepted_categories' => '["necessary"]',
			),
		), 'SELECT anonymized_id, consent_version, accepted_categories
			FROM phpbb_consentmanager_logs');
	}

	public function test_log_admin_settings_updated_delegates_to_phpbb_log()
	{
		$log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->setMethods(array('add'))
			->getMock();
		$log->expects(self::once())
			->method('add')
			->with('admin', 7, '127.0.0.1', 'LOG_CONSENTMANAGER_UPDATED');

		$manager = $this->create_manager(7, 'admin-session', $log);
		$manager->log_admin_settings_updated();
	}

	public function test_log_admin_reprompt_delegates_to_phpbb_log()
	{
		$log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->setMethods(array('add'))
			->getMock();
		$log->expects(self::once())
			->method('add')
			->with('admin', 7, '127.0.0.1', 'LOG_CONSENTMANAGER_REPROMPT');

		$manager = $this->create_manager(7, 'admin-session', $log);
		$manager->log_admin_reprompt();
	}

	protected function create_manager($user_id, $session_id, $log = null)
	{
		$config = new \phpbb\config\config(array(
			'rand_seed' => 'random-seed',
		));
		$db = $this->new_dbal();

		if ($log === null)
		{
			$log = $this->getMockBuilder('\phpbb\log\log')
				->disableOriginalConstructor()
				->getMock();
		}

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = array(
			'user_id' => $user_id,
		);
		$user->session_id = $session_id;
		$user->ip = '127.0.0.1';

		return new \phpbb\consentmanager\service\log_manager(
			$config,
			$db,
			$log,
			$user,
			'phpbb_consentmanager_logs'
		);
	}
}
