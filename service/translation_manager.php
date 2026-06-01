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

use phpbb\db\driver\driver_interface;
use phpbb\language\language;

class translation_manager
{
	public const MAX_TRANSLATION_LENGTH = 4000;

	public const BANNER_FIELDS = [
		'banner_title' => [
			'fallback' => 'CONSENTMANAGER_DEFAULT_BANNER_TITLE',
			'label' => 'ACP_CONSENTMANAGER_BANNER_TITLE',
		],
		'banner_message' => [
			'fallback' => 'CONSENTMANAGER_DEFAULT_BANNER_TEXT',
			'label' => 'ACP_CONSENTMANAGER_BANNER_MESSAGE',
		],
		'banner_subtext' => [
			'fallback' => 'CONSENTMANAGER_DEFAULT_BANNER_SUBTEXT',
			'label' => 'ACP_CONSENTMANAGER_BANNER_SUBTEXT',
		],
	];

	/** @var driver_interface */
	protected $db;

	/** @var consent_cache */
	protected $consent_cache;

	/** @var language */
	protected $language;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var string */
	protected $translations_table;

	/** @var array|null */
	protected $translations;

	/** @var array */
	protected $language_defaults = [];

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db Database connection
	 * @param consent_cache    $consent_cache Persistent cache helper
	 * @param language         $language Language service
	 * @param string           $root_path phpBB root path
	 * @param string           $php_ext PHP file extension
	 * @param string           $translations_table Translation table name
	 */
	public function __construct(driver_interface $db, consent_cache $consent_cache, language $language, $root_path, $php_ext, $translations_table)
	{
		$this->db = $db;
		$this->consent_cache = $consent_cache;
		$this->language = $language;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
		$this->translations_table = $translations_table;
	}

	/**
	 * Return a raw translation, falling back to the extension language file.
	 *
	 * @param string      $translation_key Stored translation key
	 * @param string      $fallback_lang_key Extension language fallback key
	 * @param string|null $lang_iso Language ISO code, or current user language
	 *
	 * @return string
	 */
	public function get_translation($translation_key, $fallback_lang_key, $lang_iso = null)
	{
		$row = $this->get_custom_translation_row($translation_key, $lang_iso ?: $this->language->get_used_language());

		if ($row !== null)
		{
			return utf8_decode_ncr($row['translation_text']);
		}

		return $lang_iso === null
			? $this->language->lang($fallback_lang_key)
			: $this->get_language_default($lang_iso, $fallback_lang_key);
	}

	/**
	 * Return a translation rendered for the template output.
	 *
	 * @param string      $translation_key Stored translation key
	 * @param string      $fallback_lang_key Extension language fallback key
	 * @param string|null $lang_iso Language ISO code, or current user language
	 *
	 * @return string
	 */
	public function get_translation_for_display($translation_key, $fallback_lang_key, $lang_iso = null)
	{
		$row = $this->get_custom_translation_row($translation_key, $lang_iso ?: $this->language->get_used_language());

		if ($row !== null)
		{
			return generate_text_for_display(
				$row['translation_text_parsed'],
				$row['translation_uid'],
				$row['translation_bitfield'],
				(int) $row['translation_options']
			);
		}

		return $this->language->lang($fallback_lang_key);
	}

	/**
	 * Build template variables for the ACP consent text page.
	 *
	 * @param array|null $submitted_translations Submitted values to preserve after errors
	 *
	 * @return array
	 */
	public function get_banner_template_data(array $submitted_translations = null)
	{
		$fields = [];
		foreach (self::BANNER_FIELDS as $translation_key => $definition)
		{
			$fields[] = [
				'KEY' => $translation_key,
				'LABEL' => $this->language->lang($definition['label']),
			];
		}

		$languages = [];
		foreach ($this->get_installed_languages() as $language)
		{
			$translations = [];
			foreach (self::BANNER_FIELDS as $translation_key => $definition)
			{
				$translations[] = [
					'KEY' => $translation_key,
					'VALUE' => $submitted_translations !== null
						? (string) ($submitted_translations[$language['lang_iso']][$translation_key] ?? '')
						: $this->get_translation($translation_key, $definition['fallback'], $language['lang_iso']),
				];
			}

			$languages[] = [
				'ISO' => $language['lang_iso'],
				'NAME' => $language['lang_local_name'],
				'TRANSLATIONS' => $translations,
			];
		}

		return [
			'CONSENTMANAGER_BANNER_FIELDS' => $fields,
			'CONSENTMANAGER_BANNER_LANGUAGES' => $languages,
		];
	}

	/**
	 * Persist administrator-defined translations.
	 *
	 * Blank values remove any custom translation and restore language fallback.
	 *
	 * @param array $submitted_translations Submitted translations
	 * @param array $allowed_keys Allowed translation keys
	 * @param array $errors Validation errors
	 *
	 * @return bool
	 */
	public function save_translations(array $submitted_translations, array $allowed_keys, array &$errors = [])
	{
		$errors = [];
		$installed_languages = array_column($this->get_installed_languages(), 'lang_iso');
		$allowed_languages = array_fill_keys($installed_languages, true);
		$allowed_keys = array_intersect($allowed_keys, array_keys(self::BANNER_FIELDS));
		$allowed_keys = array_fill_keys($allowed_keys, true);

		foreach ($submitted_translations as $lang_iso => $translations)
		{
			if (!isset($allowed_languages[$lang_iso]) || !is_array($translations))
			{
				continue;
			}

			foreach ($translations as $translation_key => $translation_text)
			{
				if (!isset($allowed_keys[$translation_key]))
				{
					continue;
				}

				$translation_text = trim((string) $translation_text);
				if ($translation_text === '' || $translation_text === $this->get_language_default($lang_iso, self::BANNER_FIELDS[$translation_key]['fallback']))
				{
					$this->delete_translation($translation_key, $lang_iso);
					continue;
				}

				if (utf8_strlen($translation_text) > self::MAX_TRANSLATION_LENGTH)
				{
					$errors[] = $this->language->lang('ACP_CONSENTMANAGER_BANNER_TEXT_TOO_LONG', self::MAX_TRANSLATION_LENGTH);
					continue;
				}

				$parsed_text = $translation_text;
				$uid = $bitfield = '';
				$options = 0;
				$parse_errors = generate_text_for_storage($parsed_text, $uid, $bitfield, $options, true, true, true, false, false, false, true);

				if (!empty($parse_errors))
				{
					$errors = array_merge($errors, $parse_errors);
					continue;
				}

				$this->upsert_translation($translation_key, $lang_iso, $translation_text, $parsed_text, $uid, $bitfield, $options);
			}
		}

		if (!empty($errors))
		{
			return false;
		}

		$this->translations = null;
		$this->consent_cache->invalidate_translations();

		return true;
	}

