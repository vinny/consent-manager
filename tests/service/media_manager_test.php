<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\service;

class media_manager_test extends \phpbb_test_case
{
	/** @var \phpbb\consentmanager\service\consent_manager_interface|\PHPUnit\Framework\MockObject\MockObject */
	protected $consent_manager;

	/** @var \phpbb\consentmanager\service\media_manager */
	protected $manager;

	protected function setUp(): void
	{
		parent::setUp();

		$this->consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$this->manager = new \phpbb\consentmanager\service\media_manager($this->consent_manager);
	}

	public function test_configure_iframe_embeds_skips_when_media_category_is_disabled()
	{
		$this->expect_media_enabled(false);

		$configurator = $this->create_configurator_with_tag(
			'CUSTOM',
			'<div class="custom-embed"><iframe src="https://video.example.com/embed/123"></iframe></div>'
		);
		$template = $configurator->tags['CUSTOM']->template;

		$this->manager->configure_iframe_embeds($configurator);

		self::assertSame($template, $configurator->tags['CUSTOM']->template);
	}

	public function test_configure_iframe_embeds_skips_non_iframe_templates()
	{
		$this->expect_media_enabled(true);

		$configurator = $this->create_configurator_with_tag(
			'CUSTOM_LINK',
			'<div class="custom-embed"><a href="https://video.example.com/watch/123">watch</a></div>'
		);
		$template = $configurator->tags['CUSTOM_LINK']->template;

		$this->manager->configure_iframe_embeds($configurator);

		self::assertSame($template, $configurator->tags['CUSTOM_LINK']->template);
	}

	public function test_configure_iframe_embeds_skips_templates_that_remain_unchanged()
	{
		$this->expect_media_enabled(true);

		$configurator = $this->create_configurator_with_tag(
			'CUSTOM_XSL_ONLY',
			'<xsl:element xmlns:xsl="http://www.w3.org/1999/XSL/Transform" name="iframe">'
				. '<xsl:attribute name="src">https://video.example.com/embed/123</xsl:attribute>'
				. '</xsl:element>'
		);
		$template = $configurator->tags['CUSTOM_XSL_ONLY']->template;

		$this->manager->configure_iframe_embeds($configurator);

		self::assertSame($template, $configurator->tags['CUSTOM_XSL_ONLY']->template);
	}

	public function test_configure_iframe_embeds_rewrites_xsl_generated_iframe_attributes()
	{
		$this->expect_media_enabled(true);

		$configurator = $this->create_configurator_with_tag(
			'CUSTOM_DYNAMIC',
			'<iframe src="https://video.example.com/embed/123">'
				. '<xsl:attribute xmlns:xsl="http://www.w3.org/1999/XSL/Transform" name="onload">boot()</xsl:attribute>'
				. '</iframe>'
		);

		$this->manager->configure_iframe_embeds($configurator);

		$template = $configurator->tags['CUSTOM_DYNAMIC']->template;

		self::assertStringContainsString('$S_CONSENTMANAGER_MEDIA_ALLOWED', $template);
		self::assertStringContainsString('data-consent-media-container="1"', $template);
		self::assertStringContainsString('data-consent-src="https://video.example.com/embed/123"', $template);
		self::assertStringContainsString('data-consent-onload="boot()"', $template);
		self::assertStringContainsString('data-consent-media-placeholder="1"', $template);
		self::assertStringContainsString('data-consent-media-frame="1"', $template);
		self::assertStringNotContainsString('$L_CONSENTMANAGER_MEDIA_PLACEHOLDER', $template);
		self::assertStringNotContainsString('data-consent-open-settings="1"', $template);
		self::assertStringContainsString('<iframe src="https://video.example.com/embed/123"', $template);
		self::assertStringContainsString('<iframe src="https://video.example.com/embed/123" onload="boot()"', $template);
	}

	public function test_configure_iframe_embeds_rewrites_mediaembed_iframes()
	{
		$this->expect_media_enabled(true);

		$configurator = new \s9e\TextFormatter\Configurator();
		$configurator->plugins->load('MediaEmbed');
		$configurator->MediaEmbed->add('youtube');

		$this->manager->configure_iframe_embeds($configurator);

		$template = $configurator->tags['YOUTUBE']->template;

		self::assertStringContainsString('$S_CONSENTMANAGER_MEDIA_ALLOWED', $template);
		self::assertStringContainsString('name="data-consent-media-container"', $template);
		self::assertStringContainsString('name="data-consent-src"', $template);
		self::assertStringContainsString('data-consent-media-placeholder="1"', $template);
		self::assertStringContainsString('data-consent-media-frame="1"', $template);
		self::assertStringNotContainsString('$L_CONSENTMANAGER_MEDIA_PLACEHOLDER', $template);
		self::assertStringNotContainsString('data-consent-open-settings="1"', $template);
		self::assertStringContainsString('name="src"', $template);
	}

