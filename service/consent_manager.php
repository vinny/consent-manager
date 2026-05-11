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
use phpbb\event\dispatcher_interface;
use phpbb\filesystem\filesystem;
use phpbb\language\language;
use phpbb\path_helper;
use phpbb\request\request_interface;
use phpbb\template\asset;
use phpbb\template\twig\environment;
use Twig\Error\LoaderError;

class consent_manager implements consent_manager_interface
{
	public const STORAGE_KEY = 'phpbb_consent_manager';
	public const COOKIE_NAME = 'phpbb_consent_manager';

	/** @var consent_cache */
	protected $consent_cache;

	/** @var config */
	protected $config;

	/** @var db_text */
	protected $config_text;

	/** @var language */
	protected $language;

	/** @var dispatcher_interface */
	protected $dispatcher;

	/** @var environment */
	protected $twig_environment;

	/** @var path_helper */
	protected $path_helper;

	/** @var filesystem */
	protected $filesystem;

	/** @var request_interface */
	protected $request;

	/** @var array */
	protected $registrations = [];

	/** @var bool */
	protected $registrations_collected = false;

	/** @var bool */
	protected $server_consent_state_loaded = false;

	/** @var array|null */
	protected $server_consent_state;

	/** @var array|null */
	protected $configured_integrations;

	/** @var array|null */
	protected $services;

	/** @var array|null */
	protected $category_config;

	/** @var array */
	protected $local_asset_sources = [];

	/**
	 * Constructor.
	 *
	 * @param consent_cache        $consent_cache Persistent cache helper
	 * @param config               $config Config service
	 * @param db_text              $config_text Text configuration storage
	 * @param language             $language Language service
	 * @param dispatcher_interface $dispatcher Event dispatcher
	 * @param environment          $twig_environment Twig environment
	 * @param path_helper          $path_helper Path helper
	 * @param filesystem           $filesystem Filesystem helper
	 * @param request_interface    $request Request service
	 */
	public function __construct(consent_cache $consent_cache, config $config, db_text $config_text, language $language, dispatcher_interface $dispatcher, environment $twig_environment, path_helper $path_helper, filesystem $filesystem, request_interface $request)
	{
		$this->consent_cache = $consent_cache;
		$this->config = $config;
		$this->config_text = $config_text;
		$this->language = $language;
		$this->dispatcher = $dispatcher;
		$this->twig_environment = $twig_environment;
		$this->path_helper = $path_helper;
		$this->filesystem = $filesystem;
		$this->request = $request;
	}

	/**
	 * Register a consent-aware service or script bundle.
	 *
	 * The definition must provide a supported category. Script definitions may
	 * provide either a script source URL, a local asset reference, or inline
	 * JavaScript, but not more than one execution source.
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

		$registration = [
			'id' => $id,
			'label' => isset($definition['label']) && trim((string) $definition['label']) !== '' ? trim((string) $definition['label']) : $id,
			'category' => $category,
			'description' => isset($definition['description']) ? trim((string) $definition['description']) : '',
			'scripts' => [],
		];
		$registered_script_ids = $this->get_registered_script_ids($id);

		if (isset($definition['scripts']) && is_array($definition['scripts']))
		{
			foreach ($definition['scripts'] as $script_index => $script_definition)
			{
				if (!is_array($script_definition))
				{
					continue;
				}

				$script = $this->normalize_script($id, $category, $script_definition, $script_index, true);
				if (!empty($script) && !isset($registered_script_ids[$script['id']]))
				{
					$registration['scripts'][] = $script;
					$registered_script_ids[$script['id']] = true;
				}
			}
		}
		else
		{
			$script = $this->normalize_script($id, $category, $definition);
			if (!empty($script) && !isset($registered_script_ids[$script['id']]))
			{
				$registration['scripts'][] = $script;
			}
		}

		$this->services = null;

		$this->registrations[$registration['id']] = $registration;
		return true;
	}

	/**
	 * Build template variables for rendering the frontend consent UI.
	 *
	 * @param string $log_url Consent logging endpoint URL
	 * @param string $log_hash Link hash for consent logging
	 *
	 * @return array
	 */
	public function get_frontend_template_data($log_url, $log_hash)
	{
		$categories = $this->get_category_config();
		$has_optional_categories = !empty($this->get_optional_category_ids($categories));
		$payload = $has_optional_categories ? $this->build_frontend_payload($log_url, $log_hash) : '';

		$vars = [
			'S_CONSENTMANAGER_ENABLED'				=> $has_optional_categories,
			'S_CONSENTMANAGER_ANALYTICS_ENABLED'	=> !empty($categories['analytics']['enabled']),
			'S_CONSENTMANAGER_MARKETING_ENABLED'	=> !empty($categories['marketing']['enabled']),
			'S_CONSENTMANAGER_MEDIA_ENABLED'		=> !empty($categories['media']['enabled']),
			'CONSENTMANAGER_PAYLOAD'				=> $has_optional_categories ? json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) : '',
		];