	/**
	 * Return installed phpBB languages.
	 *
	 * @return array
	 */
	public function get_installed_languages()
	{
		$sql = 'SELECT lang_iso, lang_local_name
			FROM ' . LANG_TABLE . '
			ORDER BY lang_english_name';
		$result = $this->db->sql_query($sql);
		$languages = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $languages;
	}

	/**
	 * Return cached custom translations indexed by translation key and language.
	 *
	 * @return array
	 */
	protected function get_custom_translations()
	{
		if ($this->translations !== null)
		{
			return $this->translations;
		}

		$cached = $this->consent_cache->get_translations();
		if ($cached !== null)
		{
			return $this->translations = $cached;
		}

		$translations = [];
		$sql = 'SELECT translation_key, lang_iso, translation_text, translation_text_parsed, translation_uid, translation_bitfield, translation_options
			FROM ' . $this->translations_table;
		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$text = trim((string) $row['translation_text']);
			if ($text === '')
			{
				continue;
			}

			$translations[$row['translation_key']][$row['lang_iso']] = $row;
		}

		$this->db->sql_freeresult($result);
		$this->consent_cache->put_translations($translations);

		return $this->translations = $translations;
	}

	/**
	 * Return a custom translation row or null.
	 *
	 * @param string $translation_key Translation key
	 * @param string $lang_iso Language ISO
	 *
	 * @return array|null
	 */
	protected function get_custom_translation_row($translation_key, $lang_iso)
	{
		$translations = $this->get_custom_translations();

		return $translations[$translation_key][$lang_iso] ?? null;
	}

	/**
	 * Insert or update a custom translation.
	 *
	 * @return void
	 */
	protected function upsert_translation($translation_key, $lang_iso, $translation_text, $parsed_text, $uid, $bitfield, $options)
	{
		$sql_ary = [
			'translation_key' => $translation_key,
			'lang_iso' => $lang_iso,
			'translation_text' => utf8_encode_ucr($translation_text),
			'translation_text_parsed' => $parsed_text,
			'translation_uid' => $uid,
			'translation_bitfield' => $bitfield,
			'translation_options' => (int) $options,
			'updated_at' => time(),
		];

		$sql = 'SELECT translation_id
			FROM ' . $this->translations_table . "
			WHERE translation_key = '" . $this->db->sql_escape($translation_key) . "'
				AND lang_iso = '" . $this->db->sql_escape($lang_iso) . "'";
		$result = $this->db->sql_query($sql);
		$translation_id = (int) $this->db->sql_fetchfield('translation_id');
		$this->db->sql_freeresult($result);

		if ($translation_id)
		{
			$sql = 'UPDATE ' . $this->translations_table . '
				SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
				WHERE translation_id = ' . $translation_id;
		}
		else
		{
			$sql = 'INSERT INTO ' . $this->translations_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
		}

		$this->db->sql_query($sql);
	}

	/**
	 * Delete a custom translation.
	 *
	 * @param string $translation_key Translation key
	 * @param string $lang_iso Language ISO
	 *
	 * @return void
	 */
	protected function delete_translation($translation_key, $lang_iso)
	{
		$sql = 'DELETE FROM ' . $this->translations_table . "
			WHERE translation_key = '" . $this->db->sql_escape($translation_key) . "'
				AND lang_iso = '" . $this->db->sql_escape($lang_iso) . "'";
		$this->db->sql_query($sql);
	}

	/**
	 * Return an extension language-file default for a specific language.
	 *
	 * @param string $lang_iso Language ISO
	 * @param string $lang_key Language key
	 *
	 * @return string
	 */
	protected function get_language_default($lang_iso, $lang_key)
	{
		$lang_iso = basename($lang_iso);
		if (!isset($this->language_defaults[$lang_iso]))
		{
			$this->language_defaults[$lang_iso] = $this->load_common_language_file($lang_iso);
		}

		return $this->language_defaults[$lang_iso][$lang_key] ?? $lang_key;
	}

	/**
	 * Load extension common language defaults for a language, falling back to English.
	 *
	 * @param string $lang_iso Language ISO
	 *
	 * @return array
	 */
	protected function load_common_language_file($lang_iso)
	{
		$lang = [];
		$path = $this->root_path . 'ext/phpbb/consentmanager/language/' . $lang_iso . '/common.' . $this->php_ext;

		if (!preg_match('/^[a-z0-9_-]+$/i', $lang_iso) || !file_exists($path))
		{
			$path = $this->root_path . 'ext/phpbb/consentmanager/language/en/common.' . $this->php_ext;
		}

		if (file_exists($path))
		{
			include($path);
		}

		return $lang;
	}
}
