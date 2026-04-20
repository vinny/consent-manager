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
	'ACP_CONSENTMANAGER_EXPLAIN'					=> 'Control category availability, registered integrations, and consent versioning. Non-essential scripts must be registered here or through the PHP API so they can be deferred until consent exists.',
	'ACP_CONSENTMANAGER_CATEGORIES'					=> 'Consent categories',
	'ACP_CONSENTMANAGER_ANALYTICS'					=> 'Enable analytics category',
	'ACP_CONSENTMANAGER_ANALYTICS_EXPLAIN'			=> 'Allows analytics integrations to be presented to users and loaded after consent.',
	'ACP_CONSENTMANAGER_MARKETING'					=> 'Enable marketing category',
	'ACP_CONSENTMANAGER_MARKETING_EXPLAIN'			=> 'Allows advertising and marketing integrations to be presented to users and loaded after consent.',
	'ACP_CONSENTMANAGER_INTEGRATIONS'				=> 'ACP-managed integrations',
	'ACP_CONSENTMANAGER_INTEGRATIONS_EXPLAIN'		=> 'Use this when you want to add a simple third-party analytics or marketing script directly from the ACP instead of through an extension. These entries appear in the consent UI and are only loaded after consent. Provide a JSON array of integrations. Each object must include: <samp class="error">id</samp>, <samp class="error">category</samp>, <samp class="error">src</samp>. The <samp class="error">id</samp> may only use letters, numbers, dots, underscores, colons, and hyphens. <samp class="error">category</samp> must be <samp class="error">necessary</samp>, <samp class="error">analytics</samp>, or <samp class="error">marketing</samp>. <samp class="error">src</samp> must be a valid http, https, or relative script URL. Optional fields: <samp class="error">label</samp>, <samp class="error">description</samp>, <samp class="error">async</samp>, <samp class="error">defer</samp>.',
	'ACP_CONSENTMANAGER_VERSION'					=> 'Current consent version',
	'ACP_CONSENTMANAGER_VERSION_EXPLAIN'			=> 'Increase the version to force a fresh prompt for every visitor when the consent text or integrations materially change.',
	'ACP_CONSENTMANAGER_FORCE_REPROMPT'				=> 'Force re-prompt',
	'ACP_CONSENTMANAGER_REPROMPT_SUCCESS'			=> 'Consent version increased. Visitors will be asked to review their settings again.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATIONS'		=> 'The integrations field must contain a valid JSON array.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY'	=> 'Integration entry %1$s is invalid. Each entry must include a safe id, supported category, and valid script source URL.',
	'EXAMPLE'										=> 'Example',
]);
