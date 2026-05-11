<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\system;

class ext_test extends \phpbb_test_case
{
	/** @var \PHPUnit\Framework\MockObject\MockObject|\Symfony\Component\DependencyInjection\ContainerInterface */
	protected $container;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\finder */
	protected $extension_finder;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\db\migrator */
	protected $migrator;

	/**
	 * @inheritdoc
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->disableOriginalConstructor()->getMock();
		$this->extension_finder = $this->getMockBuilder('\phpbb\finder')->disableOriginalConstructor()->getMock();
		$this->migrator = $this->getMockBuilder('\phpbb\db\migrator')->disableOriginalConstructor()->getMock();
	}

	/**
	 * Test the extension can only be enabled when the minimum
	 * phpBB version requirement is satisfied.
	 */
	public function test_ext_is_enableable()
	{
		$ext = new \phpbb\consentmanager\ext(
			$this->container,
			$this->extension_finder,
			$this->migrator,
			'phpbb/consentmanager',
			''
		);

		self::assertTrue($ext->is_enableable(), 'Asserting that the extension is enable-able.');
	}

	/**
	 * Test the extension returns a localized error when it cannot be enabled.
	 */
	public function test_ext_returns_localized_error_when_not_enableable()
	{
		global $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$extension_manager = $this->getMockBuilder('\phpbb\extension\manager')
			->disableOriginalConstructor()
			->setMethods(['get_extension_path'])
			->getMock();
		$extension_manager_args = ['phpbb/consentmanager', true];
		$extension_manager->method('get_extension_path')
			->with(...$extension_manager_args)
			->willReturn($phpbb_root_path . 'ext/phpbb/consentmanager/');
		$lang_loader->set_extension_manager($extension_manager);

		$language = new \phpbb\language\language($lang_loader);

		$language->add_lang('install', 'phpbb/consentmanager');

		$args = ['language'];
		$this->container->expects(self::once())
			->method('get')
			->with(...$args)
			->willReturn($language);

		$ext = new class(
			$this->container,
			$this->extension_finder,
			$this->migrator,
			'phpbb/consentmanager',
			''
		) extends \phpbb\consentmanager\ext {
			protected function check_php_version()
			{
				return false;
			}
		};

		self::assertSame(
			$language->lang('CONSENTMANAGER_NOT_ENABLEABLE'),
			$ext->is_enableable()
		);
	}
}
