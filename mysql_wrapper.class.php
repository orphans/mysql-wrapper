<?php

/*
 * Singleton class - rather than globalising the object instance you should call
 * $var = MYSQL_WRAPPER::get_singleton() when it's needed but not accessible.
 *
 * Strings 'NOW()' and 'NULL' will be converted to MySQL keywords when found in INSERT and UPDATE queries
 */

class MYSQL_WRAPPER {

	private $dsn = NULL;
	private $dbh = NULL;
	private $log = array();
	private $log_errors = FALSE;
	private $log_queries = FALSE;
	private $num_queries = 0;
	private static $instance = NULL;

	/*
	 * !CLASS FUNCTIONALITY
	 */

	public function __construct() {
	}

	private function __clone() {
	}

	public static function get_singleton() {
		if (self::$instance == NULL) {
			self::$instance = new MYSQL_WRAPPER();
		}
		return self::$instance;
	}


	/*
	 * !CONNECTION
	 *
	 * $settings is an array containing the following key/value pairs:
	 *
	 *  [host]     => hostname or ip address
     *  [port]     => port number (defaults to 3306)
     *    or
     *  [socket]   => path to mysql socket
     *
     *  [username] => mysql username (required)
     *  [password] => mysql password (required)
     *  [database] => mysql database (required)
     *
     *  [charset]  => chartset (defaults to utf8)
	 */

	function connect($settings = FALSE) {
		try {

			// build dsn
			$this->dsn  = 'mysql:';
			$options = array();

			// host address (address or socket)
			if (isset($settings['socket']) && !empty($settings['socket'])) {
				$this->dsn .= 'unix_socket=' . $settings['socket'];
			} else {
				$this->dsn .= 'host=' . $settings['host'];
				if (isset($settings['port']) && !empty($settings['port'])) {
					$this->dsn .= ';port=' . $settings['port'];
				}
			}

			// database name
			$this->dsn .= ';dbname=' . $settings['database'];

			// charset (default to utf8)
			if (isset($settings['charset']) && !empty($settings['charset'])) {
				$this->dsn .= ';charset=' . $settings['charset'];
				$options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $settings['charset'];
			} else {
				$this->dsn .= ';charset=utf8';
				$options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8';
			}

			$this->dbh = new PDO($this->dsn, $settings['username'], $settings['password'], $options);
			$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		} catch(PDOException $e) {
			echo "Cannot connect to " . $this->dsn . "\n";
			echo $e->getMessage() . "\n";
			exit();
		}
	}


	/*
	 *  !CORE QUERIES
	 *
	 *  TO DO: Restrict runctions to expected queries
	 *         INSERT ... ON DUPLICATE UPDATE
	 *         DELETE
	 */

	/*
	 *  Used by all querying functions to prepare and execute the statement, though it can be
	 *  called directly.
	 */
	function query($sql, $vars) {

		$sth = $this->dbh->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
		if (is_array($vars) && sizeof($vars)) {
			$result = $sth->execute($vars);
		} else {
			$result = $sth->execute();
		}
		$this->num_queries++;
		if ($this->log_queries) {
			$this->log_append($sth->queryString);
		}

		// must have errored (note that most errors will be caught in dbh->prepare and will cause an exception so this is only for edge cases)
		if (!$result) {
			$error = $sth->errorInfo();
			$this->record_error($sth->queryString, $vars, $error[2]);
		}

		return $sth;
	}


	/*
	 *  Runs standard SELECT queries and returns an associative array containing all results.
	 */
	function select($sql, $vars = array()) {
		$sth = $this->query($sql, $vars);
		return $sth->fetchAll(PDO::FETCH_ASSOC);
	}

	/*
	 *  Returns a single result as an associate array. Users are expected to write 'LIMIT 1' in there
	 *  own queries as best practice but if not this function will add it to save DB load.
	 */
	function select_single($sql, $vars = array()) {
		if (!preg_match('/limit\s1/i', substr(trim($sql), -7))) {
			$sql .= ' LIMIT 1';
		}
		$sth = $this->query($sql, $vars);
		return $sth->fetch(PDO::FETCH_ASSOC);
	}

	/*
	 *  Provide table name and associate array of field/value pairs
	 *  Returns inserted ID or FALSE on failure
	 */
	function insert($table, $insert_fields, $ignore = FALSE) {
		if (sizeof($insert_fields)) {
			$vars = array();
			$sql = "INSERT ";
			if ($ignore === TRUE) {
				$sql .= "IGNORE ";
			}
			$sql .= "INTO `$table` SET ";
			foreach ($insert_fields as $k=>$v) {
				// treat certain MySQL functions as safe
				if ($v === 'NOW()') {
					$sql .= "`$k` = NOW(), ";
				} else if ($v === 'NULL') {
					$sql .= "`$k` = NULL, ";
				} else {
					$sql .= "`$k` = :$k, ";
					$vars[":$k"] = $v;
				}
			}
			$sql = substr($sql, 0, -2);
			if ($sth = $this->query($sql, $vars)) {
				return $this->dbh->lastInsertId();
			}

		}
		return FALSE;
	}

