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

interface consent_manager_interface
{
	/**
	 * Register a consent-aware service definition.
	 *
	 * @param string $id Registration identifier
	 * @param array  $definition Service definition
	 *
	 * @return bool
	 */
	public function register($id, array $definition);

	/**
	 * Build template variables needed by the frontend consent UI.
	 *
	 * @param string $log_url Consent logging endpoint URL
	 * @param string $log_hash Link hash for consent logging
	 *
	 * @return array
	 */
	public function get_frontend_template_data($log_url, $log_hash);

	/**
	 * Build template variable data for categories and services in the frontend consent UI.
	 *
	 * @return array
	 */
	public function get_frontend_category_data();

	/**
	 * Build template variables for the ACP settings page.
	 *
	 * @return array
	 */
	public function get_acp_template_data();

	/**
	 * Persist ACP settings for consent manager.
	 *
	 * @param array $settings Submitted settings
	 * @param array $errors   Validation errors
	 *
	 * @return bool
	 */
	public function save_acp_settings(array $settings, array &$errors = []);

	/**
	 * Increment the consent version to re-prompt users.
	 *
	 * @return void
	 */
	public function reset_consent_version();

	/**
	 * Validate a frontend consent logging payload.
	 *
	 * @param array $payload Submitted payload
	 *
	 * @return array
	 */
	public function validate_log_payload(array $payload);

	/**
	 * Build the JSON payload consumed by the frontend app.
	 *
	 * @param string $log_url Consent logging endpoint URL
	 * @param string $log_hash Link hash for consent logging
	 *
	 * @return array
	 */
	public function build_frontend_payload($log_url, $log_hash);

	/**
	 * Return consent category metadata.
	 *
	 * @return array
	 */
	public function get_categories();

	/**
	 * Return active consent-aware services.
	 *
	 * @return array
	 */
	public function get_services();

	/**
	 * Normalize configured integrations into service definitions.
	 *
	 * @param string|array $input Raw JSON or decoded integrations
	 * @param array        $errors Validation errors
	 *
	 * @return array
	 */
	public function normalize_integrations($input, array &$errors = []);

	/**
	 * Normalize selected category ids for storage and logging.
	 *
	 * @param array $categories Submitted category ids
	 *
	 * @return array
	 */
	public function normalize_categories(array $categories);

	/**
	 * Return the client-side consent storage key.
	 *
	 * @return string
	 */
	public function get_storage_key();

	/**
	 * Return the consent cookie name.
	 *
	 * @return string
	 */
	public function get_cookie_name();

	/**
	 * Return the current consent version.
	 *
	 * @return int
	 */
	public function get_version();

	/**
	 * Determine whether a consent category is currently enabled.
	 *
	 * @param string $category Category identifier
	 *
	 * @return bool
	 */
	public function is_category_enabled($category);

	/**
	 * Determine whether any optional consent categories are enabled.
	 *
	 * @return bool
	 */
	public function has_optional_categories();
}
