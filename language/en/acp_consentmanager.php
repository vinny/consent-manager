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
	'ACP_CONSENTMANAGER_MEDIA'						=> 'Enable embedded media category',
	'ACP_CONSENTMANAGER_MEDIA_EXPLAIN'				=> 'Allows videos, players, widgets, and other iframe-based external media to be loaded after consent.',
	'ACP_CONSENTMANAGER_INTEGRATIONS'				=> 'Manual integrations',
	'ACP_CONSENTMANAGER_INTEGRATIONS_EXPLAIN'		=> 'Use this to add third-party analytics, marketing, or other scripts directly from the ACP. These integrations appear in the consent UI and are only loaded after the required consent has been granted.',
	'ACP_CONSENTMANAGER_INTEGRATIONS_FORMAT'		=> 'Provide a JSON array of integrations. For example:',
	'ACP_CONSENTMANAGER_INTEGRATIONS_REQUIRED'		=> 'Required properties',
	'ACP_CONSENTMANAGER_INTEGRATIONS_REQUIRED_ID'	=> 'may only use letters, numbers, dots, underscores, colons, and hyphens.',
	'ACP_CONSENTMANAGER_INTEGRATIONS_REQUIRED_CAT'	=> 'must be one of these values:',
	'ACP_CONSENTMANAGER_INTEGRATIONS_REQUIRED_SRC'	=> 'must be a valid http, https, or relative script URL.',
	'ACP_CONSENTMANAGER_INTEGRATIONS_OPTIONAL'		=> 'Optional properties',
	'ACP_CONSENTMANAGER_INTEGRATIONS_EXAMPLE_LABEL'	=> 'Example Analytics',
	'ACP_CONSENTMANAGER_INTEGRATIONS_EXAMPLE_DESC'	=> 'Loads a simple analytics library after consent.',
	'ACP_CONSENTMANAGER_REGISTRATIONS'				=> 'Registered integrations',
	'ACP_CONSENTMANAGER_REGISTRATIONS_EXPLAIN'		=> 'These services are registered with Consent Manager and automatically respect consent settings.',
	'ACP_CONSENTMANAGER_REGISTRATIONS_NONE'			=> 'No services are currently registered with Consent Manager.',
	'ACP_CONSENTMANAGER_VERSION'					=> 'Current consent version',
	'ACP_CONSENTMANAGER_VERSION_EXPLAIN'			=> 'Increase the version to force a fresh prompt for every visitor when the consent text or integrations materially change.',
	'ACP_CONSENTMANAGER_FORCE_REPROMPT'				=> 'Force re-prompt',
	'ACP_CONSENTMANAGER_REPROMPT_SUCCESS'			=> 'Consent version increased. Visitors will be asked to review their settings again.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATIONS'		=> 'The integrations field must contain a valid JSON array.',
	'ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY'	=> 'Integration entry %1$s is invalid. Each entry must include a safe id, supported category, and valid script source URL.',
	'ACP_CONSENTMANAGER_INVALID_JSON'				=> 'Invalid JSON',
	'ACP_CONSENTMANAGER_BANNER_EXPLAIN'				=> 'Customise or translate the default text shown in the consent banner and privacy settings dialog for each installed language. BBCode and URLs are supported.',
	'ACP_CONSENTMANAGER_BANNER_TITLE'				=> 'Banner title',
	'ACP_CONSENTMANAGER_BANNER_MESSAGE'				=> 'Banner message',
	'ACP_CONSENTMANAGER_BANNER_SUBTEXT'				=> 'Banner subtext',
	'ACP_CONSENTMANAGER_BANNER_FALLBACK_EXPLAIN'	=> 'Leave a field blank to remove the custom translation and use Consent Manager’s default text for that language.',
	'ACP_CONSENTMANAGER_BANNER_TEXT_TOO_LONG'		=> 'Consent text values must be %d characters or fewer.',
	'ACP_CONSENTMANAGER_BANNER_UPDATED'				=> 'Consent text updated.',
	'CONSENTMANAGER_CATEGORY_NECESSARY'				=> 'Necessary',
	'CONSENTMANAGER_CATEGORY_ANALYTICS'				=> 'Analytics',
	'CONSENTMANAGER_CATEGORY_MARKETING'				=> 'Marketing',
	'CONSENTMANAGER_CATEGORY_MEDIA'					=> 'Media',

	// Consent logs
	'ACP_CONSENTMANAGER_EXPORT_EXPLAIN'				=> 'Download a CSV file of stored consent log records or permanently delete matching records from the database. All fields are optional; leave them blank to work with the full log.',
	'ACP_CONSENTMANAGER_EXPORT_FILTERS'				=> 'Consent log filters',
	'ACP_CONSENTMANAGER_EXPORT_DATE_FROM'			=> 'Date from',
	'ACP_CONSENTMANAGER_EXPORT_DATE_FROM_EXPLAIN'	=> 'Restrict the export (or deletion) to logs dated on or after this date. Leave blank for no lower date limit.',
	'ACP_CONSENTMANAGER_EXPORT_DATE_TO'				=> 'Date to',
	'ACP_CONSENTMANAGER_EXPORT_DATE_TO_EXPLAIN'		=> 'Restrict the export (or deletion) to logs dated on or before this date. Leave blank for no upper date limit.',
	'ACP_CONSENTMANAGER_EXPORT_USERNAME_EXPLAIN'	=> 'Enter a registered username to restrict the export (or deletion) to that user’s consent records. Leave blank to include all users.',
	'ACP_CONSENTMANAGER_EXPORT_VERSION'				=> 'Consent version',
	'ACP_CONSENTMANAGER_EXPORT_VERSION_EXPLAIN'		=> 'Restrict the export (or deletion) to a specific consent version. Leave blank for all versions.',
	'ACP_CONSENTMANAGER_EXPORT_DOWNLOAD'			=> 'Download CSV',
	'ACP_CONSENTMANAGER_DELETE'						=> 'Delete logs',
	'ACP_CONSENTMANAGER_DELETE_CONFIRM'				=> 'Are you sure you want to permanently delete the selected consent log records?',
	'ACP_CONSENTMANAGER_DELETE_SUCCESS'				=> 'The selected consent log records have been deleted.',
	'ACP_CONSENTMANAGER_EXPORT_INVALID_USERNAME'	=> 'The username “%1$s” could not be found.',
	'ACP_CONSENTMANAGER_EXPORT_INVALID_DATE_FROM'	=> 'The “Date from” value is not a valid date. Use the browser date picker when available, or enter the date in YYYY-MM-DD format.',
	'ACP_CONSENTMANAGER_EXPORT_INVALID_DATE_TO'		=> 'The “Date to” value is not a valid date. Use the browser date picker when available, or enter the date in YYYY-MM-DD format.',
	'ACP_CONSENTMANAGER_EXPORT_DATE_RANGE_INVALID'	=> '“Date from” must not be later than “Date to”.',
]);
