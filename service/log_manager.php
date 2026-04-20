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
use phpbb\db\driver\driver_interface;
use phpbb\event\dispatcher_interface;
use phpbb\log\log as phpbb_log;
use phpbb\user;

class log_manager
{
	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var phpbb_log */
	protected $log;

	/** @var dispatcher_interface */
	protected $dispatcher;

	/** @var user */
	protected $user;

	/** @var string */
	protected $consent_logs_table;

	/**
	 * Constructor.
	 *
	 * @param config               $config Config service
	 * @param driver_interface     $db Database connection
	 * @param phpbb_log            $log phpBB log service
	 * @param dispatcher_interface $dispatcher Event dispatcher
	 * @param user                 $user Current user
	 * @param string               $consent_logs_table Consent log table name
	 */
	public function __construct(config $config, driver_interface $db, phpbb_log $log, dispatcher_interface $dispatcher, user $user, $consent_logs_table)
	{
		$this->config = $config;
		$this->db = $db;
		$this->log = $log;
		$this->dispatcher = $dispatcher;
		$this->user = $user;
		$this->consent_logs_table = $consent_logs_table;
	}

	/**
	 * Persist a consent decision for the current subject.
	 *
	 * @param array $categories Accepted category ids
	 * @param int   $version Consent version
	 *
	 * @return void
	 */
	public function log_consent(array $categories, $version)
	{
		$record = [
			'anonymized_id' => $this->get_anonymized_subject(),
			'consent_version' => (int) $version,
			'accepted_categories' => json_encode(array_values($categories)),
			'consent_time' => time(),
		];

		$sql = 'INSERT INTO ' . $this->consent_logs_table . ' ' . $this->db->sql_build_array('INSERT', $record);
		$this->db->sql_query($sql);

		$vars = ['record'];
		extract($this->dispatcher->trigger_event('phpbb.consentmanager.after_log', compact($vars)));
	}

	/**
	 * Add an admin log entry for consent settings changes.
	 *
	 * @return void
	 */
	public function log_admin_settings_updated()
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_UPDATED');
	}

	/**
	 * Add an admin log entry when users are re-prompted for consent.
	 *
	 * @return void
	 */
	public function log_admin_reprompt()
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_REPROMPT');
	}

	/**
	 * Build an anonymized identifier for the current user or session.
	 *
	 * @return string
	 */
	protected function get_anonymized_subject()
	{
		$subject = $this->user->data['user_id'] != ANONYMOUS ? 'u:' . $this->user->data['user_id'] : 's:' . $this->user->session_id;

		return hash_hmac('sha256', $subject, $this->config['rand_seed']);
	}
}