	public function test_configure_iframe_embeds_rewrites_custom_s9e_iframes()
	{
		$this->expect_media_enabled(true);

		$configurator = $this->create_configurator_with_tag(
			'CUSTOM',
			'<div class="custom-embed"><iframe src="https://video.example.com/embed/123"></iframe></div>'
		);

		$this->manager->configure_iframe_embeds($configurator);

		$template = $configurator->tags['CUSTOM']->template;

		self::assertStringContainsString('$S_CONSENTMANAGER_MEDIA_ALLOWED', $template);
		self::assertStringContainsString('data-consent-media-container="1"', $template);
		self::assertStringContainsString('data-consent-src="https://video.example.com/embed/123"', $template);
		self::assertStringContainsString('data-consent-media-placeholder="1"', $template);
		self::assertStringContainsString('data-consent-media-frame="1"', $template);
		self::assertStringContainsString('class="custom-embed"', $template);
		self::assertStringContainsString('data-consent-media-content="1"', $template);
		self::assertStringNotContainsString('$L_CONSENTMANAGER_MEDIA_PLACEHOLDER', $template);
		self::assertStringNotContainsString('data-consent-open-settings="1"', $template);
		self::assertStringContainsString('<iframe src="https://video.example.com/embed/123"', $template);
	}

	public function test_configure_iframe_embeds_produces_consistent_results_for_identical_templates()
	{
		$this->expect_media_enabled(true);
		$configurator = new \s9e\TextFormatter\Configurator();
		$template = '<div class="custom-embed"><iframe src="https://video.example.com/embed/123"></iframe></div>';

		$configurator->tags->add('CUSTOM_ONE', new \s9e\TextFormatter\Configurator\Items\Tag([
			'template' => $template,
		]));
		$configurator->tags->add('CUSTOM_TWO', new \s9e\TextFormatter\Configurator\Items\Tag([
			'template' => $template,
		]));

		$this->manager->configure_iframe_embeds($configurator);

		self::assertEquals((string) $configurator->tags['CUSTOM_ONE']->template, (string) $configurator->tags['CUSTOM_TWO']->template);
	}

	public function test_configure_iframe_renderer_sets_media_allowed_parameter()
	{
		$parameter_args = ['S_CONSENTMANAGER_MEDIA_ALLOWED', '1'];
		$inner_renderer = $this->createMock('\s9e\TextFormatter\Renderer');
		$inner_renderer->expects(self::once())
			->method('setParameter')
			->with(...$parameter_args);

		$renderer = $this->getMockBuilder('\phpbb\textformatter\s9e\renderer')
			->disableOriginalConstructor()
			->setMethods(['get_renderer'])
			->getMock();
		$renderer->expects(self::once())
			->method('get_renderer')
			->willReturn($inner_renderer);

		$this->expect_media_consent(true);
		$this->manager->configure_iframe_renderer($renderer);
	}

	public function test_configure_iframe_renderer_clears_media_allowed_parameter_without_consent()
	{
		$parameter_args = ['S_CONSENTMANAGER_MEDIA_ALLOWED', ''];
		$inner_renderer = $this->createMock('\s9e\TextFormatter\Renderer');
		$inner_renderer->expects(self::once())
			->method('setParameter')
			->with(...$parameter_args);

		$renderer = $this->getMockBuilder('\phpbb\textformatter\s9e\renderer')
			->disableOriginalConstructor()
			->setMethods(['get_renderer'])
			->getMock();
		$renderer->expects(self::once())
			->method('get_renderer')
			->willReturn($inner_renderer);

		$this->expect_media_consent(false);
		$this->manager->configure_iframe_renderer($renderer);
	}

	/**
	 * @dataProvider build_iframe_placeholder_template_data
	 */
	public function test_build_iframe_placeholder_template_edge_cases($template, $repeat_call, $returns_original, $expected_contains)
	{
		$result = $this->invoke_method($this->manager, 'build_iframe_placeholder_template', [$template]);
		if ($repeat_call)
		{
			$second_result = $this->invoke_method($this->manager, 'build_iframe_placeholder_template', [$template]);
			self::assertSame($result, $second_result);
			$result = $second_result;
		}

		if ($returns_original)
		{
			self::assertSame($template, $result);
		}

		if ($expected_contains !== null)
		{
			self::assertStringContainsString($expected_contains, $result);
		}
	}

