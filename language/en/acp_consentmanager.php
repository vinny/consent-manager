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
	'ACP_CONSENTMANAGER_EXPLAIN'					=> 'Here you can control category availability, registered your own integrations, and update consent versioning. Scripts added here (or by extensions via the API) will be deferred until the appropriate consent is given.',
	'ACP_CONSENTMANAGER_CATEGORIES'					=> 'Consent categories',
	'ACP_CONSENTMANAGER_ANALYTICS'					=> 'Enable analytics category',
	'ACP_CONSENTMANAGER_ANALYTICS_EXPLAIN'			=> 'Allows analytics integrations to be presented to users and loaded after consent.',
	'ACP_CONSENTMANAGER_MARKETING'					=> 'Enable marketing category',
	'ACP_CONSENTMANAGER_MARKETING_EXPLAIN'			=> 'Allows advertising and marketing integrations to be presented to users and loaded after consent.',
	'ACP_CONSENTMANAGER_INTEGRATIONS'				=> 'ACP-managed integrations',
	'ACP_CONSENTMANAGER_INTEGRATIONS_EXPLAIN'		=> 'Use this to add simple third-party analytics or marketing scripts directly from the ACP instead of through an extension. These entries appear in the consent UI and are only loaded after consent.<br><br>Provide a JSON array of integrations. Each object must include: <samp class="error">id</samp>, <samp class="error">category</samp>, <samp class="error">src</samp>. The <samp class="error">id</samp> may only use letters, numbers, dots, underscores, colons, and hyphens. <samp class="error">category</samp> must be <samp class="error">necessary</samp>, <samp class="error">analytics</samp>, or <samp class="error">marketing</samp>. <samp class="error">src</samp> must be a valid http, https, or relative script URL. Optional fields: <samp class="error">label</samp>, <samp class="error">description</samp>, <samp class="error">async</samp>, <samp class="error">defer</samp>.',
	'ACP_CONSENTMANAGER_VERSION'					=> 'Current consent version',
	'ACP_CONSENTMANAGER_VERSION_EXPLAIN'			=> 'Increase the version to force a fresh prompt for every visitor when the consent text or integrations materially change.',
	'ACP_CONSENTMANAGER_FORCE_REPROMPT'				=> 'Force re-prompt',
	'ACP_CONSENTMANAGER_REPROMPT_SUCCESS'			=> 'Consent version increased. Visitors will be asked to review their settings again.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATIONS'		=> 'The integrations field must contain a valid JSON array.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY'	=> 'Integration entry %1$s is invalid. Each entry must include a safe id, supported category, and valid script source URL.',
	'EXAMPLE'										=> 'Example',

	// Export consent logs
	'ACP_CONSENTMANAGER_EXPORT_EXPLAIN'				=> 'Download a CSV file of stored consent log records. All fields are optional; leave them blank to export the full log.',
	'ACP_CONSENTMANAGER_EXPORT_FILTERS'				=> 'Export filters',
	'ACP_CONSENTMANAGER_EXPORT_DATE_FROM'			=> 'Date from',
	'ACP_CONSENTMANAGER_EXPORT_DATE_TO'				=> 'Date to',
	'ACP_CONSENTMANAGER_EXPORT_DATE_EXPLAIN'		=> 'Use the browser date picker when available. If you cannot pick a date, enter it in YYYY-MM-DD format. Dates are interpreted in UTC. Leave blank to omit this boundary.',
	'ACP_CONSENTMANAGER_EXPORT_USER_ID'				=> 'User ID',
	'ACP_CONSENTMANAGER_EXPORT_USER_ID_EXPLAIN'		=> 'Enter a registered user ID to restrict the export to that user\'s consent records. Leave blank to include all users. Note: records for guests use a session-based identifier and cannot be filtered by user ID.',
	'ACP_CONSENTMANAGER_EXPORT_VERSION'				=> 'Consent version',
	'ACP_CONSENTMANAGER_EXPORT_VERSION_EXPLAIN'		=> 'Restrict the export to a specific consent version. Leave blank for all versions.',
	'ACP_CONSENTMANAGER_EXPORT_DOWNLOAD'			=> 'Download CSV',
	'ACP_CONSENTMANAGER_EXPORT_INVALID_DATE_FROM'	=> 'The "Date from" value is not a valid date. Use the browser date picker when available, or enter the date in YYYY-MM-DD format.',
	'ACP_CONSENTMANAGER_EXPORT_INVALID_DATE_TO'		=> 'The "Date to" value is not a valid date. Use the browser date picker when available, or enter the date in YYYY-MM-DD format.',
	'ACP_CONSENTMANAGER_EXPORT_DATE_RANGE_INVALID'	=> '"Date from" must not be later than "Date to".',
]);
