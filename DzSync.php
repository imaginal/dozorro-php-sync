<?php
/**
 *	DozorroSyncDaemon is a sample PHP synchronization 
 *	service for Dozorro API
 *
 *	Requires: DozorroClient, PHP-PDO, pecl libsodium
 *
 *	Example usage:
 * 	$daemon = new DozorroSyncDaemon($config);
 * 	$daemon->run();
 *
 *	@package  Dozorro
 *	@author   Volodymyr Flonts <flyonst@gmail.com>
 *	@version  0.1
 *  @license  https://www.apache.org/licenses/LICENSE-2.0
 *	@access   public
 *	@see      http://dozorro-api-sandbox.ed.org.ua/
**/

require 'DzClient.php';


class DozorroSyncDaemon {
	private $config = array(
		'pidfile'	=> 'dz.pid',
		'api_url'	=> '',
		'db_dsn'	=> '',
		'db_user'	=> '',
		'db_pass'	=> '',
		'db_table' 	=> 'data',
		'key_name'	=> 'none',
		'key_file'	=> ''
	);

	private $client	= null;

	private $dbh	= null;

	private $signing_keys	= array();

	private $throw_on_error	= false;

	private $json_options = JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;

	/*
	 *	Create DozorroSyncDaemon instance
	 *
	 *	@param array $argv
	 */
	function __construct($inifile) {
		$config = parse_ini_file($inifile);
		$this->config = array_replace($this->config, $config);
	}

	// function __destruct () {
	// }

	private function throw_error($msg) {
		if (is_array($msg))
			$msg = implode(' ', $msg);			
		if ($this->throw_on_error)
			throw new Exception($msg);
        trigger_error($msg, E_USER_ERROR);
	}

	private function log_message() {
		$args = func_get_args();
		if (count($args) > 1) {
			$fmt = array_shift($args);
			$msg = vsprintf($fmt, $args);
		} else
			$msg = $args[0];
		$ts = date("Y-m-d H:i:s");
		print("[$ts] $msg\n");
	}

	private function recursive_sort(&$obj) {
		if (is_object($obj))
			$obj = (array)$obj;
		foreach ($obj as &$val)
			if (is_array($val) || is_object($val))
				$this->recursive_sort($val);
		ksort($obj);
	}

	private function json_encode($obj) {
		$this->recursive_sort($obj);
		return json_encode($obj, $this->json_options);
	}

	private function load_signkey() {
		$key_name = trim($this->config['key_name']);
		$key_data = file_get_contents($this->config['key_file']);
		$this->signing_keys[$key_name] = $key_data;
	}

	private function reset_client() {
		$this->client = new DozorroClient(
			$this->config['api_url']);
	}

	private function connect_dbh() {
		$this->driver = explode(':', $this->config['db_dsn'])[0];
		$this->dbh = new PDO(
			$this->config['db_dsn'],
			$this->config['db_user'],
			$this->config['db_pass']);
	    $this->dbh->setAttribute(
	    	PDO::ATTR_ERRMODE,
	    	PDO::ERRMODE_EXCEPTION);
	    $default_settings = array(
	        'SET NAMES utf8',
	        'SET SESSION sql_warnings = 1',
	        'SET SESSION sql_mode = "ANSI,TRADITIONAL"'
       	);
       	foreach ($default_settings as $q)
       		$this->dbh->exec($q);
	}

	private function create_table() {
		if (empty($this->dbh))
			$this->connect_dbh();
		$create = array(
			'mysql' => DozorroSyncDaemon::MYSQL_CREATE_TABLE,
			'pgsql'	=> DozorroSyncDaemon::PGSQL_CREATE_TABLE,
			'sqlite'=> DozorroSyncDaemon::SQLITE_CREATE_TABLE
		);
		$query = strtr($create[$this->driver], array(
			'{table_name}'	=> $this->config['db_table']
		));
		$this->dbh->exec($query);
		if ((int)$this->dbh->errorCode())
			$this->throw_error($this->dbh->errorInfo());
	}

	private function test_exists($object_id) {
		$object_id = addslashes($object_id);
		$table = $this->config['db_table'];
		$query = "SELECT 1 FROM $table WHERE object_id = '$object_id'";
		$res = $this->dbh->query($query);
		if ($res) foreach ($res as $row)
			return true;
		return false;
	}

	private function save_multi($objects) {
		if (isset($objects->error))
			$this->throw_error("get_object ".$objects->error);
		$table = $this->config['db_table'];
		$query = 'INSERT INTO ' . $table .
			' ("object_id", "date", "owner", "model", "schema", "tender", "thread", "payload")'.
			' VALUES (:object_id, :date, :owner, :model, :schema, :tender, :thread, :payload)';
		$stmt = $this->dbh->prepare($query);
		if (isset($objects->id)) // signle object, not array
			$objects = (object)array('data' => array($objects));
		foreach ($objects->data as $obj) {
			$data = $obj->data;
			$payload = json_encode($data->payload, $this->json_options);
			if (empty($data->schema))
				$data->schema = NULL;
			$thread = NULL;
			if (isset($data->payload->thread))
				$thread = $data->payload->thread;
			$input_parameters = array(
				':object_id' => $obj->id,
				':date'		=> $data->date,
				':owner'	=> $data->owner,
				':model' 	=> $data->model,
				':schema'	=> $data->schema,
				':tender'	=> $data->payload->tender,
				':thread'	=> $thread,
				':payload'	=> $payload);
			$res = $stmt->execute($input_parameters);
			if ($res === false)
				$this->throw_error($stmt->errorInfo());
		}
	}

