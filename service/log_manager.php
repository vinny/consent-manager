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
use phpbb\user;

class log_manager
{
	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var user */
	protected $user;

	/** @var string */
	protected $consent_logs_table;

	/**
	 * Constructor.
	 *
	 * @param config           $config Config service
	 * @param driver_interface $db Database connection
	 * @param user             $user Current user
	 * @param string           $consent_logs_table Consent log table name
	 */
	public function __construct(config $config, driver_interface $db, user $user, $consent_logs_table)
	{
		$this->config = $config;
		$this->db = $db;
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
	}

	/**
	 * Build an anonymized identifier for the current user or session.
	 *
	 * @return string
	 */
	protected function get_anonymized_subject()
	{
		$subject = (int) $this->user->data['user_id'] !== ANONYMOUS ? 'u:' . (int) $this->user->data['user_id'] : 's:' . $this->user->session_id;

		return hash_hmac('sha256', $subject, $this->config['rand_seed']);
	}
}
