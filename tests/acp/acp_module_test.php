<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\acp;

require_once __DIR__ . '/../../../../../includes/functions_module.php';

class acp_module_test extends \phpbb_test_case
{
	/** @var \phpbb_mock_extension_manager */
	protected $extension_manager;

	/** @var \phpbb\module\module_manager */
	protected $module_manager;

	protected function setUp(): void
	{
		global $phpbb_dispatcher, $phpbb_extension_manager, $phpbb_root_path, $phpEx;

		$this->extension_manager = new \phpbb_mock_extension_manager(
			$phpbb_root_path,
			[
				'phpbb/consentmanager' => [
					'ext_name' => 'phpbb/consentmanager',
					'ext_active' => '1',
					'ext_path' => 'ext/phpbb/consentmanager/',
				],
			]);
		$phpbb_extension_manager = $this->extension_manager;

		$this->module_manager = new \phpbb\module\module_manager(
			new \phpbb\cache\driver\dummy(),
			$this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock(),
			$this->extension_manager,
			MODULES_TABLE,
			$phpbb_root_path,
			$phpEx
		);

		$phpbb_dispatcher = new \phpbb_mock_event_dispatcher();
	}

	public function test_module_info()
	{
		self::assertEquals([
			'\\phpbb\\consentmanager\\acp\\consentmanager_module' => [
				'filename'	=> '\\phpbb\\consentmanager\\acp\\consentmanager_module',
				'title'		=> 'ACP_CONSENTMANAGER',
				'modes'		=> [
					'settings'	=> [
						'title'	=> 'ACP_CONSENTMANAGER_SETTINGS',
						'auth'	=> 'ext_phpbb/consentmanager && acl_a_board',
						'cat'	=> ['ACP_CONSENTMANAGER']
					],
				],
			],
		], $this->module_manager->get_module_infos('acp', 'consentmanager_module'));
	}

	public function module_auth_test_data()
	{
		return [
			// module_auth, expected result
			['ext_foo/bar', false],
			['ext_phpbb/consentmanager', true],
		];
	}

	/**
	 * @dataProvider module_auth_test_data
	 */
	public function test_module_auth($module_auth, $expected)
	{
		self::assertEquals($expected, \p_master::module_auth($module_auth, 0));
	}

	public function test_main_module()
	{
		global $phpbb_container, $request, $template;

		if (!defined('IN_ADMIN'))
		{
			define('IN_ADMIN', true);
		}

		$request = $this->getMockBuilder('\phpbb\request\request')
			->disableOriginalConstructor()
			->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')
			->disableOriginalConstructor()
			->getMock();
		$phpbb_container = $this->getMockBuilder('Symfony\Component\DependencyInjection\ContainerInterface')
			->disableOriginalConstructor()
			->getMock();
		$acp_controller = $this->getMockBuilder('\phpbb\consentmanager\controller\acp_controller')
			->disableOriginalConstructor()
			->getMock();

		$phpbb_container
			->expects(self::once())
			->method('get')
			->with('phpbb.consentmanager.controller.acp')
			->willReturn($acp_controller);

		$acp_controller
			->expects(self::once())
			->method('handle');

		$p_master = new \p_master();
		$p_master->module_ary[0]['is_duplicate'] = 0;
		$p_master->module_ary[0]['url_extra'] = '';
		$p_master->load('acp', '\phpbb\consentmanager\acp\consentmanager_module');
	}
}
