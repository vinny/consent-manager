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

use phpbb\consentmanager\service\consent_manager;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\template\template;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class listener implements EventSubscriberInterface
{
	/** @var helper */
	protected $helper;

	/** @var EventDispatcherInterface */
	protected $dispatcher;

	/** @var language */
	protected $language;

	/** @var consent_manager */
	protected $consent_manager;

	/** @var template */
	protected $template;

	public function __construct(
		helper $helper,
		EventDispatcherInterface $dispatcher,
		language $language,
		consent_manager $consent_manager,
		template $template
	) {
		$this->helper = $helper;
		$this->dispatcher = $dispatcher;
		$this->language = $language;
		$this->consent_manager = $consent_manager;
		$this->template = $template;
	}

	public static function getSubscribedEvents()
	{
		return array(
			'core.page_header_after' => 'inject_frontend',
		);
	}

	public function inject_frontend()
	{
		if (defined('ADMIN_START') || defined('IN_INSTALL'))
		{
			return;
		}

		$this->language->add_lang('common', 'phpbb/consentmanager');

		$consent_manager = $this->consent_manager;
		$vars = array('consent_manager');
		extract($this->dispatcher->trigger_event('phpbb.consentmanager.collect_registrations', compact($vars)));

		$payload = $this->consent_manager->build_frontend_payload(
			$this->helper->route('phpbb_consentmanager_log_controller'),
			generate_link_hash('phpbb.consentmanager.log')
		);

		$this->template->assign_vars(array(
			'S_CONSENTMANAGER_ENABLED'	=> true,
			'CONSENTMANAGER_PAYLOAD'	=> json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
		));
	}
}
