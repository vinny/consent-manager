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

use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Configurator\Helpers\TemplateLoader;

class media_manager
{
	public const MEDIA_ALLOWED_PARAMETER = 'S_CONSENTMANAGER_MEDIA_ALLOWED';
	public const XSL_NAMESPACE = 'http://www.w3.org/1999/XSL/Transform';

	/** @var consent_manager_interface */
	protected $consent_manager;

	/** @var array<string, string> */
	protected $template_cache = [];

	/**
	 * Constructor.
	 *
	 * @param consent_manager_interface $consent_manager Consent manager service
	 */
	public function __construct(consent_manager_interface $consent_manager)
	{
		$this->consent_manager = $consent_manager;
	}

	/**
	 * Transform s9e-rendered iframe output into consent-aware placeholders.
	 *
	 * @param Configurator $configurator Text formatter configurator
	 *
	 * @return void
	 */
	public function configure_iframe_embeds(Configurator $configurator)
	{
		if (!$this->consent_manager->is_category_enabled(consent_manager_interface::MEDIA_CATEGORY))
		{
			return;
		}

		foreach ($configurator->tags as $tag)
		{
			$template_source = (string) $tag->template;

			if ($template_source === '' || stripos($template_source, 'iframe') === false)
			{
				continue;
			}

			$template = $this->build_iframe_placeholder_template($template_source);
			if ($template === $template_source)
			{
				continue;
			}

			$tag->template = $template;
			$configurator->templateNormalizer->normalizeTag($tag);
			$configurator->templateChecker->checkTag($tag);
		}
	}

	/**
	 * Pass the current request's media consent state into the s9e renderer.
	 *
	 * @param \phpbb\textformatter\s9e\renderer $renderer phpBB renderer wrapper
	 *
	 * @return void
	 */
	public function configure_iframe_renderer($renderer)
	{
		$renderer->get_renderer()->setParameter(
			self::MEDIA_ALLOWED_PARAMETER,
			$this->consent_manager->has_server_consent(consent_manager_interface::MEDIA_CATEGORY) ? '1' : ''
		);
	}

	/**
	 * Rewrite the s9e template so iframe src attributes are deferred until consent is granted.
	 *
	 * @param string $template Original s9e template
	 *
	 * @return string
	 */
	protected function build_iframe_placeholder_template($template)
	{
		if (isset($this->template_cache[$template]))
		{
			return $this->template_cache[$template];
		}

		$dom = TemplateLoader::load($template);
		$iframes = $this->get_iframe_nodes($dom);
		if (!$iframes)
		{
			return $this->template_cache[$template] = $template;
		}

		$allowed_template = $template;
		if (strpos($template, 'data-s9e-') !== false)
		{
			$allowed_dom = clone $dom;
			$this->strip_internal_s9e_attributes($allowed_dom);
			$allowed_template = TemplateLoader::save($allowed_dom);
		}

		$media_roots = [];

		foreach ($iframes as $iframe)
		{
			$this->rewrite_iframe_node($iframe);

			$media_root = $this->get_media_root($iframe);
			if ($media_root !== null)
			{
				$media_roots[spl_object_id($media_root)] = $media_root;
			}
		}

		if (!$media_roots)
		{
			return $this->template_cache[$template] = $template;
		}

		foreach ($media_roots as $media_root)
		{
			$this->wrap_media_root($dom, $media_root);
		}

		$this->strip_internal_s9e_attributes($dom);
		$blocked_template = TemplateLoader::save($dom);

		return $this->template_cache[$template] = '<xsl:choose>'
			. '<xsl:when test="$' . self::MEDIA_ALLOWED_PARAMETER . '">' . $allowed_template . '</xsl:when>'
			. '<xsl:otherwise>' . $blocked_template . '</xsl:otherwise>'
			. '</xsl:choose>';
	}

	/**
	 * Return the non-XSL iframe nodes present in a template DOM.
	 *
	 * @param \DOMDocument $dom Template DOM
	 *
	 * @return \DOMElement[]
	 */
	protected function get_iframe_nodes(\DOMDocument $dom)
	{
		$iframes = [];

		foreach ($dom->getElementsByTagName('iframe') as $iframe)
		{
			if ($iframe instanceof \DOMElement && $iframe->namespaceURI !== self::XSL_NAMESPACE)
			{
				$iframes[] = $iframe;
			}
		}

		return $iframes;
	}

