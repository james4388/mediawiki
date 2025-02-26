<?php

/**
 * Holds tests for LoadBalancer MediaWiki class.
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
 */

use Wikimedia\Rdbms\DBError;
use Wikimedia\Rdbms\DatabaseDomain;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\LoadBalancer;
use Wikimedia\Rdbms\LoadMonitorNull;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @covers \Wikimedia\Rdbms\LoadBalancer
 */
class LoadBalancerTest extends MediaWikiTestCase {
	private function makeServerConfig( $flags = DBO_DEFAULT ) {
		global $wgDBserver, $wgDBname, $wgDBuser, $wgDBpassword, $wgDBtype, $wgSQLiteDataDir;

		return [
			'host' => $wgDBserver,
			'dbname' => $wgDBname,
			'tablePrefix' => $this->dbPrefix(),
			'user' => $wgDBuser,
			'password' => $wgDBpassword,
			'type' => $wgDBtype,
			'dbDirectory' => $wgSQLiteDataDir,
			'load' => 0,
			'flags' => $flags
		];
	}

	/**
	 * @covers LoadBalancer::getLocalDomainID()
	 * @covers LoadBalancer::resolveDomainID()
	 */
	public function testWithoutReplica() {
		global $wgDBname;

		$called = false;
		$lb = new LoadBalancer( [
			// Simulate web request with DBO_TRX
			'servers' => [ $this->makeServerConfig( DBO_TRX ) ],
			'queryLogger' => MediaWiki\Logger\LoggerFactory::getInstance( 'DBQuery' ),
			'localDomain' => new DatabaseDomain( $wgDBname, null, $this->dbPrefix() ),
			'chronologyCallback' => function () use ( &$called ) {
				$called = true;
			}
		] );

		$ld = DatabaseDomain::newFromId( $lb->getLocalDomainID() );
		$this->assertEquals( $wgDBname, $ld->getDatabase(), 'local domain DB set' );
		$this->assertEquals( $this->dbPrefix(), $ld->getTablePrefix(), 'local domain prefix set' );
		$this->assertSame( 'my_test_wiki', $lb->resolveDomainID( 'my_test_wiki' ) );
		$this->assertSame( $ld->getId(), $lb->resolveDomainID( false ) );
		$this->assertSame( $ld->getId(), $lb->resolveDomainID( $ld ) );
		$this->assertFalse( $called );

		$dbw = $lb->getConnection( DB_MASTER );
		$this->assertTrue( $called );
		$this->assertTrue( $dbw->getLBInfo( 'master' ), 'master shows as master' );
		$this->assertTrue( $dbw->getFlag( $dbw::DBO_TRX ), "DBO_TRX set on master" );
		$this->assertWriteAllowed( $dbw );

		$dbr = $lb->getConnection( DB_REPLICA );
		$this->assertTrue( $dbr->getLBInfo( 'master' ), 'DB_REPLICA also gets the master' );
		$this->assertTrue( $dbr->getFlag( $dbw::DBO_TRX ), "DBO_TRX set on replica" );

		if ( !$lb->getServerAttributes( $lb->getWriterIndex() )[$dbw::ATTR_DB_LEVEL_LOCKING] ) {
			$dbwAuto = $lb->getConnection( DB_MASTER, [], false, $lb::CONN_TRX_AUTOCOMMIT );
			$this->assertFalse(
				$dbwAuto->getFlag( $dbw::DBO_TRX ), "No DBO_TRX with CONN_TRX_AUTOCOMMIT" );
			$this->assertTrue( $dbw->getFlag( $dbw::DBO_TRX ), "DBO_TRX still set on master" );
			$this->assertNotEquals(
				$dbw, $dbwAuto, "CONN_TRX_AUTOCOMMIT uses separate connection" );

			$dbrAuto = $lb->getConnection( DB_REPLICA, [], false, $lb::CONN_TRX_AUTOCOMMIT );
			$this->assertFalse(
				$dbrAuto->getFlag( $dbw::DBO_TRX ), "No DBO_TRX with CONN_TRX_AUTOCOMMIT" );
			$this->assertTrue( $dbr->getFlag( $dbw::DBO_TRX ), "DBO_TRX still set on replica" );
			$this->assertNotEquals(
				$dbr, $dbrAuto, "CONN_TRX_AUTOCOMMIT uses separate connection" );

			$dbwAuto2 = $lb->getConnection( DB_MASTER, [], false, $lb::CONN_TRX_AUTOCOMMIT );
			$this->assertEquals( $dbwAuto2, $dbwAuto, "CONN_TRX_AUTOCOMMIT reuses connections" );
		}

		$lb->closeAll();
	}