	/*
	 *  Provide table name and associate array of field/value pairs
	 *  Enforces a where clause for protection (practically, it's always wanted anyway)
	 *  Where clause fields are auto-prepended with __where__ to prevent conflicts with the update fields
	 *  Returns FALSE on failure
	 */
	function update($table, $update_fields, $where_clause = false, $where_fields = false) {
		if ($where_clause && sizeof($update_fields)) {
			$vars = array();
			$sql = "UPDATE `$table` SET ";
			foreach ($update_fields as $k=>$v) {
				// treat certain MySQL functions as safe
				if ($v === 'NOW()') {
					$sql .= "`$k` = NOW(), ";
				} else if ($v === 'NULL') {
					$sql .= "`$k` = NULL, ";
				} else {
					$sql .= "`$k` = :$k, ";
					$vars[":$k"] = $v;
				}
			}
			$sql = substr($sql, 0, -2);
			$sql .= " WHERE " . str_replace(':', ':__where__', $where_clause);
			foreach ($where_fields as $k=>$v) {
				$vars[":__where__$k"] = $v;
			}
			if ($sth = $this->query($sql, $vars)) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/*
	 *  Provide table name and associate array of field/value pairs
	 *  Returns FALSE on failure
	 */
	function insert_or_update($table, $insert_fields, $update_fields) {
		if (sizeof($insert_fields) && sizeof($update_fields)) {
			$vars = array();
			$sql = "INSERT INTO `$table` SET ";
			foreach ($insert_fields as $k=>$v) {
				// treat certain MySQL functions as safe
				if ($v === 'NOW()') {
					$sql .= "`$k` = NOW(), ";
				} else if ($v === 'NULL') {
					$sql .= "`$k` = NULL, ";
				} else {
					$sql .= "`$k` = :$k, ";
					$vars[":$k"] = $v;
				}
			}
			$sql = substr($sql, 0, -2);
			$sql .= " ON DUPLICATE KEY UPDATE ";
			foreach ($update_fields as $k=>$v) {
				// treat certain MySQL functions as safe
				if ($v === 'NOW()') {
					$sql .= "`$k` = NOW(), ";
				} else if ($v === 'NULL') {
					$sql .= "`$k` = NULL, ";
				} else {
					$sql .= "`$k` = :$k, ";
					$vars[":$k"] = $v;
				}
			}
			$sql = substr($sql, 0, -2);
			if ($sth = $this->query($sql, $vars)) {
				return TRUE;
			}
		}
		return FALSE;
	}

	/*
	 *  Provide table name
	 *  Enforces a where clause for protection (practically, it's always wanted anyway)
	 *  Returns FALSE on failure
	 */
	function delete($table, $where_clause = false, $where_fields = array()) {
		$vars = array();
		$sql = "DELETE FROM `$table`";
		if ($where_clause !== FALSE) {
			$sql .= " WHERE " . $where_clause;
		}
		foreach ($where_fields as $k=>$v) {
			$vars[":$k"] = $v;
		}
		if ($sth = $this->query($sql, $vars)) {
			return TRUE;
		}
		return FALSE;
	}


	/*
	 *  !UTILITIES
	 *
	 *  Useful DB-related tools
	 */

	// ONLY to my used when a value can't supported above (E.g. WHERE field LIKE '%str%')
	public function quote($str) {
		return substr($this->dbh->quote($str), 1, -1);
	}

	// Convert a date from an dd/mm/yyyy input to yyyy-mm-dd
	public function input_date_to_system_date($in) {
		if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $in)) {
			$parts = explode('/', $in);
			return str_pad($parts[2], 4, "20", STR_PAD_LEFT) . '-' . str_pad($parts[1], 2, "0", STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, "0", STR_PAD_LEFT);
		}
		return FALSE;
	}

	// Convert a date from yyyy-mm-dd for dd/mm/yyyy input fields
	public function system_date_to_input_date($in, $default_today = FALSE) {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $in)) {
			$parts = explode('-', $in);
			return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
		} else if ($default_today === TRUE && empty($in)) {
			return date("d/m/Y");
		}
		return '';
	}


	/*
	 * !LOGGING
	 */

	function log_append($line) {
		$this->log[] = $line;
	}

	function log_fetch() {
		return $this->log;
	}

	function log_reset() {
		$this->log=array();
	}

	function num_queries() {
		return $this->num_queries;
	}

	function record_error($sql, $vars, $msg) {
		if ($this->log_errors === TRUE) {
			if (is_array($vars)) {
				$vars = serialize($vars);
			}
			$this->insert('error_log', array(
				'thedate' => 'NOW()',
				'query' => $sql,
				'vars' => $vars,
				'message' => $msg,
			));
		}
	}


	// When the singleton object is serialized/unserialized
	// the value of static variable will be lost
	// so you need to re-assign to the static $instance
	// the object by using restore_instance member (setter function)

	public function restore_instance($session_instance) {
		self::$instance = $session_instance;
	}

}
