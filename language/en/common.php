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
	$lang = [];
}

$lang = array_merge($lang, [
	'CONSENTMANAGER_ACCEPT_ALL'					=> 'Accept all',
	'CONSENTMANAGER_REJECT_ALL'					=> 'Reject all',
	'CONSENTMANAGER_CUSTOMIZE'					=> 'Customise settings',
	'CONSENTMANAGER_SAVE_PREFERENCES'			=> 'Save choices',
	'CONSENTMANAGER_SETTINGS_TITLE'				=> 'Privacy settings',
	'CONSENTMANAGER_ALWAYS_ACTIVE'				=> 'Always active',
	'CONSENTMANAGER_ALLOWED'					=> 'Allowed',
	'CONSENTMANAGER_NOSCRIPT'					=> 'JavaScript is disabled, so only necessary cookies remain active. Enable JavaScript to manage optional analytics and marketing cookies, and embedded media.',
	'CONSENTMANAGER_DEFAULT_BANNER_TITLE'		=> 'We value your privacy',
	'CONSENTMANAGER_DEFAULT_BANNER_TEXT'		=> 'This forum uses cookies to keep you signed in, secure your account, and ensure the site works properly. With your consent, we may also use optional cookies and similar technologies for analytics, marketing, and embedded media.',
	'CONSENTMANAGER_DEFAULT_BANNER_SUBTEXT'		=> 'You can change your preferences at any time in the Privacy Settings.',
	'CONSENTMANAGER_CATEGORY_NECESSARY'			=> 'Necessary',
	'CONSENTMANAGER_CATEGORY_NECESSARY_EXPLAIN'	=> 'Required for forum security, authentication, and essential site functionality.',
	'CONSENTMANAGER_CATEGORY_ANALYTICS'			=> 'Analytics',
	'CONSENTMANAGER_CATEGORY_ANALYTICS_EXPLAIN'	=> 'Helps us understand how the forum is used so we can measure performance and improve the experience.',
	'CONSENTMANAGER_CATEGORY_MARKETING'			=> 'Marketing',
	'CONSENTMANAGER_CATEGORY_MARKETING_EXPLAIN'	=> 'Used for advertising, personalisation, and marketing measurement.',
	'CONSENTMANAGER_CATEGORY_MEDIA'				=> 'Embedded media',
	'CONSENTMANAGER_CATEGORY_MEDIA_EXPLAIN'		=> 'Controls whether external videos, players, widgets, and other embedded media are allowed to load.',
	'CONSENTMANAGER_MEDIA_PLACEHOLDER'			=> 'This content is blocked until you allow embedded media in the Privacy Settings.',
]);
