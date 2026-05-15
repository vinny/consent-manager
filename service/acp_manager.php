<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\service;

use phpbb\config\config;
use phpbb\config\db_text;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\log\log as phpbb_log;
use phpbb\textformatter\cache_interface;
use phpbb\user;

class acp_manager
{
	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var db_text */
	protected $config_text;

	/** @var language */
	protected $language;

	/** @var phpbb_log */
	protected $log;

	/** @var consent_manager_interface */
	protected $consent_manager;

	/** @var consent_cache */
	protected $consent_cache;

	/** @var cache_interface */
	protected $text_formatter_cache;

	/** @var user */
	protected $user;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var string */
	protected $consent_logs_table;

	/**
	 * Constructor.
	 *
	 * @param config                    $config Config service
	 * @param driver_interface          $db Database connection
	 * @param db_text                   $config_text Text config service
	 * @param language                  $language Language service
	 * @param phpbb_log                 $log phpBB log service
	 * @param consent_manager_interface $consent_manager Consent manager service
	 * @param consent_cache             $consent_cache Persistent cache helper
	 * @param cache_interface           $text_formatter_cache Text formatter cache service
	 * @param user                      $user Current user
	 * @param string                    $root_path phpBB root path
	 * @param string                    $php_ext PHP file extension
	 * @param string                    $consent_logs_table Consent log table name
	 */
	public function __construct(config $config, driver_interface $db, db_text $config_text, language $language, phpbb_log $log, consent_manager_interface $consent_manager, consent_cache $consent_cache, cache_interface $text_formatter_cache, user $user, $root_path, $php_ext, $consent_logs_table)
	{
		$this->config = $config;
		$this->db = $db;
		$this->config_text = $config_text;
		$this->language = $language;
		$this->log = $log;
		$this->consent_manager = $consent_manager;
		$this->consent_cache = $consent_cache;
		$this->text_formatter_cache = $text_formatter_cache;
		$this->user = $user;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
		$this->consent_logs_table = $consent_logs_table;
	}

	/**
	 * Build template variables for the ACP settings page.
	 *
	 * @return array
	 */
	public function get_settings_template_data()
	{
		return [
			'S_CONSENTMANAGER_ANALYTICS'	=> (bool) $this->config['consentmanager_analytics_enabled'],
			'S_CONSENTMANAGER_MARKETING'	=> (bool) $this->config['consentmanager_marketing_enabled'],
			'S_CONSENTMANAGER_MEDIA'		=> (bool) $this->config['consentmanager_media_enabled'],
			'CONSENTMANAGER_VERSION'		=> (int) $this->config['consentmanager_consent_version'],
			'CONSENTMANAGER_SERVICES'		=> $this->consent_manager->get_services(),
			'CONSENTMANAGER_INTEGRATIONS'	=> $this->get_integrations_json(),
			'CONSENTMANAGER_INTEGRATIONS_EXAMPLE' => $this->get_integrations_example_json(),
		];
	}

	/**
	 * Validate and persist ACP consent manager settings.
	 *
	 * @param array $settings Submitted settings
	 * @param array $errors   Validation errors
	 *
	 * @return bool
	 */
	public function save_settings(array $settings, array &$errors = [])
	{
		$errors = [];
		$stored_integrations = $this->normalize_integrations_json(
			$settings['integrations'] ?? '',
			$errors
		);

		if (!empty($errors))
		{
			return false;
		}

		$media_enabled = !empty($settings['media_enabled']) ? 1 : 0;
		$media_setting_changed = (int) $this->config['consentmanager_media_enabled'] !== $media_enabled;

		$this->config->set('consentmanager_analytics_enabled', !empty($settings['analytics_enabled']) ? 1 : 0);
		$this->config->set('consentmanager_marketing_enabled', !empty($settings['marketing_enabled']) ? 1 : 0);
		$this->config->set('consentmanager_media_enabled', $media_enabled);
		$this->config_text->set('consentmanager_integrations', $stored_integrations);
		$this->consent_cache->invalidate();

		if ($media_setting_changed)
		{
			$this->text_formatter_cache->invalidate();
		}

		return true;
	}

	/**
	 * Increment the consent version to force a fresh prompt.
	 *
	 * @return void
	 */
	public function reset_consent_version()
	{
		$this->config->set('consentmanager_consent_version', (int) $this->config['consentmanager_consent_version'] + 1);
	}