	public function testWithReplica() {
		global $wgDBserver;

		// Simulate web request with DBO_TRX
		$lb = $this->newMultiServerLocalLoadBalancer( DBO_TRX );

		$dbw = $lb->getConnection( DB_MASTER );
		$this->assertTrue( $dbw->getLBInfo( 'master' ), 'master shows as master' );
		$this->assertEquals(
			( $wgDBserver != '' ) ? $wgDBserver : 'localhost',
			$dbw->getLBInfo( 'clusterMasterHost' ),
			'cluster master set' );
		$this->assertTrue( $dbw->getFlag( $dbw::DBO_TRX ), "DBO_TRX set on master" );
		$this->assertWriteAllowed( $dbw );

		$dbr = $lb->getConnection( DB_REPLICA );
		$this->assertTrue( $dbr->getLBInfo( 'replica' ), 'replica shows as replica' );
		$this->assertEquals(
			( $wgDBserver != '' ) ? $wgDBserver : 'localhost',
			$dbr->getLBInfo( 'clusterMasterHost' ),
			'cluster master set' );
		$this->assertTrue( $dbr->getFlag( $dbw::DBO_TRX ), "DBO_TRX set on replica" );
		$this->assertWriteForbidden( $dbr );

		if ( !$lb->getServerAttributes( $lb->getWriterIndex() )[$dbw::ATTR_DB_LEVEL_LOCKING] ) {
			$dbwAuto = $lb->getConnection( DB_MASTER, [], false, $lb::CONN_TRX_AUTOCOMMIT );
			$this->assertFalse(
				$dbwAuto->getFlag( $dbw::DBO_TRX ), "No DBO_TRX with CONN_TRX_AUTOCOMMIT" );
			$this->assertTrue( $dbw->getFlag( $dbw::DBO_TRX ), "DBO_TRX still set on master" );
			$this->assertNotEquals(
				$dbw, $dbwAuto, "CONN_TRX_AUTOCOMMIT uses separate connection" );

			$dbrAuto = $lb->getConnection( DB_REPLICA, [], false, $lb::CONN_TRX_AUTOCOMMIT );
			$this->assertFalse(
				$dbrAuto->getFlag( $dbw::DBO_TRX ), "No DBO_TRX with CONN_TRX_AUTOCOMMIT" );
			$this->assertTrue( $dbr->getFlag( $dbw::DBO_TRX ), "DBO_TRX still set on replica" );
			$this->assertNotEquals(
				$dbr, $dbrAuto, "CONN_TRX_AUTOCOMMIT uses separate connection" );

			$dbwAuto2 = $lb->getConnection( DB_MASTER, [], false, $lb::CONN_TRX_AUTOCOMMIT );
			$this->assertEquals( $dbwAuto2, $dbwAuto, "CONN_TRX_AUTOCOMMIT reuses connections" );
		}

		$lb->closeAll();
	}

	private function newSingleServerLocalLoadBalancer() {
		global $wgDBname;

		return new LoadBalancer( [
			'servers' => [ $this->makeServerConfig() ],
			'localDomain' => new DatabaseDomain( $wgDBname, null, $this->dbPrefix() )
		] );
	}

