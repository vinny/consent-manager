<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\functional;

/**
 * @group functional
 */
class iframe_embed_test extends \phpbb_functional_test_case
{
	protected static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	public function test_custom_iframe_bbcodes_are_deferred_until_media_consent_is_granted()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', 'adm/index.php?i=acp_bbcodes&sid=' . $this->sid . '&mode=bbcodes&action=add');
		$form = $crawler->selectButton('Submit')->form(array(
			'bbcode_match' => '[iframe]{URL}[/iframe]',
			'bbcode_tpl'   => '<iframe src="{URL}" width="560" height="315"></iframe>',
		));
		self::submit($form);

		$post = $this->create_topic(2, 'Consent-managed iframe BBCode', '[iframe]https://video.example.com/embed/123[/iframe]');
		$crawler = self::request('GET', 'viewtopic.php?t=' . $post['topic_id'] . '&sid=' . $this->sid);
		$post_selector = '#post_content' . $post['topic_id'];

		self::assertCount(1, $crawler->filter($post_selector . ' [data-consent-media-container="1"]'));
		self::assertCount(1, $crawler->filter($post_selector . ' [data-consent-media-placeholder="1"]'));
		self::assertCount(1, $crawler->filter($post_selector . ' [data-consent-media-content="1"][hidden="hidden"]'));

		$iframe = $crawler->filter($post_selector . ' iframe[data-consent-media-frame="1"]');
		self::assertCount(1, $iframe);
		self::assertSame('', (string) $iframe->attr('src'));
		self::assertSame('https://video.example.com/embed/123', $iframe->attr('data-consent-src'));
	}
}