	/**
	 * Parse a YYYY-MM-DD date string into a UTC timestamp.
	 *
	 * @param string $date_str   Input date string
	 * @param bool   $end_of_day When true, uses 23:59:59 instead of 00:00:00
	 *
	 * @return int|false Timestamp on success, false if the string is empty or invalid
	 */
	public function parse_date_filter($date_str, $end_of_day = false)
	{
		if ($date_str === '')
		{
			return false;
		}

		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date_str, new \DateTimeZone('UTC'));

		if ($dt === false || $dt->format('Y-m-d') !== $date_str)
		{
			return false;
		}

		return $end_of_day
			? (int) $dt->setTime(23, 59, 59)->getTimestamp()
			: (int) $dt->getTimestamp();
	}

	/**
	 * Resolve a phpBB username to its numeric user ID.
	 *
	 * @param string $username Submitted username
	 *
	 * @return int|false User ID on success, false when no matching user exists
	 */
	public function get_user_id_by_username($username)
	{
		$username = trim((string) $username);

		if ($username === '')
		{
			return false;
		}

		if (!function_exists('user_get_id_name'))
		{
			include_once($this->root_path . 'includes/functions_user.' . $this->php_ext);
		}

		$user_id_ary = [];
		$username_ary = [$username];
		$error = user_get_id_name($user_id_ary, $username_ary, false, true);

		if ($error !== false || empty($user_id_ary))
		{
			return false;
		}

		return (int) reset($user_id_ary);
	}

	/**
	 * Write filtered consent log rows as CSV to the given file handle.
	 *
	 * Uses keyset pagination on consent_log_id to iterate rows in batches,
	 * avoiding memory exhaustion on large datasets.
	 *
	 * @param resource $handle     Writable stream (e.g. opened on php://output)
	 * @param array    $filters    Optional: date_from, date_to, user_id, consent_version
	 * @param int      $batch_size Rows per DB query
	 *
	 * @return void
	 */
	public function stream_logs_csv($handle, array $filters = [], $batch_size = 500)
	{
		$last_id = 0;

		do
		{
			$sql = 'SELECT consent_log_id, anonymized_id, consent_time, consent_version, accepted_categories'
				. ' FROM ' . $this->consent_logs_table
				. $this->build_filter_where($filters, $last_id)
				. ' ORDER BY consent_log_id ASC';

			$result = $this->db->sql_query_limit($sql, $batch_size);
			$count  = 0;

			while ($row = $this->db->sql_fetchrow($result))
			{
				$count++;
				$last_id    = (int) $row['consent_log_id'];
				$categories = json_decode($row['accepted_categories'], true);
				$cat_string = is_array($categories) ? implode(',', $categories) : '';

				fputcsv($handle, [
					$row['anonymized_id'],
					gmdate('Y-m-d\TH:i:s\Z', (int) $row['consent_time']),
					(int) $row['consent_version'],
					$this->sanitize_csv_value($cat_string),
				]);
			}

			$this->db->sql_freeresult($result);
		}
		while ($count === $batch_size);
	}

	/**
	 * Delete consent log rows matching the supplied filters.
	 *
	 * @param array $filters Optional: date_from, date_to, user_id, consent_version
	 *
	 * @return int Number of deleted rows
	 */
	public function delete_logs(array $filters = [])
	{
		$sql = 'DELETE FROM ' . $this->consent_logs_table . $this->build_delete_filter_where($filters);
		$this->db->sql_query($sql);

		return (int) $this->db->sql_affectedrows();
	}

	/**
	 * Compute the anonymized identifier for a given registered user ID.
	 *
	 * Mirrors the HMAC used in log_manager::log_consent() so that admins can
	 * filter exports by user ID without exposing raw identifiers.
	 *
	 * Note: it only matches rows hashed with the current config[rand_seed]. Records
	 * logged before a rand_seed rotation will not be found.
	 *
	 * @param int $user_id Numeric phpBB user ID (must be > 0)
	 *
	 * @return string 64-character hex hash
	 */
	public function hash_user_id($user_id)
	{
		return hash_hmac('sha256', 'u:' . (int) $user_id, $this->config['rand_seed']);
	}

	/**
	 * Add an admin log entry for an admin action in the settings.
	 *
	 * @param $message string Language key for the log message
	 * @return void
	 */
	public function log_admin_action($message)
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, $message);
	}

	/**
	 * Normalize integrations and encode them for config text storage.
	 *
	 * @param string|array $input Raw JSON or decoded integrations
	 * @param array        $errors Validation errors
	 *
	 * @return string
	 */
	protected function normalize_integrations_json($input, array &$errors = [])
	{
		if (is_string($input))
		{
			$json = trim($input);
			if ($json === '')
			{
				return '';
			}

			$this->consent_manager->normalize_integrations($json, $errors);
			return empty($errors) ? $json : '';
		}

		$this->consent_manager->normalize_integrations($input, $errors);
		if (!empty($errors))
		{
			return '';
		}

		$json = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false)
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
			return '';
		}

		return $json;
	}

	/**
	 * Return the stored ACP integrations JSON formatted for textarea output.
	 *
	 * @return string
	 */
	protected function get_integrations_json()
	{
		$json = trim((string) $this->config_text->get('consentmanager_integrations'));
		if ($json === '')
		{
			return '';
		}

		$decoded = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE)
		{
			return $json;
		}

		$pretty_json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($pretty_json === false)
		{
			return $json;
		}

		return $pretty_json;
	}

	/**
	 * Return example ACP integrations JSON formatted for template output.
	 *
	 * @return string
	 */
	protected function get_integrations_example_json()
	{
		$example = [[
			'id' => 'example.analytics',
			'category' => consent_manager_interface::ANALYTICS_CATEGORY,
			'label' => $this->language->lang('ACP_CONSENTMANAGER_INTEGRATIONS_EXAMPLE_LABEL'),
			'description' => $this->language->lang('ACP_CONSENTMANAGER_INTEGRATIONS_EXAMPLE_DESC'),
			'src' => 'https://cdn.example.com/analytics.js',
			'async' => true,
		]];

		$json = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return $json === false ? '' : $json;
	}

	/**
	 * Build a WHERE clause for consent log queries.
	 *
	 * The keyset condition (consent_log_id > last_id) is always included so
	 * that the caller can page through results without OFFSET.
	 *
	 * @param array $filters  Filter map from parse_export_filters
	 * @param int   $last_id  Highest consent_log_id seen in the previous batch
	 *
	 * @return string SQL WHERE clause (including the leading " WHERE " keyword)
	 */
	protected function build_filter_where(array $filters, $last_id = 0)
	{
		$where = array_merge(
			['consent_log_id > ' . (int) $last_id],
			$this->build_filter_conditions($filters)
		);

		return ' WHERE ' . implode(' AND ', $where);
	}

	/**
	 * Build a WHERE clause for deleting consent logs.
	 *
	 * @param array $filters Filter map from parse_export_filters
	 *
	 * @return string SQL WHERE clause, or an empty string when no filters are set
	 */
	protected function build_delete_filter_where(array $filters)
	{
		$where = $this->build_filter_conditions($filters);

		return empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);
	}

	/**
	 * Build SQL filter conditions shared by export and delete operations.
	 *
	 * @param array $filters Filter map from parse_export_filters
	 *
	 * @return array
	 */
	protected function build_filter_conditions(array $filters)
	{
		$where = [];

		if (!empty($filters['date_from']))
		{
			$where[] = 'consent_time >= ' . (int) $filters['date_from'];
		}

		if (!empty($filters['date_to']))
		{
			$where[] = 'consent_time <= ' . (int) $filters['date_to'];
		}

		if (!empty($filters['user_id']))
		{
			$anonymized = $this->hash_user_id((int) $filters['user_id']);
			$where[]    = "anonymized_id = '" . $this->db->sql_escape($anonymized) . "'";
		}

		if (!empty($filters['consent_version']))
		{
			$where[] = 'consent_version = ' . (int) $filters['consent_version'];
		}

		return $where;
	}

	protected function sanitize_csv_value($value)
	{
		// Prevent spreadsheet formula injection (CSV injection).
		// Excel/LibreOffice treat cells starting with =, +, -, @, or \t as formulas.
		if ($value !== '' && strpos('=+-@' . "\t", $value[0]) !== false)
		{
			return "\t" . $value;
		}

		return $value;
	}
}
