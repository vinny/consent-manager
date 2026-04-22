<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\controller;

class log_controller_test extends \phpbb_test_case
{
	public function test_log_rejects_invalid_json_payload()
	{
		$controller = new \phpbb\consentmanager\controller\log_controller(
			$this->createMock('\phpbb\consentmanager\service\log_manager'),
			$this->createMock('\phpbb\consentmanager\service\consent_manager_interface')
		);

		$response = $controller->log(\Symfony\Component\HttpFoundation\Request::create(
			'/consent/log',
			'POST',
			array(),
			array(),
			array(),
			array(),
			'{invalid'
		));

		self::assertSame(400, $response->getStatusCode());
		self::assertSame(array(
			'success' => false,
			'error' => 'invalid_payload',
		), json_decode($response->getContent(), true));
	}

	/**
	 * @dataProvider invalid_submission_data
	 */
	public function test_log_returns_service_validation_failure($submission_error, $expected_status)
	{
		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');
		$log_manager->expects(self::never())
			->method('log_consent');

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('validate_log_payload')
			->with(array('hash' => 'bad'))
			->willReturn(array(
				'success' => false,
				'error' => $submission_error,
			));

		$controller = new \phpbb\consentmanager\controller\log_controller($log_manager, $consent_manager);
		$response = $controller->log(new \Symfony\Component\HttpFoundation\Request(array(), array(), array(), array(), array(), array(), json_encode(array(
			'hash' => 'bad',
		))));

		self::assertSame($expected_status, $response->getStatusCode());
		self::assertSame($submission_error, json_decode($response->getContent(), true)['error']);
	}

	public function test_log_persists_valid_submission()
	{
		$log_manager = $this->createMock('\phpbb\consentmanager\service\log_manager');
		$log_manager->expects(self::once())
			->method('log_consent')
			->with(array('necessary', 'analytics'), 5);

		$consent_manager = $this->createMock('\phpbb\consentmanager\service\consent_manager_interface');
		$consent_manager->expects(self::once())
			->method('validate_log_payload')
			->willReturn(array(
				'success' => true,
				'categories' => array('necessary', 'analytics'),
				'version' => 5,
			));

		$controller = new \phpbb\consentmanager\controller\log_controller($log_manager, $consent_manager);
		$response = $controller->log(new \Symfony\Component\HttpFoundation\Request(array(), array(), array(), array(), array(), array(), json_encode(array(
			'hash' => 'good',
			'version' => 5,
			'categories' => array('analytics'),
		))));

		self::assertSame(200, $response->getStatusCode());
		self::assertSame(array(
			'success' => true,
			'categories' => array('necessary', 'analytics'),
			'version' => 5,
		), json_decode($response->getContent(), true));
	}

	public function invalid_submission_data()
	{
		return array(
			'invalid hash' => array('invalid_hash', 403),
			'version mismatch' => array('version_mismatch', 409),
			'generic invalid payload' => array('invalid_payload', 400),
		);
	}
}
