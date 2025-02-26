<?php
/**
 * This is the SQLite database abstraction layer.
 * See maintenance/sqlite/README for development notes and other specific information
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Database
 */
namespace Wikimedia\Rdbms;

use PDO;
use PDOException;
use Exception;
use LockManager;
use FSLockManager;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * @ingroup Database
 */
class DatabaseSqlite extends Database {
	/** @var bool Whether full text is enabled */
	private static $fulltextEnabled = null;

	/** @var string Directory */
	protected $dbDir;
	/** @var string File name for SQLite database file */
	protected $dbPath;
	/** @var string Transaction mode */
	protected $trxMode;

	/** @var int The number of rows affected as an integer */
	protected $lastAffectedRowCount;
	/** @var resource */
	protected $lastResultHandle;

	/** @var PDO */
	protected $conn;

	/** @var FSLockManager (hopefully on the same server as the DB) */
	protected $lockMgr;

	/** @var array List of shared database already attached to this connection */
	private $alreadyAttached = [];

	/**
	 * Additional params include:
	 *   - dbDirectory : directory containing the DB and the lock file directory
	 *                   [defaults to $wgSQLiteDataDir]
	 *   - dbFilePath  : use this to force the path of the DB file
	 *   - trxMode     : one of (deferred, immediate, exclusive)
	 * @param array $p
	 */
	function __construct( array $p ) {
		if ( isset( $p['dbFilePath'] ) ) {
			$this->dbPath = $p['dbFilePath'];
			$lockDomain = md5( $this->dbPath );
			// Use "X" for things like X.sqlite and ":memory:" for RAM-only DBs
			if ( !isset( $p['dbname'] ) || !strlen( $p['dbname'] ) ) {
				$p['dbname'] = preg_replace( '/\.sqlite\d?$/', '', basename( $this->dbPath ) );
			}
		} elseif ( isset( $p['dbDirectory'] ) ) {
			$this->dbDir = $p['dbDirectory'];
			$lockDomain = $p['dbname'];
		} else {
			throw new InvalidArgumentException( "Need 'dbDirectory' or 'dbFilePath' parameter." );
		}

		$this->trxMode = isset( $p['trxMode'] ) ? strtoupper( $p['trxMode'] ) : null;
		if ( $this->trxMode &&
			!in_array( $this->trxMode, [ 'DEFERRED', 'IMMEDIATE', 'EXCLUSIVE' ] )
		) {
			$this->trxMode = null;
			$this->queryLogger->warning( "Invalid SQLite transaction mode provided." );
		}

		$this->lockMgr = new FSLockManager( [
			'domain' => $lockDomain,
			'lockDirectory' => "{$this->dbDir}/locks"
		] );

		parent::__construct( $p );
	}

	protected static function getAttributes() {
		return [ self::ATTR_DB_LEVEL_LOCKING => true ];
	}

	/**
	 * @param string $filename
	 * @param array $p Options map; supports:
	 *   - flags       : (same as __construct counterpart)
	 *   - trxMode     : (same as __construct counterpart)
	 *   - dbDirectory : (same as __construct counterpart)
	 * @return DatabaseSqlite
	 * @since 1.25
	 */
	public static function newStandaloneInstance( $filename, array $p = [] ) {
		$p['dbFilePath'] = $filename;
		$p['schema'] = null;
		$p['tablePrefix'] = '';
		/** @var DatabaseSqlite $db */
		$db = Database::factory( 'sqlite', $p );

		return $db;
	}

	protected function doInitConnection() {
		if ( $this->dbPath !== null ) {
			// Standalone .sqlite file mode.
			$this->openFile(
				$this->dbPath,
				$this->connectionParams['dbname'],
				$this->connectionParams['tablePrefix']
			);
		} elseif ( $this->dbDir !== null ) {
			// Stock wiki mode using standard file names per DB
			if ( strlen( $this->connectionParams['dbname'] ) ) {
				$this->open(
					$this->connectionParams['host'],
					$this->connectionParams['user'],
					$this->connectionParams['password'],
					$this->connectionParams['dbname'],
					$this->connectionParams['schema'],
					$this->connectionParams['tablePrefix']
				);
			} else {
				// Caller will manually call open() later?
				$this->connLogger->debug( __METHOD__ . ': no database opened.' );
			}
		} else {
			throw new InvalidArgumentException( "Need 'dbDirectory' or 'dbFilePath' parameter." );
		}
	}

