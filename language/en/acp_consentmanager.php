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
	'ACP_CONSENTMANAGER_EXPLAIN'					=> 'Control category availability, registered integrations, and consent versioning. Non-essential scripts must be registered here or through the PHP API so they can be deferred until consent exists.',
	'ACP_CONSENTMANAGER_CATEGORIES'					=> 'Consent categories',
	'ACP_CONSENTMANAGER_CATEGORIES_EXPLAIN'			=> 'Necessary cookies are always active. Optional categories can be disabled globally when not needed.',
	'ACP_CONSENTMANAGER_ANALYTICS'					=> 'Enable analytics category',
	'ACP_CONSENTMANAGER_ANALYTICS_EXPLAIN'			=> 'Allows analytics integrations to be presented to users and loaded after consent.',
	'ACP_CONSENTMANAGER_MARKETING'					=> 'Enable marketing category',
	'ACP_CONSENTMANAGER_MARKETING_EXPLAIN'			=> 'Allows advertising and marketing integrations to be presented to users and loaded after consent.',
	'ACP_CONSENTMANAGER_INTEGRATIONS'				=> 'ACP-managed integrations',
	'ACP_CONSENTMANAGER_INTEGRATIONS_EXPLAIN'		=> 'Provide a JSON array of integrations. Each object must include: id, category, src. Optional fields: label, description, async, defer.',
	'ACP_CONSENTMANAGER_VERSION'					=> 'Current consent version',
	'ACP_CONSENTMANAGER_VERSION_EXPLAIN'			=> 'Increase the version to force a fresh prompt for every visitor when the consent text or integrations materially change.',
	'ACP_CONSENTMANAGER_FORCE_REPROMPT'				=> 'Force re-prompt',
	'ACP_CONSENTMANAGER_REPROMPT_SUCCESS'			=> 'Consent version increased. Visitors will be asked to review their settings again.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATIONS'		=> 'The integrations field must contain a valid JSON array.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY'	=> 'Integration entry %1$s is invalid. Each entry must include a safe id, supported category, and valid script source URL.',
));
