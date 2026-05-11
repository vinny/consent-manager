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

	/** @var \phpbb\request\request|\PHPUnit\Framework\MockObject\MockObject */
	protected $request;

	/** @var \phpbb\template\template|\PHPUnit\Framework\MockObject\MockObject */
	protected $template;

	/** @var \phpbb\consentmanager\controller\acp_controller|\PHPUnit\Framework\MockObject\MockObject */
	protected $acp_controller;

	/** @var \Symfony\Component\DependencyInjection\ContainerInterface|\PHPUnit\Framework\MockObject\MockObject */
	protected $container;

	protected function setUp(): void
	{
		global $phpbb_dispatcher, $phpbb_extension_manager, $phpbb_root_path, $phpEx, $phpbb_container, $request, $template;

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
			$this->createMock('\phpbb\db\driver\driver_interface'),
			$this->extension_manager,
			MODULES_TABLE,
			$phpbb_root_path,
			$phpEx
		);

		$phpbb_dispatcher = new \phpbb_mock_event_dispatcher();

		if (!defined('IN_ADMIN'))
		{
			define('IN_ADMIN', true);
		}

		$this->request = $this->createMock('\phpbb\request\request');
		$this->template = $this->createMock('\phpbb\template\template');
		$this->acp_controller = $this->createMock('\phpbb\consentmanager\controller\acp_controller');
		$this->container = $this->createMock('Symfony\Component\DependencyInjection\ContainerInterface');

		$phpbb_container = $this->container;
		$request = $this->request;
		$template = $this->template;
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
					'export'	=> [
						'title'	=> 'ACP_CONSENTMANAGER_EXPORT',
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
		$this->expect_controller_method('handle');

		$this->create_p_master()->load('acp', '\phpbb\consentmanager\acp\consentmanager_module');
	}

	public function test_main_module_export_mode()
	{
		$this->expect_controller_method('handle_export');

		$module = new \phpbb\consentmanager\acp\consentmanager_module();
		$module->u_action = 'adm.php?i=test&mode=export';
		$module->main('', 'export');

		self::assertSame('consentmanager_acp_export', $module->tpl_name);
		self::assertSame('ACP_CONSENTMANAGER_EXPORT', $module->page_title);
	}

	protected function expect_controller_method($method)
	{
		$args = ['phpbb.consentmanager.controller.acp'];
		$this->container
			->expects(self::once())
			->method('get')
			->with(...$args)
			->willReturn($this->acp_controller);

		$this->acp_controller
			->expects(self::once())
			->method($method);
	}

	protected function create_p_master()
	{
		$p_master = new \p_master();
		$p_master->module_ary[0]['is_duplicate'] = 0;
		$p_master->module_ary[0]['url_extra'] = '';

		return $p_master;
	}
}