	/**
	 * @return string
	 */
	function getType() {
		return 'sqlite';
	}

	/**
	 * @todo Check if it should be true like parent class
	 *
	 * @return bool
	 */
	function implicitGroupby() {
		return false;
	}

	protected function open( $server, $user, $pass, $dbName, $schema, $tablePrefix ) {
		$this->close();
		$fileName = self::generateFileName( $this->dbDir, $dbName );
		if ( !is_readable( $fileName ) ) {
			$this->conn = false;
			throw new DBConnectionError( $this, "SQLite database not accessible" );
		}
		// Only $dbName is used, the other parameters are irrelevant for SQLite databases
		$this->openFile( $fileName, $dbName, $tablePrefix );

		return (bool)$this->conn;
	}

	/**
	 * Opens a database file
	 *
	 * @param string $fileName
	 * @param string $dbName
	 * @param string $tablePrefix
	 * @throws DBConnectionError
	 * @return PDO|bool SQL connection or false if failed
	 */
	protected function openFile( $fileName, $dbName, $tablePrefix ) {
		$err = false;

		$this->dbPath = $fileName;
		try {
			if ( $this->flags & self::DBO_PERSISTENT ) {
				$this->conn = new PDO( "sqlite:$fileName", '', '',
					[ PDO::ATTR_PERSISTENT => true ] );
			} else {
				$this->conn = new PDO( "sqlite:$fileName", '', '' );
			}
		} catch ( PDOException $e ) {
			$err = $e->getMessage();
		}

		if ( !$this->conn ) {
			$this->queryLogger->debug( "DB connection error: $err\n" );
			throw new DBConnectionError( $this, $err );
		}

		$this->opened = is_object( $this->conn );
		if ( $this->opened ) {
			$this->currentDomain = new DatabaseDomain( $dbName, null, $tablePrefix );
			# Set error codes only, don't raise exceptions
			$this->conn->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
			# Enforce LIKE to be case sensitive, just like MySQL
			$this->query( 'PRAGMA case_sensitive_like = 1' );

			$sync = $this->connectionVariables['synchronous'] ?? null;
			if ( in_array( $sync, [ 'EXTRA', 'FULL', 'NORMAL', 'OFF' ], true ) ) {
				$this->query( "PRAGMA synchronous = $sync" );
			}

			return $this->conn;
		}

		return false;
	}

	/**
	 * @return string SQLite DB file path
	 * @since 1.25
	 */
	public function getDbFilePath() {
		return $this->dbPath;
	}

	/**
	 * Does not actually close the connection, just destroys the reference for GC to do its work
	 * @return bool
	 */
	protected function closeConnection() {
		$this->conn = null;

		return true;
	}

	/**
	 * Generates a database file name. Explicitly public for installer.
	 * @param string $dir Directory where database resides
	 * @param string $dbName Database name
	 * @return string
	 */
	public static function generateFileName( $dir, $dbName ) {
		return "$dir/$dbName.sqlite";
	}

	/**
	 * Check if the searchindext table is FTS enabled.
	 * @return bool False if not enabled.
	 */
	public function checkForEnabledSearch() {
		if ( self::$fulltextEnabled === null ) {
			self::$fulltextEnabled = false;
			$table = $this->tableName( 'searchindex' );
			$res = $this->query( "SELECT sql FROM sqlite_master WHERE tbl_name = '$table'", __METHOD__ );
			if ( $res ) {
				$row = $res->fetchRow();
				self::$fulltextEnabled = stristr( $row['sql'], 'fts' ) !== false;
			}
		}

		return self::$fulltextEnabled;
	}

	/**
	 * Returns version of currently supported SQLite fulltext search module or false if none present.
	 * @return string
	 */
	static function getFulltextSearchModule() {
		static $cachedResult = null;
		if ( $cachedResult !== null ) {
			return $cachedResult;
		}
		$cachedResult = false;
		$table = 'dummy_search_test';

		$db = self::newStandaloneInstance( ':memory:' );
		if ( $db->query( "CREATE VIRTUAL TABLE $table USING FTS3(dummy_field)", __METHOD__, true ) ) {
			$cachedResult = 'FTS3';
		}
		$db->close();

		return $cachedResult;
	}