	private function newMultiServerLocalLoadBalancer( $flags = DBO_DEFAULT ) {
		global $wgDBserver, $wgDBname, $wgDBuser, $wgDBpassword, $wgDBtype, $wgSQLiteDataDir;

		$servers = [
			// Master DB
			0 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 0,
				'flags' => $flags
			],
			// Main replica DBs
			1 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 100,
				'flags' => $flags
			],
			2 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 100,
				'flags' => $flags
			],
			// RC replica DBs
			3 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 0,
				'groupLoads' => [
					'recentchanges' => 100,
					'watchlist' => 100
				],
				'flags' => $flags
			],
			// Logging replica DBs
			4 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 0,
				'groupLoads' => [
					'logging' => 100
				],
				'flags' => $flags
			],
			5 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 0,
				'groupLoads' => [
					'logging' => 100
				],
				'flags' => $flags
			],
			// Maintenance query replica DBs
			6 => [
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 0,
				'groupLoads' => [
					'vslow' => 100
				],
				'flags' => $flags
			]
		];

		return new LoadBalancer( [
			'servers' => $servers,
			'localDomain' => new DatabaseDomain( $wgDBname, null, $this->dbPrefix() ),
			'queryLogger' => MediaWiki\Logger\LoggerFactory::getInstance( 'DBQuery' ),
			'loadMonitorClass' => LoadMonitorNull::class
		] );
	}

	private function assertWriteForbidden( Database $db ) {
		try {
			$db->delete( 'some_table', [ 'id' => 57634126 ], __METHOD__ );
			$this->fail( 'Write operation should have failed!' );
		} catch ( DBError $ex ) {
			// check that the exception message contains "Write operation"
			$constraint = new PHPUnit_Framework_Constraint_StringContains( 'Write operation' );

			if ( !$constraint->evaluate( $ex->getMessage(), '', true ) ) {
				// re-throw original error, to preserve stack trace
				throw $ex;
			}
		}
	}

	private function assertWriteAllowed( Database $db ) {
		$table = $db->tableName( 'some_table' );
		// Trigger a transaction so that rollback() will remove all the tables.
		// Don't do this for MySQL/Oracle as they auto-commit transactions for DDL
		// statements such as CREATE TABLE.
		$useAtomicSection = in_array( $db->getType(), [ 'sqlite', 'postgres', 'mssql' ], true );
		try {
			$db->dropTable( 'some_table' ); // clear for sanity
			$this->assertNotEquals( $db::STATUS_TRX_ERROR, $db->trxStatus() );

			if ( $useAtomicSection ) {
				$db->startAtomic( __METHOD__ );
			}
			// Use only basic SQL and trivial types for these queries for compatibility
			$this->assertNotSame(
				false,
				$db->query( "CREATE TABLE $table (id INT, time INT)", __METHOD__ ),
				"table created"
			);
			$this->assertNotEquals( $db::STATUS_TRX_ERROR, $db->trxStatus() );
			$this->assertNotSame(
				false,
				$db->query( "DELETE FROM $table WHERE id=57634126", __METHOD__ ),
				"delete query"
			);
			$this->assertNotEquals( $db::STATUS_TRX_ERROR, $db->trxStatus() );
		} finally {
			if ( !$useAtomicSection ) {
				// Drop the table to clean up, ignoring any error.
				$db->dropTable( 'some_table' );
			}
			// Rollback the atomic section for sqlite's benefit.
			$db->rollback( __METHOD__, 'flush' );
			$this->assertNotEquals( $db::STATUS_TRX_ERROR, $db->trxStatus() );
		}
	}

	public function testServerAttributes() {
		$servers = [
			[ // master
				'dbname'      => 'my_unittest_wiki',
				'tablePrefix' => 'unittest_',
				'type'        => 'sqlite',
				'dbDirectory' => "some_directory",
				'load'        => 0
			]
		];

		$lb = new LoadBalancer( [
			'servers' => $servers,
			'localDomain' => new DatabaseDomain( 'my_unittest_wiki', null, 'unittest_' ),
			'loadMonitorClass' => LoadMonitorNull::class
		] );

		$this->assertTrue( $lb->getServerAttributes( 0 )[Database::ATTR_DB_LEVEL_LOCKING] );

		$servers = [
			[ // master
				'host'        => 'db1001',
				'user'        => 'wikiuser',
				'password'    => 'none',
				'dbname'      => 'my_unittest_wiki',
				'tablePrefix' => 'unittest_',
				'type'        => 'mysql',
				'load'        => 100
			],
			[ // emulated replica
				'host'        => 'db1002',
				'user'        => 'wikiuser',
				'password'    => 'none',
				'dbname'      => 'my_unittest_wiki',
				'tablePrefix' => 'unittest_',
				'type'        => 'mysql',
				'load'        => 100
			]
		];

		$lb = new LoadBalancer( [
			'servers' => $servers,
			'localDomain' => new DatabaseDomain( 'my_unittest_wiki', null, 'unittest_' ),
			'loadMonitorClass' => LoadMonitorNull::class
		] );

		$this->assertFalse( $lb->getServerAttributes( 1 )[Database::ATTR_DB_LEVEL_LOCKING] );
	}

	/**
	 * @covers LoadBalancer::openConnection()
	 * @covers LoadBalancer::getAnyOpenConnection()
	 */
	function testOpenConnection() {
		$lb = $this->newSingleServerLocalLoadBalancer();

		$i = $lb->getWriterIndex();
		$this->assertEquals( null, $lb->getAnyOpenConnection( $i ) );

		$conn1 = $lb->getConnection( $i );
		$this->assertNotEquals( null, $conn1 );
		$this->assertEquals( $conn1, $lb->getAnyOpenConnection( $i ) );
		$this->assertFalse( $conn1->getFlag( DBO_TRX ) );

		$conn2 = $lb->getConnection( $i, [], false, $lb::CONN_TRX_AUTOCOMMIT );
		$this->assertNotEquals( null, $conn2 );
		$this->assertFalse( $conn2->getFlag( DBO_TRX ) );

		if ( $lb->getServerAttributes( $i )[Database::ATTR_DB_LEVEL_LOCKING] ) {
			$this->assertEquals( null,
				$lb->getAnyOpenConnection( $i, $lb::CONN_TRX_AUTOCOMMIT ) );
			$this->assertEquals( $conn1,
				$lb->getConnection(
					$i, [], false, $lb::CONN_TRX_AUTOCOMMIT ), $lb::CONN_TRX_AUTOCOMMIT );
		} else {
			$this->assertEquals( $conn2,
				$lb->getAnyOpenConnection( $i, $lb::CONN_TRX_AUTOCOMMIT ) );
			$this->assertEquals( $conn2,
				$lb->getConnection( $i, [], false, $lb::CONN_TRX_AUTOCOMMIT ) );

			$conn2->startAtomic( __METHOD__ );
			try {
				$lb->getConnection( $i, [], false, $lb::CONN_TRX_AUTOCOMMIT );
				$conn2->endAtomic( __METHOD__ );
				$this->fail( "No exception thrown." );
			} catch ( DBUnexpectedError $e ) {
				$this->assertEquals(
					'Handle requested with CONN_TRX_AUTOCOMMIT yet it has a transaction',
					$e->getMessage()
				);
			}
			$conn2->endAtomic( __METHOD__ );
		}

		$lb->closeAll();
	}

	public function testTransactionCallbackChains() {
		global $wgDBserver, $wgDBname, $wgDBuser, $wgDBpassword, $wgDBtype, $wgSQLiteDataDir;

		$servers = [
			[
				'host' => $wgDBserver,
				'dbname' => $wgDBname,
				'tablePrefix' => $this->dbPrefix(),
				'user' => $wgDBuser,
				'password' => $wgDBpassword,
				'type' => $wgDBtype,
				'dbDirectory' => $wgSQLiteDataDir,
				'load' => 0,
				'flags' => DBO_TRX // simulate a web request with DBO_TRX
			],
		];

		$lb = new LoadBalancer( [
			'servers' => $servers,
			'localDomain' => new DatabaseDomain( $wgDBname, null, $this->dbPrefix() )
		] );

		$conn1 = $lb->openConnection( $lb->getWriterIndex(), false );
		$conn2 = $lb->openConnection( $lb->getWriterIndex(), '' );

		$count = 0;
		$lb->forEachOpenMasterConnection( function () use ( &$count ) {
			++$count;
		} );
		$this->assertEquals( 2, $count, 'Connection handle count' );

		$tlCalls = 0;
		$lb->setTransactionListener( 'test-listener', function () use ( &$tlCalls ) {
			++$tlCalls;
		} );

		$lb->beginMasterChanges( __METHOD__ );
		$bc = array_fill_keys( [ 'a', 'b', 'c', 'd' ], 0 );
		$conn1->onTransactionPreCommitOrIdle( function () use ( &$bc, $conn1, $conn2 ) {
			$bc['a'] = 1;
			$conn2->onTransactionPreCommitOrIdle( function () use ( &$bc, $conn1, $conn2 ) {
				$bc['b'] = 1;
				$conn1->onTransactionPreCommitOrIdle( function () use ( &$bc, $conn1, $conn2 ) {
					$bc['c'] = 1;
					$conn1->onTransactionPreCommitOrIdle( function () use ( &$bc, $conn1, $conn2 ) {
						$bc['d'] = 1;
					} );
				} );
			} );
		} );
		$lb->finalizeMasterChanges();
		$lb->approveMasterChanges( [] );
		$lb->commitMasterChanges( __METHOD__ );
		$lb->runMasterTransactionIdleCallbacks();
		$lb->runMasterTransactionListenerCallbacks();

		$this->assertEquals( array_fill_keys( [ 'a', 'b', 'c', 'd' ], 1 ), $bc );
		$this->assertEquals( 2, $tlCalls );

		$tlCalls = 0;
		$lb->beginMasterChanges( __METHOD__ );
		$ac = array_fill_keys( [ 'a', 'b', 'c', 'd' ], 0 );
		$conn1->onTransactionCommitOrIdle( function () use ( &$ac, $conn1, $conn2 ) {
			$ac['a'] = 1;
			$conn2->onTransactionCommitOrIdle( function () use ( &$ac, $conn1, $conn2 ) {
				$ac['b'] = 1;
				$conn1->onTransactionCommitOrIdle( function () use ( &$ac, $conn1, $conn2 ) {
					$ac['c'] = 1;
					$conn1->onTransactionCommitOrIdle( function () use ( &$ac, $conn1, $conn2 ) {
						$ac['d'] = 1;
					} );
				} );
			} );
		} );
		$lb->finalizeMasterChanges();
		$lb->approveMasterChanges( [] );
		$lb->commitMasterChanges( __METHOD__ );
		$lb->runMasterTransactionIdleCallbacks();
		$lb->runMasterTransactionListenerCallbacks();

		$this->assertEquals( array_fill_keys( [ 'a', 'b', 'c', 'd' ], 1 ), $ac );
		$this->assertEquals( 2, $tlCalls );

		$conn1->close();
		$conn2->close();
	}

	public function testDBConnRefReadsMasterAndReplicaRoles() {
		$lb = $this->newSingleServerLocalLoadBalancer();

		$rConn = $lb->getConnectionRef( DB_REPLICA );
		$wConn = $lb->getConnectionRef( DB_MASTER );
		$wConn2 = $lb->getConnectionRef( 0 );

		$v = [ 'value' => '1', '1' ];
		$sql = 'SELECT MAX(1) AS value';
		foreach ( [ $rConn, $wConn, $wConn2 ] as $conn ) {
			$conn->clearFlag( $conn::DBO_TRX );

			$res = $conn->query( $sql, __METHOD__ );
			$this->assertEquals( $v, $conn->fetchRow( $res ) );

			$res = $conn->query( $sql, __METHOD__, $conn::QUERY_REPLICA_ROLE );
			$this->assertEquals( $v, $conn->fetchRow( $res ) );
		}

		$wConn->getScopedLockAndFlush( 'key', __METHOD__, 1 );
		$wConn2->getScopedLockAndFlush( 'key2', __METHOD__, 1 );
	}

	/**
	 * @expectedException \Wikimedia\Rdbms\DBReadOnlyRoleError
	 */
	public function testDBConnRefWritesReplicaRole() {
		$lb = $this->newSingleServerLocalLoadBalancer();

		$rConn = $lb->getConnectionRef( DB_REPLICA );

		$rConn->query( 'DELETE FROM sometesttable WHERE 1=0' );
	}

	/**
	 * @expectedException \Wikimedia\Rdbms\DBReadOnlyRoleError
	 */
	public function testDBConnRefWritesReplicaRoleIndex() {
		$lb = $this->newMultiServerLocalLoadBalancer();

		$rConn = $lb->getConnectionRef( 1 );

		$rConn->query( 'DELETE FROM sometesttable WHERE 1=0' );
	}

	/**
	 * @expectedException \Wikimedia\Rdbms\DBReadOnlyRoleError
	 */
	public function testDBConnRefWritesReplicaRoleInsert() {
		$lb = $this->newMultiServerLocalLoadBalancer();

		$rConn = $lb->getConnectionRef( DB_REPLICA );

		$rConn->insert( 'test', [ 't' => 1 ], __METHOD__ );
	}

	public function testQueryGroupIndex() {
		$lb = $this->newMultiServerLocalLoadBalancer();
		/** @var LoadBalancer $lbWrapper */
		$lbWrapper = TestingAccessWrapper::newFromObject( $lb );

		$rGeneric = $lb->getConnectionRef( DB_REPLICA );
		$mainIndexPicked = $rGeneric->getLBInfo( 'serverIndex' );

		$this->assertEquals( $mainIndexPicked, $lbWrapper->getExistingReaderIndex( false ) );
		$this->assertTrue( in_array( $mainIndexPicked, [ 1, 2 ] ) );
		for ( $i = 0; $i < 300; ++$i ) {
			$rLog = $lb->getConnectionRef( DB_REPLICA, [] );
			$this->assertEquals(
				$mainIndexPicked,
				$rLog->getLBInfo( 'serverIndex' ),
				"Main index unchanged" );
		}

		$rRC = $lb->getConnectionRef( DB_REPLICA, [ 'recentchanges' ] );
		$rWL = $lb->getConnectionRef( DB_REPLICA, [ 'watchlist' ] );

		$this->assertEquals( 3, $rRC->getLBInfo( 'serverIndex' ) );
		$this->assertEquals( 3, $rWL->getLBInfo( 'serverIndex' ) );

		$rLog = $lb->getConnectionRef( DB_REPLICA, [ 'logging', 'watchlist' ] );
		$logIndexPicked = $rLog->getLBInfo( 'serverIndex' );

		$this->assertEquals( $logIndexPicked, $lbWrapper->getExistingReaderIndex( 'logging' ) );
		$this->assertTrue( in_array( $logIndexPicked, [ 4, 5 ] ) );

		for ( $i = 0; $i < 300; ++$i ) {
			$rLog = $lb->getConnectionRef( DB_REPLICA, [ 'logging', 'watchlist' ] );
			$this->assertEquals(
				$logIndexPicked, $rLog->getLBInfo( 'serverIndex' ), "Index unchanged" );
		}

		$rVslow = $lb->getConnectionRef( DB_REPLICA, [ 'vslow', 'logging' ] );
		$vslowIndexPicked = $rVslow->getLBInfo( 'serverIndex' );

		$this->assertEquals( $vslowIndexPicked, $lbWrapper->getExistingReaderIndex( 'vslow' ) );
		$this->assertEquals( 6, $vslowIndexPicked );
	}
}
