<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'CONSENTMANAGER_ACCEPT_ALL'					=> 'Accept all',
	'CONSENTMANAGER_REJECT_ALL'					=> 'Reject all',
	'CONSENTMANAGER_CUSTOMIZE'					=> 'Customize settings',
	'CONSENTMANAGER_SAVE_PREFERENCES'			=> 'Save choices',
	'CONSENTMANAGER_COOKIE_SETTINGS'			=> 'Cookie settings',
	'CONSENTMANAGER_SETTINGS_TITLE'				=> 'Privacy settings',
	'CONSENTMANAGER_SERVICE_LIST_HEADING'		=> 'Registered services',
	'CONSENTMANAGER_ALWAYS_ACTIVE'				=> 'Always active',
	'CONSENTMANAGER_ALLOWED'					=> 'Allowed',
	'CONSENTMANAGER_NOSCRIPT'					=> 'JavaScript is disabled, so optional analytics and marketing services remain off until JavaScript is enabled and you make a consent choice.',
	'CONSENTMANAGER_DEFAULT_BANNER_TITLE'		=> 'We value your privacy',
	'CONSENTMANAGER_DEFAULT_BANNER_TEXT'		=> 'We use necessary cookies to keep the forum secure and optional analytics and marketing technologies only when you allow them.',
	'CONSENTMANAGER_CATEGORY_NECESSARY'			=> 'Necessary',
	'CONSENTMANAGER_CATEGORY_NECESSARY_EXPLAIN'	=> 'Required for forum security, authentication, and core phpBB functionality.',
	'CONSENTMANAGER_CATEGORY_ANALYTICS'			=> 'Analytics',
	'CONSENTMANAGER_CATEGORY_ANALYTICS_EXPLAIN'	=> 'Helps forum operators understand traffic and improve performance.',
	'CONSENTMANAGER_CATEGORY_MARKETING'			=> 'Marketing',
	'CONSENTMANAGER_CATEGORY_MARKETING_EXPLAIN'	=> 'Used for advertising, personalization, and marketing attribution.',
));
