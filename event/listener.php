<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\event;

use phpbb\consentmanager\service\consent_manager_interface;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\template\template;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** @var helper */
	protected $helper;

	/** @var language */
	protected $language;

	/** @var consent_manager_interface */
	protected $consent_manager;

	/** @var template */
	protected $template;

	/**
	 * Constructor.
	 *
	 * @param helper                    $helper Controller helper
	 * @param language                  $language Language service
	 * @param consent_manager_interface $consent_manager Consent manager service
	 * @param template                  $template Template service
	 */
	public function __construct(helper $helper, language $language, consent_manager_interface $consent_manager, template $template)
	{
		$this->helper = $helper;
		$this->language = $language;
		$this->consent_manager = $consent_manager;
		$this->template = $template;
	}

	/**
	 * Return the subscribed phpBB events.
	 *
	 * @return array
	 */
	public static function getSubscribedEvents()
	{
		return [
			'core.page_header_after' => 'inject_frontend',
		];
	}

	/**
	 * Inject consent manager frontend data on board pages.
	 *
	 * @return void
	 */
	public function inject_frontend()
	{
		if (defined('ADMIN_START') || defined('IN_INSTALL'))
		{
			return;
		}

		if (!$this->consent_manager->has_optional_categories())
		{
			return;
		}

		$this->language->add_lang('common', 'phpbb/consentmanager');
		$this->template->assign_vars($this->consent_manager->get_frontend_template_data(
			$this->helper->route('phpbb_consentmanager_log_controller'),
			generate_link_hash('phpbb.consentmanager.log')
		));

		foreach ($this->consent_manager->get_frontend_category_data() as $category)
		{
			$this->template->assign_block_vars('CONSENTMANAGER_CATEGORIES', $category);

			foreach ($category['services'] as $service)
			{
				$this->template->assign_block_vars('CONSENTMANAGER_CATEGORIES.CONSENTMANAGER_SERVICES', $service);
			}
		}
	}
}