	public function build_iframe_placeholder_template_data()
	{
		return [
			'no runtime iframes' => [
				'<xsl:element xmlns:xsl="http://www.w3.org/1999/XSL/Transform" name="iframe">'
					. '<xsl:attribute name="src">https://video.example.com/embed/123</xsl:attribute>'
				. '</xsl:element>',
				false,
				true,
				null,
			],
			'cached result' => [
				'<div class="custom-embed"><iframe src="https://video.example.com/embed/123"></iframe></div>',
				true,
				false,
				'data-consent-media-container="1"',
			],
		];
	}

	public function test_strip_internal_s9e_attributes_removes_xsl_and_dom_attributes()
	{
		[$manager, $fixture] = $this->build_strip_internal_s9e_fixture();

		$this->invoke_method($manager, 'strip_internal_s9e_attributes', [$fixture]);

		$this->assert_strip_internal_s9e_fixture($fixture);
	}

	public function test_rewrite_iframe_node_rewrites_static_and_xsl_src_and_onload_attributes()
	{
		$manager = new \phpbb\consentmanager\service\media_manager(
			$this->createMock('\phpbb\consentmanager\service\consent_manager_interface')
		);
		$dom = \s9e\TextFormatter\Configurator\Helpers\TemplateLoader::load(
			'<iframe src="https://video.example.com/embed/123" onload="boot()">'
				. '<xsl:attribute xmlns:xsl="http://www.w3.org/1999/XSL/Transform" name="src">https://video.example.com/dynamic</xsl:attribute>'
				. '<xsl:attribute xmlns:xsl="http://www.w3.org/1999/XSL/Transform" name="onload">dynamicBoot()</xsl:attribute>'
				. '<span>fallback</span>'
			. '</iframe>'
		);
		$iframe = $dom->getElementsByTagName('iframe')->item(0);

		$this->invoke_method($manager, 'rewrite_iframe_node', [$iframe]);

		$template = \s9e\TextFormatter\Configurator\Helpers\TemplateLoader::save($dom);
		self::assertStringContainsString('data-consent-src="https://video.example.com/embed/123"', $template);
		self::assertStringContainsString('data-consent-onload="boot()"', $template);
		self::assertStringContainsString('name="data-consent-src"', $template);
		self::assertStringContainsString('name="data-consent-onload"', $template);
		self::assertStringContainsString('data-consent-media-frame="1"', $template);
		self::assertStringNotContainsString(' src="https://video.example.com/embed/123"', $template);
		self::assertStringNotContainsString(' onload="boot()"', $template);
	}

	protected function invoke_method($object, $method_name, array $arguments = [])
	{
		$method = new \ReflectionMethod($object, $method_name);
		$method->setAccessible(true);

		return $method->invokeArgs($object, $arguments);
	}

	protected function build_strip_internal_s9e_fixture()
	{
		$dom = \s9e\TextFormatter\Configurator\Helpers\TemplateLoader::load(
			'<div data-s9e-live="1">'
				. '<xsl:attribute xmlns:xsl="http://www.w3.org/1999/XSL/Transform" name="data-s9e-test">value</xsl:attribute>'
				. '<span>inner</span>'
			. '</div>'
		);

		return [$this->manager, $dom];
	}

	protected function assert_strip_internal_s9e_fixture(\DOMDocument $dom)
	{
		$template = \s9e\TextFormatter\Configurator\Helpers\TemplateLoader::save($dom);
		self::assertStringNotContainsString('data-s9e-live', $template);
		self::assertStringNotContainsString('data-s9e-test', $template);
	}

	protected function expect_media_enabled($enabled)
	{
		$category_args = ['media'];
		$this->consent_manager->expects(self::once())
			->method('is_category_enabled')
			->with(...$category_args)
			->willReturn($enabled);
	}

	protected function expect_media_consent($granted)
	{
		$category_args = ['media'];
		$this->consent_manager->expects(self::once())
			->method('has_server_consent')
			->with(...$category_args)
			->willReturn($granted);
	}

	protected function create_configurator_with_tag($tag_name, $template)
	{
		$configurator = new \s9e\TextFormatter\Configurator();
		$configurator->tags->add($tag_name, new \s9e\TextFormatter\Configurator\Items\Tag([
			'template' => $template,
		]));

		return $configurator;
	}
}