	private function update_object_id($id, $object_id) {
		$table = $this->config['db_table'];
		$query = "UPDATE $table SET object_id = ? WHERE id = ?";
		$stmt = $this->dbh->prepare($query);
		$res = $stmt->execute(array($object_id, $id));
		if ($res === 0)
			$this->throw_error("object_id not updated");
	}

	private function hash_id($data) {
		$h1 = hash('sha256', $data, true);
		$h2 = hash('sha256', $h1);
		return substr($h2, 0, 32);
	}

	private function sign_data($data, $owner) {
		if (empty($this->signing_keys[$owner]))
			$this->throw_error("bad owner ".$owner);
		$sign_key = $this->signing_keys[$owner];
		$signature = \Sodium\crypto_sign_detached(
		    $data, $sign_key);
		return rtrim(base64_encode($signature), "=");
	}

	private function put_single($id, $obj) {
		$obj->payload = json_decode($obj->payload);
		if ($obj->tender != $obj->payload->tender)
			$this->throw_error("tender mismatch");
		if ($obj->thread && $obj->thread != $obj->payload->thread)
			$this->throw_error("thread mismatch");
		$r = preg_match('/^\d\d\d\d-\d\d-\d\dT\d\d:\d\d:\d\d/', $obj->date);
		if ($r == 0) // == 0 or false
			$this->throw_error("bad date ".$obj->date);
		// remove unnecessary fields
		$tender = $obj->tender;
		$thread = $obj->thread;
		if (empty($obj->schema))
			unset($obj->schema);
		unset($obj->tender);
		unset($obj->thread);
		unset($obj->id);
		// create json and calc hash
		$data = $this->json_encode($obj);
		$hash = $this->hash_id($data);
		$sign = $this->sign_data($data, $obj->owner);
		$full = array('data' => $obj, 'id' => $hash, 'sign' => $sign);
		$json = $this->json_encode($full);
		$object_id = false;
		if ($obj->model == 'form')
			$object_id = $this->client->put_form($tender, $hash, $json);
		if ($obj->model == 'comment')
			$object_id = $this->client->put_comment($tender, $thread, $hash, $json);
		if (empty($object_id))
			$this->$throw_error("not saved");
		// finally update object_id
		$this->update_object_id($id, $object_id);
		$this->log_message("PUT %s id=%s hash=%s tender=%s", 
			$obj->model, $id, $object_id, $tender);
	}

	private function put_changes() {
		$table = $this->config['db_table'];
		$query = 'SELECT "id", "date", "owner", "model", "schema", "tender", "thread", "payload"'.
			" FROM $table WHERE object_id IS NULL ORDER BY date DESC";
		$res = $this->dbh->query($query, PDO::FETCH_INTO, new stdClass());
		if ($res === false)
			return;
		foreach ($res as $obj) {
			try {
				$id = $obj->id;
				$this->put_single($id, $obj);
			} catch (Exception $e) {
				$this->log_message("PUT id=%d %s", $id, $e);
			}
		}
	}

	private function signal_handler($signo) {
		$this->stop = true;
	}

	/*
	 *	Run daemon loop
	 */
	public function run() {
		file_put_contents($this->config['pidfile'], getmypid()."\n");
		pcntl_signal(SIGTERM, array(&$this, 'signal_handler'));
		pcntl_signal(SIGINT,  array(&$this, 'signal_handler'));

		$this->connect_dbh();
		$this->create_table();

		$this->reset_client();
		$this->load_signkey();

		for ($this->stop = false; !$this->stop; sleep(1)) {
			pcntl_signal_dispatch();
			// send
			$this->put_changes();
			// recv
			$items = $this->client->get_feed();
			if (empty($items)) {
				sleep(5);
				continue;
			}
			$toget = array();
			foreach ($items as $i)
				if ($this->test_exists($i->id) === false)
					$toget[] = $i->id;
			$this->log_message("Recv %d save %d",
				count($items), count($toget));
			if (empty($toget))
				continue;
			$toget = implode(',', $toget);
			$objects = $this->client->get_object($toget);
			$this->save_multi($objects);
		}

		if ($this->stop) {
			$this->log_message("Remove pidfile");
			unlink($this->config['pidfile']);
		}
	}

	/*
	 *	SQL constants
	 */
	const MYSQL_CREATE_TABLE =<<<EOS
		CREATE TABLE IF NOT EXISTS `{table_name}` (
		  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `object_id` char(32) DEFAULT NULL,
		  `date` char(32) NOT NULL,
		  `owner` varchar(40) NOT NULL,
		  `model` varchar(40) NOT NULL,
		  `schema` varchar(40) DEFAULT NULL,
		  `tender` char(32) NOT NULL,
		  `thread` char(32) DEFAULT NULL,
		  `payload` text NOT NULL,
		  PRIMARY KEY (`id`),
		  UNIQUE KEY `object_id` (`object_id`),
		  KEY `date` (`date`),
		  KEY `tender` (`tender`),
		  KEY `thread` (`thread`)
		) DEFAULT CHARSET=utf8;
EOS;
	const PGSQL_CREATE_TABLE =<<<EOS
EOS;
	const SQLITE_CREATE_TABLE =<<<EOS
EOS;
}
