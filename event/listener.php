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
use phpbb\consentmanager\service\media_manager;
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

	/** @var media_manager */
	protected $media_manager;

	/**
	 * Constructor.
	 *
	 * @param helper                    $helper Controller helper
	 * @param language                  $language Language service
	 * @param consent_manager_interface $consent_manager Consent manager service
	 * @param template                  $template Template service
	 * @param media_manager             $media_manager Media manager
	 */
	public function __construct(helper $helper, language $language, consent_manager_interface $consent_manager, template $template, media_manager $media_manager)
	{
		$this->helper = $helper;
		$this->language = $language;
		$this->consent_manager = $consent_manager;
		$this->template = $template;
		$this->media_manager = $media_manager;
	}

	/**
	 * Return the subscribed phpBB events.
	 *
	 * @return array
	 */
	public static function getSubscribedEvents()
	{
		return [
			'core.text_formatter_s9e_configure_after' => [['configure_iframe_embeds', -10]],
			'core.text_formatter_s9e_renderer_setup' => 'configure_iframe_renderer',
			'core.page_header_after' => 'inject_frontend',
		];
	}

	/**
	 * Transform s9e-rendered iframe output into consent-aware placeholders.
	 *
	 * @param \phpbb\event\data $event Event data
	 *
	 * @return void
	 */
	public function configure_iframe_embeds($event)
	{
		$this->media_manager->configure_iframe_embeds($event['configurator']);
	}

	/**
	 * Pass the current request's media consent state into the s9e renderer.
	 *
	 * @param \phpbb\event\data $event Event data
	 *
	 * @return void
	 */
	public function configure_iframe_renderer($event)
	{
		$this->media_manager->configure_iframe_renderer($event['renderer']);
	}

	/**
	 * Inject consent manager frontend data on board pages.
	 *
	 * @return void
	 */
	public function inject_frontend()
	{
		if ($this->is_acp_or_installer() || !$this->consent_manager->has_optional_categories())
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

	/**
	 * Determine whether the current request is running in the ACP or installer.
	 *
	 * @return bool
	 */
	protected function is_acp_or_installer()
	{
		return defined('ADMIN_START') || defined('IN_INSTALL');
	}
}
