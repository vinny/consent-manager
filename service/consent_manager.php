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
use phpbb\language\language;

class consent_manager implements consent_manager_interface
{
	const STORAGE_KEY = 'phpbb_consent_manager';
	const COOKIE_NAME = 'phpbb_consent_manager';

	/** @var config */
	protected $config;

	/** @var db_text */
	protected $config_text;

	/** @var language */
	protected $language;

	/** @var array */
	protected $registrations = array();

	public function __construct(config $config, db_text $config_text, language $language)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->language = $language;
	}

	/**
	 * Register a consent-aware service or script bundle.
	 *
	 * The definition must provide a supported category. Script definitions may
	 * provide either a script source URL or inline JavaScript, but not both.
	 *
	 * @param string $id Unique registration id
	 * @param array  $definition Registration metadata and scripts
	 * Invalid definitions fail closed and are ignored rather than causing
	 * optional scripts to execute unsafely.
	 *
	 * @return bool True if the registration was accepted, false otherwise
	 */
	public function register($id, array $definition)
	{
		$id = trim((string) $id);
		if (!$this->is_valid_identifier($id))
		{
			return false;
		}

		$category = isset($definition['category']) ? trim((string) $definition['category']) : '';
		if (!$this->is_supported_category($category))
		{
			return false;
		}

		$registration = array(
			'id' => $id,
			'label' => isset($definition['label']) && trim((string) $definition['label']) !== '' ? trim((string) $definition['label']) : $id,
			'category' => $category,
			'description' => isset($definition['description']) ? trim((string) $definition['description']) : '',
			'scripts' => array(),
		);

		if (isset($definition['scripts']) && is_array($definition['scripts']))
		{
			foreach ($definition['scripts'] as $script_index => $script_definition)
			{
				if (!is_array($script_definition))
				{
					continue;
				}

				$script = $this->normalize_script($id, $category, $script_definition, $script_index, true);
				if (!empty($script))
				{
					$registration['scripts'][] = $script;
				}
			}
		}
		else
		{
			$script = $this->normalize_script($id, $category, $definition, 0, false);
			if (!empty($script))
			{
				$registration['scripts'][] = $script;
			}
		}

		$this->registrations[$registration['id']] = $registration;
		return true;
	}

	public function build_frontend_payload($log_url, $log_hash)
	{
		$categories = $this->get_categories();
		$services = $this->get_services();
		$scripts = array();

		foreach ($services as $service)
		{
			foreach ($service['scripts'] as $script)
			{
				if ($this->is_category_enabled($script['category']))
				{
					$scripts[$script['id']] = $script;
				}
			}
		}

		return array(
			'storageKey' => $this->get_storage_key(),
			'cookieName' => $this->get_cookie_name(),
			'version' => $this->get_version(),
			'rootId' => 'consent-manager-root',
			'deferredSelector' => 'script[type="text/plain"][data-consent-category]',
			'categories' => array_values($categories),
			'services' => array_values($services),
			'scripts' => array_values($scripts),
			'banner' => array(
				'title' => $this->language->lang('CONSENTMANAGER_DEFAULT_BANNER_TITLE'),
				'text' => $this->language->lang('CONSENTMANAGER_DEFAULT_BANNER_TEXT'),
			),
			'strings' => array(
				'acceptAll' => $this->language->lang('CONSENTMANAGER_ACCEPT_ALL'),
				'rejectAll' => $this->language->lang('CONSENTMANAGER_REJECT_ALL'),
				'customize' => $this->language->lang('CONSENTMANAGER_CUSTOMIZE'),
				'savePreferences' => $this->language->lang('CONSENTMANAGER_SAVE_PREFERENCES'),
				'cookieSettings' => $this->language->lang('CONSENTMANAGER_COOKIE_SETTINGS'),
				'close' => $this->language->lang('CLOSE_WINDOW'),
				'serviceListHeading' => $this->language->lang('CONSENTMANAGER_SERVICE_LIST_HEADING'),
				'alwaysActive' => $this->language->lang('CONSENTMANAGER_ALWAYS_ACTIVE'),
				'allowed' => $this->language->lang('CONSENTMANAGER_ALLOWED'),
				'settingsTitle' => $this->language->lang('CONSENTMANAGER_SETTINGS_TITLE'),
			),
			'logEndpoint' => $log_url,
			'logHash' => $log_hash,
		);
	}

	public function get_categories()
	{
		return array(
			'necessary' => array(
				'id' => 'necessary',
				'label' => $this->language->lang('CONSENTMANAGER_CATEGORY_NECESSARY'),
				'description' => $this->language->lang('CONSENTMANAGER_CATEGORY_NECESSARY_EXPLAIN'),
				'required' => true,
				'enabled' => true,
			),
			'analytics' => array(
				'id' => 'analytics',
				'label' => $this->language->lang('CONSENTMANAGER_CATEGORY_ANALYTICS'),
				'description' => $this->language->lang('CONSENTMANAGER_CATEGORY_ANALYTICS_EXPLAIN'),
				'required' => false,
				'enabled' => (bool) $this->config['consentmanager_analytics_enabled'],
			),
			'marketing' => array(
				'id' => 'marketing',
				'label' => $this->language->lang('CONSENTMANAGER_CATEGORY_MARKETING'),
				'description' => $this->language->lang('CONSENTMANAGER_CATEGORY_MARKETING_EXPLAIN'),
				'required' => false,
				'enabled' => (bool) $this->config['consentmanager_marketing_enabled'],
			),
		);
	}

	public function get_services()
	{
		$services = $this->registrations;

		foreach ($this->get_configured_integrations() as $integration)
		{
			$services[$integration['id']] = $integration;
		}

		foreach ($services as $id => $service)
		{
			if (!$this->is_category_enabled($service['category']))
			{
				unset($services[$id]);
			}
		}

		return $services;
	}

	public function get_configured_integrations()
	{
		$raw = $this->config_text->get('consentmanager_integrations');
		if ($raw === '')
		{
			return array();
		}

		$errors = array();
		$integrations = $this->normalize_integrations($raw, $errors);

		return empty($errors) ? $integrations : array();
	}

	/**
	 * Normalize integrations configured through the ACP JSON textarea.
	 *
	 * Inline JavaScript is intentionally not supported here so ACP-managed
	 * integrations cannot introduce arbitrary executable code.
	 *
	 * @param string|array $input Raw JSON or pre-decoded integrations
	 * @param array        $errors Validation errors
	 * @return array
	 */
	public function normalize_integrations($input, array &$errors = array())
	{
		if (is_string($input))
		{
			$trimmed = trim($input);
			if ($trimmed === '')
			{
				return array();
			}

			$decoded = json_decode($trimmed, true);
			if (json_last_error() !== JSON_ERROR_NONE)
			{
				$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
				return array();
			}
		}
		else
		{
			$decoded = $input;
		}

		if (empty($decoded))
		{
			return array();
		}

		if (!is_array($decoded))
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
			return array();
		}

		$integrations = array();

		foreach ($decoded as $index => $item)
		{
			if (!is_array($item))
			{
				$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY', $index + 1);
				continue;
			}

			$id = isset($item['id']) ? trim((string) $item['id']) : '';
			$label = isset($item['label']) ? trim((string) $item['label']) : '';
			$description = isset($item['description']) ? trim((string) $item['description']) : '';
			$category = isset($item['category']) ? trim((string) $item['category']) : '';
			$src = isset($item['src']) ? trim((string) $item['src']) : '';

			if (!$this->is_valid_identifier($id) || !$this->is_supported_category($category) || !$this->is_valid_script_source($src))
			{
				$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATION_ENTRY', $index + 1);
				continue;
			}

			$integrations[$id] = array(
				'id' => $id,
				'label' => $label !== '' ? $label : $id,
				'category' => $category,
				'description' => $description,
				'scripts' => array(
					array(
						'id' => $id,
						'category' => $category,
						'src' => $src,
						'inline' => '',
						'async' => !empty($item['async']),
						'defer' => !empty($item['defer']),
						'attributes' => array(),
					),
				),
			);
		}

		return array_values($integrations);
	}

	public function normalize_categories(array $categories)
	{
		$normalized = array('necessary');

		foreach ($categories as $category)
		{
			$category = trim((string) $category);
			if ($category !== 'necessary' && $this->is_category_enabled($category))
			{
				$normalized[] = $category;
			}
		}

		return array_values(array_unique($normalized));
	}

	public function get_storage_key()
	{
		return self::STORAGE_KEY;
	}

	public function get_cookie_name()
	{
		return self::COOKIE_NAME;
	}

	public function get_version()
	{
		return (int) $this->config['consentmanager_consent_version'];
	}

	public function is_category_enabled($category)
	{
		$categories = $this->get_categories();
		return isset($categories[$category]) && $categories[$category]['enabled'];
	}

	public function is_supported_category($category)
	{
		return in_array($category, array('necessary', 'analytics', 'marketing'), true);
	}

	protected function normalize_script($registration_id, $fallback_category, array $definition, $script_index = 0, $force_unique_id = false)
	{
		$category = isset($definition['category']) && trim((string) $definition['category']) !== '' ? trim((string) $definition['category']) : $fallback_category;
		if (!$this->is_supported_category($category))
		{
			return array();
		}

		$script_id = isset($definition['id']) && trim((string) $definition['id']) !== '' ? trim((string) $definition['id']) : $registration_id;
		if ($force_unique_id || $script_id === $registration_id && $script_index > 0)
		{
			$script_id = $registration_id . '.' . ($script_index + 1);
		}

		if (!$this->is_valid_identifier($script_id))
		{
			return array();
		}

		$src = isset($definition['src']) ? trim((string) $definition['src']) : '';
		$inline = isset($definition['inline']) ? trim((string) $definition['inline']) : '';

		if ($src === '' && $inline === '')
		{
			return array();
		}

		if ($src !== '' && $inline !== '')
		{
			return array();
		}

		if ($src !== '' && !$this->is_valid_script_source($src))
		{
			return array();
		}

		return array(
			'id' => $script_id,
			'category' => $category,
			'src' => $src,
			'inline' => $inline,
			'async' => isset($definition['async']) ? (bool) $definition['async'] : ($src !== ''),
			'defer' => isset($definition['defer']) ? (bool) $definition['defer'] : false,
			'attributes' => $this->normalize_attributes(isset($definition['attributes']) ? $definition['attributes'] : array()),
		);
	}

	protected function normalize_attributes($attributes)
	{
		if (!is_array($attributes))
		{
			return array();
		}

		$normalized = array();
		foreach ($attributes as $name => $value)
		{
			$name = trim((string) $name);
			if ($name === ''
				|| !preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:\\.-]*$/', $name)
				|| preg_match('/^on/i', $name)
				|| in_array(strtolower($name), array('src', 'type', 'async', 'defer'), true))
			{
				continue;
			}

			if (is_scalar($value))
			{
				$normalized[$name] = (string) $value;
			}
		}

		return $normalized;
	}

	protected function is_valid_script_source($src)
	{
		if ($src === '' || preg_match('/[<>"\']/', $src))
		{
			return false;
		}

		if (strpos($src, '//') === 0 || preg_match('#^(?:javascript|data|vbscript|file):#i', $src))
		{
			return false;
		}

		$parts = parse_url($src);
		if ($parts === false)
		{
			return false;
		}

		return !isset($parts['scheme']) || in_array(strtolower($parts['scheme']), array('http', 'https'), true);
	}

	protected function is_valid_identifier($identifier)
	{
		return $identifier !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $identifier);
	}

}