		// Override phpBB's cookie consent banner when Consent Manager is enabled
		if ($has_optional_categories)
		{
			$vars['S_COOKIE_NOTICE'] = false;
		}

		return $vars;
	}

	/**
	 * Build template variable data for categories and services in the frontend consent UI.
	 *
	 * Returns an array of enabled categories, each with a nested 'services' array,
	 * shaped for use with assign_block_vars('CONSENTMANAGER_CATEGORIES', ...) and
	 * assign_block_vars('CONSENTMANAGER_CATEGORIES.CONSENTMANAGER_SERVICES', ...).
	 *
	 * @return array
	 */
	public function get_frontend_category_data()
	{
		$services_by_category = [];
		foreach ($this->get_services() as $service)
		{
			$services_by_category[$service['category']][] = $service;
		}

		$result = [];
		foreach ($this->get_categories() as $category)
		{
			if (!$category['enabled'])
			{
				continue;
			}

			$category_services = [];
			foreach ($services_by_category[$category['id']] ?? [] as $service)
			{
				$category_services[] = [
					'LABEL'       => $service['label'],
					'DESCRIPTION' => $service['description'],
				];
			}

			$result[] = [
				'ID'          => $category['id'],
				'LABEL'       => $category['label'],
				'DESCRIPTION' => $category['description'],
				'REQUIRED'    => $category['required'],
				'services'    => $category_services,
			];
		}

		return $result;
	}

	/**
	 * Validate a consent logging payload from the frontend.
	 *
	 * @param array $payload Submitted payload
	 *
	 * @return array
	 */
	public function validate_log_payload(array $payload)
	{
		$hash = isset($payload['hash']) ? (string) $payload['hash'] : '';
		if (!check_link_hash($hash, 'phpbb.consentmanager.log'))
		{
			return [
				'success' => false,
				'error' => 'invalid_hash',
			];
		}

		$version = isset($payload['version']) ? (int) $payload['version'] : 0;
		if ($version !== $this->get_version())
		{
			return [
				'success' => false,
				'error' => 'version_mismatch',
			];
		}

		return [
			'success' => true,
			'categories' => $this->normalize_categories(
				isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : []
			),
			'version' => $version,
		];
	}

	/**
	 * Build the frontend consent manager payload.
	 *
	 * @param string $log_url Consent logging endpoint URL
	 * @param string $log_hash Link hash for consent logging
	 *
	 * @return array
	 */
	public function build_frontend_payload($log_url, $log_hash)
	{
		$categories = $this->get_category_config();
		$services = $this->get_services();
		$scripts = [];

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

		return [
			'storageKey' => $this->get_storage_key(),
			'cookieName' => $this->get_cookie_name(),
			'version' => $this->get_version(),
			'requiredCategories' => $this->get_required_category_ids($categories),
			'enabledCategories' => $this->get_enabled_category_ids($categories),
			'optionalCategories' => $this->get_optional_category_ids($categories),
			'categories' => $this->get_frontend_payload_categories($categories),
			'scripts' => array_values($scripts),
			'logEndpoint' => $log_url,
			'logHash' => $log_hash,
		];
	}

	/**
	 * Return the consent categories exposed to the frontend.
	 *
	 * @return array
	 */
	public function get_categories()
	{
		$lang_keys = [
			'necessary' => ['CONSENTMANAGER_CATEGORY_NECESSARY', 'CONSENTMANAGER_CATEGORY_NECESSARY_EXPLAIN'],
			'analytics' => ['CONSENTMANAGER_CATEGORY_ANALYTICS', 'CONSENTMANAGER_CATEGORY_ANALYTICS_EXPLAIN'],
			'marketing' => ['CONSENTMANAGER_CATEGORY_MARKETING', 'CONSENTMANAGER_CATEGORY_MARKETING_EXPLAIN'],
			'media' => ['CONSENTMANAGER_CATEGORY_MEDIA', 'CONSENTMANAGER_CATEGORY_MEDIA_EXPLAIN'],
		];
		$categories = [];

		foreach ($this->get_category_config() as $id => $category)
		{
			[$label_key, $description_key] = $lang_keys[$id];
			$categories[$id] = $category + [
				'label' => $this->language->lang($label_key),
				'description' => $this->language->lang($description_key),
			];
		}

		return $categories;
	}

	/**
	 * Return registered services that are active for the current configuration.
	 *
	 * @return array
	 */
	public function get_services()
	{
		if ($this->services !== null)
		{
			return $this->services;
		}

		$this->collect_registrations();

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

		return $this->services = $services;
	}

	/**
	 * Return integrations configured through ACP storage.
	 *
	 * @return array
	 */
	public function get_configured_integrations()
	{
		if ($this->configured_integrations !== null)
		{
			return $this->configured_integrations;
		}

		$raw = $this->config_text->get('consentmanager_integrations');
		if ($raw === '')
		{
			return $this->configured_integrations = [];
		}

		$fingerprint = $this->get_integrations_cache_fingerprint($raw);
		$cached = $this->consent_cache->get_integrations($fingerprint);
		if ($cached !== null)
		{
			return $this->configured_integrations = $cached;
		}

		$errors = [];
		$integrations = $this->normalize_integrations($raw, $errors);
		$this->configured_integrations = empty($errors) ? $integrations : [];

		$this->consent_cache->put_integrations($fingerprint, $this->configured_integrations);

		return $this->configured_integrations;
	}

	/**
	 * Normalize integrations configured through the ACP JSON textarea.
	 *
	 * Inline JavaScript is intentionally not supported here, so ACP-managed
	 * integrations cannot introduce arbitrary executable code.
	 *
	 * @param string|array $input Raw JSON or pre-decoded integrations
	 * @param array        $errors Validation errors
	 * @return array
	 */
	public function normalize_integrations($input, array &$errors = [])
	{
		if (is_string($input))
		{
			$trimmed = trim($input);
			if ($trimmed === '')
			{
				return [];
			}

			$decoded = json_decode($trimmed, true);
			if (json_last_error() !== JSON_ERROR_NONE)
			{
				$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
				return [];
			}
		}
		else
		{
			$decoded = $input;
		}

		if (empty($decoded))
		{
			return [];
		}

		if (!is_array($decoded))
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
			return [];
		}

		if (array_keys($decoded) !== range(0, count($decoded) - 1))
		{
			$errors[] = $this->language->lang('ACP_CONSENTMANAGER_INVALID_INTEGRATIONS');
			return [];
		}

		$integrations = [];

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

			$integrations[$id] = [
				'id' => $id,
				'label' => $label !== '' ? $label : $id,
				'category' => $category,
				'description' => $description,
				'scripts' => [
					[
						'id' => $id,
						'category' => $category,
						'src' => $src,
						'inline' => '',
						'async' => !empty($item['async']),
						'defer' => !empty($item['defer']),
						'attributes' => [],
					],
				],
			];
		}

		return array_values($integrations);
	}

	/**
	 * Normalize category selections to valid enabled category ids.
	 *
	 * @param array $categories Submitted category ids
	 *
	 * @return array
	 */
	public function normalize_categories(array $categories)
	{
		$normalized = ['necessary'];

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

	/**
	 * Return the client-side storage key for consent data.
	 *
	 * @return string
	 */
	public function get_storage_key()
	{
		return self::STORAGE_KEY;
	}

	/**
	 * Return the consent cookie name.
	 *
	 * @return string
	 */
	public function get_cookie_name()
	{
		return self::COOKIE_NAME;
	}

	/**
	 * Return the current consent version.
	 *
	 * @return int
	 */
	public function get_version()
	{
		return (int) $this->config['consentmanager_consent_version'];
	}

	/**
	 * Determine whether a category is enabled in the current configuration.
	 *
	 * @param string $category Category identifier
	 *
	 * @return bool
	 */
	public function is_category_enabled($category)
	{
		$categories = $this->get_category_config();
		return isset($categories[$category]) && $categories[$category]['enabled'];
	}

	/**
	 * Determine whether any optional consent categories are enabled.
	 *
	 * @return bool
	 */
	public function has_optional_categories()
	{
		return !empty($this->get_optional_category_ids($this->get_category_config()));
	}

	/**
	 * Determine whether a valid consent cookie currently grants the given category.
	 *
	 * @param string $category Category identifier
	 *
	 * @return bool
	 */
	public function has_server_consent($category)
	{
		if (!$this->is_supported_category($category))
		{
			return false;
		}

		$categories = $this->get_category_config();
		if (!empty($categories[$category]['required']))
		{
			return true;
		}

		if (!$this->is_category_enabled($category))
		{
			return false;
		}

		$state = $this->get_server_consent_state();

		return !empty($state) && in_array($category, $state['categories'], true);
	}

	/**
	 * Determine whether a category identifier is supported.
	 *
	 * @param string $category Category identifier
	 *
	 * @return bool
	 */
	public function is_supported_category($category)
	{
		return in_array($category, ['necessary', 'analytics', 'marketing', 'media'], true);
	}

	/**
	 * Return the validated consent state from the current request cookie.
	 *
	 * @return array|null
	 */
	protected function get_server_consent_state()
	{
		if ($this->server_consent_state_loaded)
		{
			return $this->server_consent_state;
		}

		$this->server_consent_state_loaded = true;
		$this->server_consent_state = null;

		$raw = $this->request->raw_variable(self::COOKIE_NAME, '', request_interface::COOKIE);
		if (!is_string($raw) || $raw === '')
		{
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)
			|| !isset($decoded['version'], $decoded['categories'])
			|| (int) $decoded['version'] !== $this->get_version()
			|| !is_array($decoded['categories']))
		{
			return null;
		}

		$this->server_consent_state = [
			'categories' => $this->normalize_categories($decoded['categories']),
			'version' => (int) $decoded['version'],
		];

		return $this->server_consent_state;
	}

	/**
	 * Allow extensions to register consent-aware integrations.
	 *
	 * @return void
	 * @noinspection PhpVarTagWithoutVariableNameInspection
	 * @noinspection PhpUnusedLocalVariableInspection
	 * @noinspection PassingByReferenceCorrectnessInspection
	 */
	protected function collect_registrations()
	{
		if ($this->registrations_collected)
		{
			return;
		}

		$this->registrations_collected = true;
		$consent_manager = $this;

		/**
		* Event to allow extensions to register consent-aware integrations.
		*
		* @event phpbb.consentmanager.collect_registrations
		* @var object consent_manager Consent manager service
		* @since 1.0.0
		*/
		$vars = ['consent_manager'];
		extract($this->dispatcher->trigger_event('phpbb.consentmanager.collect_registrations', compact($vars)));
	}

	/**
	 * Return category ids that are always required.
	 *
	 * @param array $categories Category metadata
	 *
	 * @return array
	 */
	protected function get_required_category_ids(array $categories)
	{
		$required_categories = [];

		foreach ($categories as $category)
		{
			if (!empty($category['required']))
			{
				$required_categories[] = $category['id'];
			}
		}

		return array_values($required_categories);
	}

	/**
	 * Return category ids that are currently enabled.
	 *
	 * @param array $categories Category metadata
	 *
	 * @return array
	 */
	protected function get_enabled_category_ids(array $categories)
	{
		$enabled_categories = [];

		foreach ($categories as $category)
		{
			if (!empty($category['enabled']))
			{
				$enabled_categories[] = $category['id'];
			}
		}

		return array_values($enabled_categories);
	}

	/**
	 * Return category ids that are optional and enabled.
	 *
	 * @param array $categories Category metadata
	 *
	 * @return array
	 */
	protected function get_optional_category_ids(array $categories)
	{
		$optional_categories = [];

		foreach ($categories as $category)
		{
			if (!empty($category['enabled']) && empty($category['required']))
			{
				$optional_categories[] = $category['id'];
			}
		}

		return array_values($optional_categories);
	}

	/**
	 * Return the category metadata required by the frontend payload.
	 *
	 * @param array $categories Category metadata
	 *
	 * @return array
	 */
	protected function get_frontend_payload_categories(array $categories)
	{
		$payload_categories = [];

		foreach ($categories as $category)
		{
			$payload_categories[] = [
				'id' => $category['id'],
				'required' => !empty($category['required']),
				'enabled' => !empty($category['enabled']),
			];
		}

		return $payload_categories;
	}

	/**
	 * Return the category state/config data independent of language loading.
	 *
	 * @return array
	 */
	protected function get_category_config()
	{
		return $this->category_config ?? ($this->category_config = [
			'necessary' => [
				'id' => 'necessary',
				'required' => true,
				'enabled' => true,
			],
			'analytics' => [
				'id' => 'analytics',
				'required' => false,
				'enabled' => (bool) $this->config['consentmanager_analytics_enabled'],
			],
			'marketing' => [
				'id' => 'marketing',
				'required' => false,
				'enabled' => (bool) $this->config['consentmanager_marketing_enabled'],
			],
			'media' => [
				'id' => 'media',
				'required' => false,
				'enabled' => (bool) $this->config['consentmanager_media_enabled'],
			],
		]);
	}

	/**
	 * Normalize a script definition for frontend execution.
	 *
	 * @param string $registration_id Parent registration identifier
	 * @param string $fallback_category Fallback category identifier
	 * @param array  $definition Raw script definition
	 * @param int    $script_index Script position within the registration
	 * @param bool   $generate_default_id Whether to generate a default script id from the registration id
	 *
	 * @return array
	 */
	protected function normalize_script($registration_id, $fallback_category, array $definition, $script_index = 0, $generate_default_id = false)
	{
		$category = isset($definition['category']) && trim((string) $definition['category']) !== '' ? trim((string) $definition['category']) : $fallback_category;
		if (!$this->is_supported_category($category))
		{
			return [];
		}

		$script_id = isset($definition['id']) && trim((string) $definition['id']) !== '' ? trim((string) $definition['id']) : $registration_id;
		if (!isset($definition['id']) || trim((string) $definition['id']) === '')
		{
			$script_id = ($generate_default_id || $script_index > 0) ? $registration_id . '.' . ($script_index + 1) : $registration_id;
		}
		else if ($script_id === $registration_id && $script_index > 0)
		{
			$script_id = $registration_id . '.' . ($script_index + 1);
		}

		if (!$this->is_valid_identifier($script_id))
		{
			return [];
		}

		$src = isset($definition['src']) ? trim((string) $definition['src']) : '';
		$inline = isset($definition['inline']) ? trim((string) $definition['inline']) : '';
		$asset_path = isset($definition['asset']) ? trim((string) $definition['asset']) : '';
		$defined_sources = 0;

		if ($src !== '')
		{
			$defined_sources++;
		}

		if ($inline !== '')
		{
			$defined_sources++;
		}

		if ($asset_path !== '')
		{
			$defined_sources++;
		}

		if ($defined_sources !== 1)
		{
			return [];
		}

		if ($src !== '' && !$this->is_valid_script_source($src))
		{
			return [];
		}

		if ($asset_path !== '')
		{
			$src = $this->resolve_local_asset_source($asset_path);
			if ($src === '')
			{
				return [];
			}
		}

		return [
			'id' => $script_id,
			'category' => $category,
			'src' => $src,
			'inline' => $inline,
			'async' => isset($definition['async']) ? (bool) $definition['async'] : ($src !== ''),
			'defer' => isset($definition['defer']) ? (bool) $definition['defer'] : false,
			'wait_for_dom_ready' => !empty($definition['wait_for_dom_ready']),
			'attributes' => $this->normalize_attributes($definition['attributes'] ?? []),
		];
	}

	/**
	 * Normalize arbitrary script attributes to a safe subset.
	 *
	 * @param mixed $attributes Raw attribute map
	 *
	 * @return array
	 */
	protected function normalize_attributes($attributes)
	{
		if (!is_array($attributes))
		{
			return [];
		}

		$normalized = [];
		foreach ($attributes as $name => $value)
		{
			$name = trim((string) $name);
			if ($name === ''
				|| !preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', $name)
				|| 0 === stripos($name, 'on')
				|| in_array(strtolower($name), ['src', 'type', 'async', 'defer'], true))
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

	/**
	 * Determine whether a remote script source URL is allowed.
	 *
	 * @param string $src Script source URL
	 *
	 * @return bool
	 */
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

		return !isset($parts['scheme']) || in_array(strtolower($parts['scheme']), ['http', 'https'], true);
	}

	/**
	 * Resolve a local asset path to a frontend URL.
	 *
	 * @param string $asset_path Local asset path
	 *
	 * @return string
	 */
	protected function resolve_local_asset_source($asset_path)
	{
		if (array_key_exists($asset_path, $this->local_asset_sources))
		{
			return $this->local_asset_sources[$asset_path];
		}

		$template_asset = new asset($asset_path, $this->path_helper, $this->filesystem);
		if (!$this->is_valid_local_asset_path($asset_path) || !$template_asset->is_relative())
		{
			return $this->local_asset_sources[$asset_path] = '';
		}

		if (strpos($asset_path, './') !== 0)
		{
			$local_file = $this->twig_environment->get_phpbb_root_path() . $template_asset->get_path();
			if (!file_exists($local_file))
			{
				try
				{
					$local_file = $this->twig_environment->findTemplate($template_asset->get_path());
					$template_asset->set_path($local_file, true);
				}
				catch (LoaderError $error)
				{
					return $this->local_asset_sources[$asset_path] = '';
				}
			}
		}

		if ($template_asset->is_relative())
		{
			$template_asset->add_assets_version($this->config['assets_version']);
		}

		return $this->local_asset_sources[$asset_path] = html_entity_decode($template_asset->get_url(), ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Determine whether a local asset path is safe to resolve.
	 *
	 * @param string $asset_path Local asset path
	 *
	 * @return bool
	 */
	protected function is_valid_local_asset_path($asset_path)
	{
		if ($asset_path === '' || preg_match('/[<>"\']/', $asset_path))
		{
			return false;
		}

		$parts = parse_url($asset_path);
		if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || strpos($asset_path, '//') === 0)
		{
			return false;
		}

		return isset($parts['path']) && $parts['path'] !== '' && strpos($parts['path'], '/') !== 0;
	}

	/**
	 * Determine whether an identifier is valid for registrations and scripts.
	 *
	 * @param string $identifier Identifier to validate
	 *
	 * @return bool
	 */
	protected function is_valid_identifier($identifier)
	{
		return $identifier !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $identifier);
	}

	/**
	 * Build the persistent cache fingerprint used for normalized integrations.
	 *
	 * @param string $raw Raw integrations JSON
	 *
	 * @return string
	 */
	protected function get_integrations_cache_fingerprint($raw)
	{
		return hash('sha256', (string) $raw);
	}

	/**
	 * Return all currently registered script ids, excluding one registration if requested.
	 *
	 * @param string|null $exclude_registration_id Registration id to ignore
	 *
	 * @return array<string, bool>
	 */
	protected function get_registered_script_ids($exclude_registration_id = null)
	{
		$script_ids = [];

		foreach ($this->registrations as $registration_id => $registration)
		{
			if ($exclude_registration_id !== null && $registration_id === $exclude_registration_id)
			{
				continue;
			}

			foreach ($registration['scripts'] as $script)
			{
				$script_ids[$script['id']] = true;
			}
		}

		return $script_ids;
	}
}