	/**
	 * Attaches external database to our connection, see https://sqlite.org/lang_attach.html
	 * for details.
	 *
	 * @param string $name Database name to be used in queries like
	 *   SELECT foo FROM dbname.table
	 * @param bool|string $file Database file name. If omitted, will be generated
	 *   using $name and configured data directory
	 * @param string $fname Calling function name
	 * @return IResultWrapper
	 */
	function attachDatabase( $name, $file = false, $fname = __METHOD__ ) {
		if ( !$file ) {
			$file = self::generateFileName( $this->dbDir, $name );
		}
		$file = $this->addQuotes( $file );

		return $this->query( "ATTACH DATABASE $file AS $name", $fname );
	}

	protected function isWriteQuery( $sql ) {
		return parent::isWriteQuery( $sql ) && !preg_match( '/^(ATTACH|PRAGMA)\b/i', $sql );
	}

	protected function isTransactableQuery( $sql ) {
		return parent::isTransactableQuery( $sql ) && !in_array(
			$this->getQueryVerb( $sql ),
			[ 'ATTACH', 'PRAGMA' ],
			true
		);
	}

	/**
	 * SQLite doesn't allow buffered results or data seeking etc, so we'll use fetchAll as the result
	 *
	 * @param string $sql
	 * @return bool|IResultWrapper
	 */
	protected function doQuery( $sql ) {
		$res = $this->getBindingHandle()->query( $sql );
		if ( $res === false ) {
			return false;
		}

		$r = $res instanceof ResultWrapper ? $res->result : $res;
		$this->lastAffectedRowCount = $r->rowCount();
		$res = new ResultWrapper( $this, $r->fetchAll() );

		return $res;
	}

