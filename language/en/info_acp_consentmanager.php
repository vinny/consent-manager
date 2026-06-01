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
	'ACP_CONSENTMANAGER'			=> 'Consent Manager',
	'ACP_CONSENTMANAGER_SETTINGS'	=> 'Settings',
	'ACP_CONSENTMANAGER_BANNER'		=> 'Consent Text',
	'ACP_CONSENTMANAGER_EXPORT'		=> 'Consent Logs',
	'LOG_CONSENTMANAGER_UPDATED'	=> '<strong>Updated Consent Manager settings</strong>',
	'LOG_CONSENTMANAGER_BANNER_UPDATED' => '<strong>Updated Consent Manager consent text</strong>',
	'LOG_CONSENTMANAGER_REPROMPT'	=> '<strong>Forced Consent Manager re-prompt by increasing the consent version</strong>',
	'LOG_CONSENTMANAGER_EXPORT'		=> '<strong>Exported Consent Manager logs as CSV</strong>',
	'LOG_CONSENTMANAGER_DELETE'		=> '<strong>Deleted Consent Manager log records</strong>',
]);