	/**
	 * Rewrite a single iframe node so its live attributes are deferred until consent exists.
	 *
	 * @param \DOMElement $iframe Iframe element
	 *
	 * @return void
	 */
	protected function rewrite_iframe_node(\DOMElement $iframe)
	{
		if ($iframe->hasAttribute('src'))
		{
			$iframe->setAttribute('data-consent-src', $iframe->getAttribute('src'));
			$iframe->removeAttribute('src');
		}

		if ($iframe->hasAttribute('onload'))
		{
			$iframe->setAttribute('data-consent-onload', $iframe->getAttribute('onload'));
			$iframe->removeAttribute('onload');
		}

		foreach ($iframe->childNodes as $child_node)
		{
			if (!$child_node instanceof \DOMElement
				|| $child_node->namespaceURI !== self::XSL_NAMESPACE
				|| $child_node->localName !== 'attribute'
			)
			{
				continue;
			}

			$name = $child_node->getAttribute('name');
			if ($name === 'src' || $name === 'onload')
			{
				$child_node->setAttribute('name', 'data-consent-' . $name);
			}
		}

		$iframe->setAttribute('data-consent-media-frame', '1');
	}

	/**
	 * Return the topmost non-XSL ancestor for a consent-managed iframe subtree.
	 *
	 * @param \DOMElement $iframe Iframe element
	 *
	 * @return \DOMElement|null
	 */
	protected function get_media_root(\DOMElement $iframe)
	{
		$media_root = $iframe;

		while ($media_root->parentNode instanceof \DOMElement && $media_root->parentNode->namespaceURI !== self::XSL_NAMESPACE)
		{
			$media_root = $media_root->parentNode;
		}

		return $media_root;
	}

	/**
	 * Wrap a media subtree in placeholder markup for blocked-consent rendering.
	 *
	 * @param \DOMDocument $dom        Template DOM
	 * @param \DOMElement  $media_root Top-level media subtree
	 *
	 * @noinspection PhpUnhandledExceptionInspection,PhpDocMissingThrowsInspection
	 *
	 * @return void
	 */
	protected function wrap_media_root(\DOMDocument $dom, \DOMElement $media_root)
	{
		$container = $dom->createElement('span');
		$container->setAttribute('data-consent-media-container', '1');
		$container->setAttribute('data-consent-category', consent_manager_interface::MEDIA_CATEGORY);

		$placeholder = $dom->createElement('span');
		$placeholder->setAttribute('data-consent-media-placeholder', '1');
		$link_attribute = $dom->createElementNS(self::XSL_NAMESPACE, 'xsl:attribute');
		$link_attribute->setAttribute('name', 'data-consent-link');
		$link_value = $dom->createElementNS(self::XSL_NAMESPACE, 'xsl:value-of');
		$link_value->setAttribute('select', '.');
		$link_attribute->appendChild($link_value);
		$placeholder->appendChild($link_attribute);

		$media_root->setAttribute('data-consent-media-content', '1');
		$media_root->setAttribute('hidden', 'hidden');

		$parent = $media_root->parentNode;
		$parent->replaceChild($container, $media_root);
		$container->appendChild($placeholder);
		$container->appendChild($media_root);
	}

	/**
	 * Remove s9e internal data attributes that are not valid in rewritten templates.
	 *
	 * @param \DOMDocument $dom Template DOM
	 *
	 * @return void
	 */
	protected function strip_internal_s9e_attributes(\DOMDocument $dom)
	{
		$elements = [];

		foreach ($dom->getElementsByTagName('*') as $element)
		{
			$elements[] = $element;
		}

		foreach ($elements as $element)
		{
			if ($element->namespaceURI === self::XSL_NAMESPACE
				&& $element->localName === 'attribute'
				&& strpos($element->getAttribute('name'), 'data-s9e-') === 0
			)
			{
				$element->parentNode->removeChild($element);
				continue;
			}

			if (!$element->hasAttributes())
			{
				continue;
			}

			$attribute_names = [];

			foreach ($element->attributes as $attribute)
			{
				if (strpos($attribute->name, 'data-s9e-') === 0)
				{
					$attribute_names[] = $attribute->name;
				}
			}

			foreach ($attribute_names as $attribute_name)
			{
				$element->removeAttribute($attribute_name);
			}
		}
	}
}