	/**
	 * @param IResultWrapper|mixed $res
	 */
	function freeResult( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$res->result = null;
		} else {
			$res = null;
		}
	}

	/**
	 * @param IResultWrapper|array $res
	 * @return stdClass|bool
	 */
	function fetchObject( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$r =& $res->result;
		} else {
			$r =& $res;
		}

		$cur = current( $r );
		if ( is_array( $cur ) ) {
			next( $r );
			$obj = new stdClass;
			foreach ( $cur as $k => $v ) {
				if ( !is_numeric( $k ) ) {
					$obj->$k = $v;
				}
			}

			return $obj;
		}

		return false;
	}

	/**
	 * @param IResultWrapper|mixed $res
	 * @return array|bool
	 */
	function fetchRow( $res ) {
		if ( $res instanceof ResultWrapper ) {
			$r =& $res->result;
		} else {
			$r =& $res;
		}
		$cur = current( $r );
		if ( is_array( $cur ) ) {
			next( $r );

			return $cur;
		}

		return false;
	}

	/**
	 * The PDO::Statement class implements the array interface so count() will work
	 *
	 * @param IResultWrapper|array|false $res
	 * @return int
	 */
	function numRows( $res ) {
		// false does not implement Countable
		$r = $res instanceof ResultWrapper ? $res->result : $res;

		return is_array( $r ) ? count( $r ) : 0;
	}

	/**
	 * @param IResultWrapper $res
	 * @return int
	 */
	function numFields( $res ) {
		$r = $res instanceof ResultWrapper ? $res->result : $res;
		if ( is_array( $r ) && count( $r ) > 0 ) {
			// The size of the result array is twice the number of fields. (T67578)
			return count( $r[0] ) / 2;
		} else {
			// If the result is empty return 0
			return 0;
		}
	}

	/**
	 * @param IResultWrapper $res
	 * @param int $n
	 * @return bool
	 */
	function fieldName( $res, $n ) {
		$r = $res instanceof ResultWrapper ? $res->result : $res;
		if ( is_array( $r ) ) {
			$keys = array_keys( $r[0] );

			return $keys[$n];
		}

		return false;
	}

	/**
	 * Use MySQL's naming (accounts for prefix etc) but remove surrounding backticks
	 *
	 * @param string $name
	 * @param string $format
	 * @return string
	 */
	function tableName( $name, $format = 'quoted' ) {
		// table names starting with sqlite_ are reserved
		if ( strpos( $name, 'sqlite_' ) === 0 ) {
			return $name;
		}

		return str_replace( '"', '', parent::tableName( $name, $format ) );
	}

	/**
	 * This must be called after nextSequenceVal
	 *
	 * @return int
	 */
	function insertId() {
		// PDO::lastInsertId yields a string :(
		return intval( $this->getBindingHandle()->lastInsertId() );
	}

	/**
	 * @param IResultWrapper|array $res
	 * @param int $row
	 */
	function dataSeek( $res, $row ) {
		if ( $res instanceof ResultWrapper ) {
			$r =& $res->result;
		} else {
			$r =& $res;
		}
		reset( $r );
		if ( $row > 0 ) {
			for ( $i = 0; $i < $row; $i++ ) {
				next( $r );
			}
		}
	}

	/**
	 * @return string
	 */
	function lastError() {
		if ( !is_object( $this->conn ) ) {
			return "Cannot return last error, no db connection";
		}
		$e = $this->conn->errorInfo();

		return $e[2] ?? '';
	}

	/**
	 * @return string
	 */
	function lastErrno() {
		if ( !is_object( $this->conn ) ) {
			return "Cannot return last error, no db connection";
		} else {
			$info = $this->conn->errorInfo();

			return $info[1];
		}
	}

	/**
	 * @return int
	 */
	protected function fetchAffectedRowCount() {
		return $this->lastAffectedRowCount;
	}

	function tableExists( $table, $fname = __METHOD__ ) {
		$tableRaw = $this->tableName( $table, 'raw' );
		if ( isset( $this->sessionTempTables[$tableRaw] ) ) {
			return true; // already known to exist
		}

		$encTable = $this->addQuotes( $tableRaw );
		$res = $this->query(
			"SELECT 1 FROM sqlite_master WHERE type='table' AND name=$encTable" );

		return $res->numRows() ? true : false;
	}

	/**
	 * Returns information about an index
	 * Returns false if the index does not exist
	 * - if errors are explicitly ignored, returns NULL on failure
	 *
	 * @param string $table
	 * @param string $index
	 * @param string $fname
	 * @return array|false
	 */
	function indexInfo( $table, $index, $fname = __METHOD__ ) {
		$sql = 'PRAGMA index_info(' . $this->addQuotes( $this->indexName( $index ) ) . ')';
		$res = $this->query( $sql, $fname );
		if ( !$res || $res->numRows() == 0 ) {
			return false;
		}
		$info = [];
		foreach ( $res as $row ) {
			$info[] = $row->name;
		}

		return $info;
	}

	/**
	 * @param string $table
	 * @param string $index
	 * @param string $fname
	 * @return bool|null
	 */
	function indexUnique( $table, $index, $fname = __METHOD__ ) {
		$row = $this->selectRow( 'sqlite_master', '*',
			[
				'type' => 'index',
				'name' => $this->indexName( $index ),
			], $fname );
		if ( !$row || !isset( $row->sql ) ) {
			return null;
		}

		// $row->sql will be of the form CREATE [UNIQUE] INDEX ...
		$indexPos = strpos( $row->sql, 'INDEX' );
		if ( $indexPos === false ) {
			return null;
		}
		$firstPart = substr( $row->sql, 0, $indexPos );
		$options = explode( ' ', $firstPart );

		return in_array( 'UNIQUE', $options );
	}

	/**
	 * Filter the options used in SELECT statements
	 *
	 * @param array $options
	 * @return array
	 */
	function makeSelectOptions( $options ) {
		foreach ( $options as $k => $v ) {
			if ( is_numeric( $k ) && ( $v == 'FOR UPDATE' || $v == 'LOCK IN SHARE MODE' ) ) {
				$options[$k] = '';
			}
		}

		return parent::makeSelectOptions( $options );
	}

	/**
	 * @param array $options
	 * @return array
	 */
	protected function makeUpdateOptionsArray( $options ) {
		$options = parent::makeUpdateOptionsArray( $options );
		$options = self::fixIgnore( $options );

		return $options;
	}

	/**
	 * @param array $options
	 * @return array
	 */
	static function fixIgnore( $options ) {
		# SQLite uses OR IGNORE not just IGNORE
		foreach ( $options as $k => $v ) {
			if ( $v == 'IGNORE' ) {
				$options[$k] = 'OR IGNORE';
			}
		}

		return $options;
	}

	/**
	 * @param array $options
	 * @return string
	 */
	function makeInsertOptions( $options ) {
		$options = self::fixIgnore( $options );

		return parent::makeInsertOptions( $options );
	}

	/**
	 * Based on generic method (parent) with some prior SQLite-sepcific adjustments
	 * @param string $table
	 * @param array $a
	 * @param string $fname
	 * @param array $options
	 * @return bool
	 */
	function insert( $table, $a, $fname = __METHOD__, $options = [] ) {
		if ( !count( $a ) ) {
			return true;
		}

		# SQLite can't handle multi-row inserts, so divide up into multiple single-row inserts
		if ( isset( $a[0] ) && is_array( $a[0] ) ) {
			$affectedRowCount = 0;
			try {
				$this->startAtomic( $fname, self::ATOMIC_CANCELABLE );
				foreach ( $a as $v ) {
					parent::insert( $table, $v, "$fname/multi-row", $options );
					$affectedRowCount += $this->affectedRows();
				}
				$this->endAtomic( $fname );
			} catch ( Exception $e ) {
				$this->cancelAtomic( $fname );
				throw $e;
			}
			$this->affectedRowCount = $affectedRowCount;
		} else {
			parent::insert( $table, $a, "$fname/single-row", $options );
		}

		return true;
	}

	/**
	 * @param string $table
	 * @param array $uniqueIndexes Unused
	 * @param string|array $rows
	 * @param string $fname
	 */
	function replace( $table, $uniqueIndexes, $rows, $fname = __METHOD__ ) {
		if ( !count( $rows ) ) {
			return;
		}

		# SQLite can't handle multi-row replaces, so divide up into multiple single-row queries
		if ( isset( $rows[0] ) && is_array( $rows[0] ) ) {
			$affectedRowCount = 0;
			try {
				$this->startAtomic( $fname, self::ATOMIC_CANCELABLE );
				foreach ( $rows as $v ) {
					$this->nativeReplace( $table, $v, "$fname/multi-row" );
					$affectedRowCount += $this->affectedRows();
				}
				$this->endAtomic( $fname );
			} catch ( Exception $e ) {
				$this->cancelAtomic( $fname );
				throw $e;
			}
			$this->affectedRowCount = $affectedRowCount;
		} else {
			$this->nativeReplace( $table, $rows, "$fname/single-row" );
		}
	}

	/**
	 * Returns the size of a text field, or -1 for "unlimited"
	 * In SQLite this is SQLITE_MAX_LENGTH, by default 1GB. No way to query it though.
	 *
	 * @param string $table
	 * @param string $field
	 * @return int
	 */
	function textFieldSize( $table, $field ) {
		return -1;
	}

	/**
	 * @return bool
	 */
	function unionSupportsOrderAndLimit() {
		return false;
	}

	/**
	 * @param string[] $sqls
	 * @param bool $all Whether to "UNION ALL" or not
	 * @return string
	 */
	function unionQueries( $sqls, $all ) {
		$glue = $all ? ' UNION ALL ' : ' UNION ';

		return implode( $glue, $sqls );
	}

	/**
	 * @return bool
	 */
	function wasDeadlock() {
		return $this->lastErrno() == 5; // SQLITE_BUSY
	}

	/**
	 * @return bool
	 */
	function wasReadOnlyError() {
		return $this->lastErrno() == 8; // SQLITE_READONLY;
	}

	public function wasConnectionError( $errno ) {
		return $errno == 17; // SQLITE_SCHEMA;
	}

	protected function wasKnownStatementRollbackError() {
		// ON CONFLICT ROLLBACK clauses make it so that SQLITE_CONSTRAINT error is
		// ambiguous with regard to whether it implies a ROLLBACK or an ABORT happened.
		// https://sqlite.org/lang_createtable.html#uniqueconst
		// https://sqlite.org/lang_conflict.html
		return false;
	}

	/**
	 * @return string Wikitext of a link to the server software's web site
	 */
	public function getSoftwareLink() {
		return "[{{int:version-db-sqlite-url}} SQLite]";
	}

	/**
	 * @return string Version information from the database
	 */
	function getServerVersion() {
		$ver = $this->getBindingHandle()->getAttribute( PDO::ATTR_SERVER_VERSION );

		return $ver;
	}

	/**
	 * Get information about a given field
	 * Returns false if the field does not exist.
	 *
	 * @param string $table
	 * @param string $field
	 * @return SQLiteField|bool False on failure
	 */
	function fieldInfo( $table, $field ) {
		$tableName = $this->tableName( $table );
		$sql = 'PRAGMA table_info(' . $this->addQuotes( $tableName ) . ')';
		$res = $this->query( $sql, __METHOD__ );
		foreach ( $res as $row ) {
			if ( $row->name == $field ) {
				return new SQLiteField( $row, $tableName );
			}
		}

		return false;
	}

	protected function doBegin( $fname = '' ) {
		if ( $this->trxMode ) {
			$this->query( "BEGIN {$this->trxMode}", $fname );
		} else {
			$this->query( 'BEGIN', $fname );
		}
		$this->trxLevel = 1;
	}

	/**
	 * @param string $s
	 * @return string
	 */
	function strencode( $s ) {
		return substr( $this->addQuotes( $s ), 1, -1 );
	}

	/**
	 * @param string $b
	 * @return Blob
	 */
	function encodeBlob( $b ) {
		return new Blob( $b );
	}

	/**
	 * @param Blob|string $b
	 * @return string
	 */
	function decodeBlob( $b ) {
		if ( $b instanceof Blob ) {
			$b = $b->fetch();
		}

		return $b;
	}

	/**
	 * @param string|int|null|bool|Blob $s
	 * @return string|int
	 */
	function addQuotes( $s ) {
		if ( $s instanceof Blob ) {
			return "x'" . bin2hex( $s->fetch() ) . "'";
		} elseif ( is_bool( $s ) ) {
			return (int)$s;
		} elseif ( strpos( (string)$s, "\0" ) !== false ) {
			// SQLite doesn't support \0 in strings, so use the hex representation as a workaround.
			// This is a known limitation of SQLite's mprintf function which PDO
			// should work around, but doesn't. I have reported this to php.net as bug #63419:
			// https://bugs.php.net/bug.php?id=63419
			// There was already a similar report for SQLite3::escapeString, bug #62361:
			// https://bugs.php.net/bug.php?id=62361
			// There is an additional bug regarding sorting this data after insert
			// on older versions of sqlite shipped with ubuntu 12.04
			// https://phabricator.wikimedia.org/T74367
			$this->queryLogger->debug(
				__FUNCTION__ .
				': Quoting value containing null byte. ' .
				'For consistency all binary data should have been ' .
				'first processed with self::encodeBlob()'
			);
			return "x'" . bin2hex( (string)$s ) . "'";
		} else {
			return $this->getBindingHandle()->quote( (string)$s );
		}
	}

	public function buildSubstring( $input, $startPosition, $length = null ) {
		$this->assertBuildSubstringParams( $startPosition, $length );
		$params = [ $input, $startPosition ];
		if ( $length !== null ) {
			$params[] = $length;
		}
		return 'SUBSTR(' . implode( ',', $params ) . ')';
	}

	/**
	 * @param string $field Field or column to cast
	 * @return string
	 * @since 1.28
	 */
	public function buildStringCast( $field ) {
		return 'CAST ( ' . $field . ' AS TEXT )';
	}

	/**
	 * No-op version of deadlockLoop
	 *
	 * @return mixed
	 */
	public function deadlockLoop( /*...*/ ) {
		$args = func_get_args();
		$function = array_shift( $args );

		return $function( ...$args );
	}

	/**
	 * @param string $s
	 * @return string
	 */
	protected function replaceVars( $s ) {
		$s = parent::replaceVars( $s );
		if ( preg_match( '/^\s*(CREATE|ALTER) TABLE/i', $s ) ) {
			// CREATE TABLE hacks to allow schema file sharing with MySQL

			// binary/varbinary column type -> blob
			$s = preg_replace( '/\b(var)?binary(\(\d+\))/i', 'BLOB', $s );
			// no such thing as unsigned
			$s = preg_replace( '/\b(un)?signed\b/i', '', $s );
			// INT -> INTEGER
			$s = preg_replace( '/\b(tiny|small|medium|big|)int(\s*\(\s*\d+\s*\)|\b)/i', 'INTEGER', $s );
			// floating point types -> REAL
			$s = preg_replace(
				'/\b(float|double(\s+precision)?)(\s*\(\s*\d+\s*(,\s*\d+\s*)?\)|\b)/i',
				'REAL',
				$s
			);
			// varchar -> TEXT
			$s = preg_replace( '/\b(var)?char\s*\(.*?\)/i', 'TEXT', $s );
			// TEXT normalization
			$s = preg_replace( '/\b(tiny|medium|long)text\b/i', 'TEXT', $s );
			// BLOB normalization
			$s = preg_replace( '/\b(tiny|small|medium|long|)blob\b/i', 'BLOB', $s );
			// BOOL -> INTEGER
			$s = preg_replace( '/\bbool(ean)?\b/i', 'INTEGER', $s );
			// DATETIME -> TEXT
			$s = preg_replace( '/\b(datetime|timestamp)\b/i', 'TEXT', $s );
			// No ENUM type
			$s = preg_replace( '/\benum\s*\([^)]*\)/i', 'TEXT', $s );
			// binary collation type -> nothing
			$s = preg_replace( '/\bbinary\b/i', '', $s );
			// auto_increment -> autoincrement
			$s = preg_replace( '/\bauto_increment\b/i', 'AUTOINCREMENT', $s );
			// No explicit options
			$s = preg_replace( '/\)[^);]*(;?)\s*$/', ')\1', $s );
			// AUTOINCREMENT should immedidately follow PRIMARY KEY
			$s = preg_replace( '/primary key (.*?) autoincrement/i', 'PRIMARY KEY AUTOINCREMENT $1', $s );
		} elseif ( preg_match( '/^\s*CREATE (\s*(?:UNIQUE|FULLTEXT)\s+)?INDEX/i', $s ) ) {
			// No truncated indexes
			$s = preg_replace( '/\(\d+\)/', '', $s );
			// No FULLTEXT
			$s = preg_replace( '/\bfulltext\b/i', '', $s );
		} elseif ( preg_match( '/^\s*DROP INDEX/i', $s ) ) {
			// DROP INDEX is database-wide, not table-specific, so no ON <table> clause.
			$s = preg_replace( '/\sON\s+[^\s]*/i', '', $s );
		} elseif ( preg_match( '/^\s*INSERT IGNORE\b/i', $s ) ) {
			// INSERT IGNORE --> INSERT OR IGNORE
			$s = preg_replace( '/^\s*INSERT IGNORE\b/i', 'INSERT OR IGNORE', $s );
		}

		return $s;
	}

	public function lock( $lockName, $method, $timeout = 5 ) {
		if ( !is_dir( "{$this->dbDir}/locks" ) ) { // create dir as needed
			if ( !is_writable( $this->dbDir ) || !mkdir( "{$this->dbDir}/locks" ) ) {
				throw new DBError( $this, "Cannot create directory \"{$this->dbDir}/locks\"." );
			}
		}

		return $this->lockMgr->lock( [ $lockName ], LockManager::LOCK_EX, $timeout )->isOK();
	}

	public function unlock( $lockName, $method ) {
		return $this->lockMgr->unlock( [ $lockName ], LockManager::LOCK_EX )->isOK();
	}

	/**
	 * Build a concatenation list to feed into a SQL query
	 *
	 * @param string[] $stringList
	 * @return string
	 */
	function buildConcat( $stringList ) {
		return '(' . implode( ') || (', $stringList ) . ')';
	}

	public function buildGroupConcatField(
		$delim, $table, $field, $conds = '', $join_conds = []
	) {
		$fld = "group_concat($field," . $this->addQuotes( $delim ) . ')';

		return '(' . $this->selectSQLText( $table, $fld, $conds, null, [], $join_conds ) . ')';
	}

	/**
	 * @param string $oldName
	 * @param string $newName
	 * @param bool $temporary
	 * @param string $fname
	 * @return bool|IResultWrapper
	 * @throws RuntimeException
	 */
	function duplicateTableStructure( $oldName, $newName, $temporary = false, $fname = __METHOD__ ) {
		$res = $this->query( "SELECT sql FROM sqlite_master WHERE tbl_name=" .
			$this->addQuotes( $oldName ) . " AND type='table'", $fname );
		$obj = $this->fetchObject( $res );
		if ( !$obj ) {
			throw new RuntimeException( "Couldn't retrieve structure for table $oldName" );
		}
		$sql = $obj->sql;
		$sql = preg_replace(
			'/(?<=\W)"?' .
				preg_quote( trim( $this->addIdentifierQuotes( $oldName ), '"' ), '/' ) .
				'"?(?=\W)/',
			$this->addIdentifierQuotes( $newName ),
			$sql,
			1
		);
		if ( $temporary ) {
			if ( preg_match( '/^\\s*CREATE\\s+VIRTUAL\\s+TABLE\b/i', $sql ) ) {
				$this->queryLogger->debug(
					"Table $oldName is virtual, can't create a temporary duplicate.\n" );
			} else {
				$sql = str_replace( 'CREATE TABLE', 'CREATE TEMPORARY TABLE', $sql );
			}
		}

		$res = $this->query( $sql, $fname, self::QUERY_PSEUDO_PERMANENT );

		// Take over indexes
		$indexList = $this->query( 'PRAGMA INDEX_LIST(' . $this->addQuotes( $oldName ) . ')' );
		foreach ( $indexList as $index ) {
			if ( strpos( $index->name, 'sqlite_autoindex' ) === 0 ) {
				continue;
			}

			if ( $index->unique ) {
				$sql = 'CREATE UNIQUE INDEX';
			} else {
				$sql = 'CREATE INDEX';
			}
			// Try to come up with a new index name, given indexes have database scope in SQLite
			$indexName = $newName . '_' . $index->name;
			$sql .= ' ' . $indexName . ' ON ' . $newName;

			$indexInfo = $this->query( 'PRAGMA INDEX_INFO(' . $this->addQuotes( $index->name ) . ')' );
			$fields = [];
			foreach ( $indexInfo as $indexInfoRow ) {
				$fields[$indexInfoRow->seqno] = $indexInfoRow->name;
			}

			$sql .= '(' . implode( ',', $fields ) . ')';

			$this->query( $sql );
		}

		return $res;
	}

	/**
	 * List all tables on the database
	 *
	 * @param string|null $prefix Only show tables with this prefix, e.g. mw_
	 * @param string $fname Calling function name
	 *
	 * @return array
	 */
	function listTables( $prefix = null, $fname = __METHOD__ ) {
		$result = $this->select(
			'sqlite_master',
			'name',
			"type='table'"
		);

		$endArray = [];

		foreach ( $result as $table ) {
			$vars = get_object_vars( $table );
			$table = array_pop( $vars );

			if ( !$prefix || strpos( $table, $prefix ) === 0 ) {
				if ( strpos( $table, 'sqlite_' ) !== 0 ) {
					$endArray[] = $table;
				}
			}
		}

		return $endArray;
	}

	/**
	 * Override due to no CASCADE support
	 *
	 * @param string $tableName
	 * @param string $fName
	 * @return bool|IResultWrapper
	 * @throws DBReadOnlyError
	 */
	public function dropTable( $tableName, $fName = __METHOD__ ) {
		if ( !$this->tableExists( $tableName, $fName ) ) {
			return false;
		}
		$sql = "DROP TABLE " . $this->tableName( $tableName );

		return $this->query( $sql, $fName );
	}

	public function setTableAliases( array $aliases ) {
		parent::setTableAliases( $aliases );
		foreach ( $this->tableAliases as $params ) {
			if ( isset( $this->alreadyAttached[$params['dbname']] ) ) {
				continue;
			}
			$this->attachDatabase( $params['dbname'] );
			$this->alreadyAttached[$params['dbname']] = true;
		}
	}

	public function resetSequenceForTable( $table, $fname = __METHOD__ ) {
		$encTable = $this->addIdentifierQuotes( 'sqlite_sequence' );
		$encName = $this->addQuotes( $this->tableName( $table, 'raw' ) );
		$this->query( "DELETE FROM $encTable WHERE name = $encName", $fname );
	}

	public function databasesAreIndependent() {
		return true;
	}

	/**
	 * @return PDO
	 */
	protected function getBindingHandle() {
		return parent::getBindingHandle();
	}
}

/**
 * @deprecated since 1.29
 */
class_alias( DatabaseSqlite::class, 'DatabaseSqlite' );
