<?php
/**
 * Local file in the wiki's own database.
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
 * @ingroup FileAbstraction
 */

use Wikimedia\AtEase\AtEase;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IDatabase;
use MediaWiki\MediaWikiServices;

/**
 * Class to represent a local file in the wiki's own database
 *
 * Provides methods to retrieve paths (physical, logical, URL),
 * to generate image thumbnails or for uploading.
 *
 * Note that only the repo object knows what its file class is called. You should
 * never name a file class explictly outside of the repo class. Instead use the
 * repo's factory functions to generate file objects, for example:
 *
 * RepoGroup::singleton()->getLocalRepo()->newFile( $title );
 *
 * Consider the services container below;
 *
 * $services = MediaWikiServices::getInstance();
 *
 * The convenience services $services->getRepoGroup()->getLocalRepo()->newFile()
 * and $services->getRepoGroup()->findFile() should be sufficient in most cases.
 *
 * @TODO: DI - Instead of using MediaWikiServices::getInstance(), a service should
 * ideally accept a RepoGroup in its constructor and then, use $this->repoGroup->findFile()
 * and $this->repoGroup->getLocalRepo()->newFile().
 *
 * @ingroup FileAbstraction
 */
class LocalFile extends File {
	const VERSION = 11; // cache version

	const CACHE_FIELD_MAX_LEN = 1000;

	/** @var bool Does the file exist on disk? (loadFromXxx) */
	protected $fileExists;

	/** @var int Image width */
	protected $width;

	/** @var int Image height */
	protected $height;

	/** @var int Returned by getimagesize (loadFromXxx) */
	protected $bits;

	/** @var string MEDIATYPE_xxx (bitmap, drawing, audio...) */
	protected $media_type;

	/** @var string MIME type, determined by MimeAnalyzer::guessMimeType */
	protected $mime;

	/** @var int Size in bytes (loadFromXxx) */
	protected $size;

	/** @var string Handler-specific metadata */
	protected $metadata;

	/** @var string SHA-1 base 36 content hash */
	protected $sha1;

	/** @var bool Whether or not core data has been loaded from the database (loadFromXxx) */
	protected $dataLoaded;

	/** @var bool Whether or not lazy-loaded data has been loaded from the database */
	protected $extraDataLoaded;

	/** @var int Bitfield akin to rev_deleted */
	protected $deleted;

	/** @var string */
	protected $repoClass = LocalRepo::class;

	/** @var int Number of line to return by nextHistoryLine() (constructor) */
	private $historyLine;

	/** @var int Result of the query for the file's history (nextHistoryLine) */
	private $historyRes;

	/** @var string Major MIME type */
	private $major_mime;

	/** @var string Minor MIME type */
	private $minor_mime;

	/** @var string Upload timestamp */
	private $timestamp;

	/** @var User Uploader */
	private $user;

	/** @var string Description of current revision of the file */
	private $description;

	/** @var string TS_MW timestamp of the last change of the file description */
	private $descriptionTouched;

	/** @var bool Whether the row was upgraded on load */
	private $upgraded;

	/** @var bool Whether the row was scheduled to upgrade on load */
	private $upgrading;

	/** @var bool True if the image row is locked */
	private $locked;

	/** @var bool True if the image row is locked with a lock initiated transaction */
	private $lockedOwnTrx;

	/** @var bool True if file is not present in file system. Not to be cached in memcached */
	private $missing;

	// @note: higher than IDBAccessObject constants
	const LOAD_ALL = 16; // integer; load all the lazy fields too (like metadata)

	const ATOMIC_SECTION_LOCK = 'LocalFile::lockingTransaction';

	/**
	 * Create a LocalFile from a title
	 * Do not call this except from inside a repo class.
	 *
	 * Note: $unused param is only here to avoid an E_STRICT
	 *
	 * @param Title $title
	 * @param FileRepo $repo
	 * @param null $unused
	 *
	 * @return static
	 */
	static function newFromTitle( $title, $repo, $unused = null ) {
		return new static( $title, $repo );
	}

	/**
	 * Create a LocalFile from a title
	 * Do not call this except from inside a repo class.
	 *
	 * @param stdClass $row
	 * @param FileRepo $repo
	 *
	 * @return static
	 */
	static function newFromRow( $row, $repo ) {
		$title = Title::makeTitle( NS_FILE, $row->img_name );
		$file = new static( $title, $repo );
		$file->loadFromRow( $row );

		return $file;
	}

	/**
	 * Create a LocalFile from a SHA-1 key
	 * Do not call this except from inside a repo class.
	 *
	 * @param string $sha1 Base-36 SHA-1
	 * @param LocalRepo $repo
	 * @param string|bool $timestamp MW_timestamp (optional)
	 * @return bool|LocalFile
	 */
	static function newFromKey( $sha1, $repo, $timestamp = false ) {
		$dbr = $repo->getReplicaDB();

		$conds = [ 'img_sha1' => $sha1 ];
		if ( $timestamp ) {
			$conds['img_timestamp'] = $dbr->timestamp( $timestamp );
		}

		$fileQuery = static::getQueryInfo();
		$row = $dbr->selectRow(
			$fileQuery['tables'], $fileQuery['fields'], $conds, __METHOD__, [], $fileQuery['joins']
		);
		if ( $row ) {
			return static::newFromRow( $row, $repo );
		} else {
			return false;
		}
	}

	/**
	 * Fields in the image table
	 * @deprecated since 1.31, use self::getQueryInfo() instead.
	 * @return string[]
	 */
	static function selectFields() {
		global $wgActorTableSchemaMigrationStage;

		wfDeprecated( __METHOD__, '1.31' );
		if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_READ_NEW ) {
			// If code is using this instead of self::getQueryInfo(), there's a
			// decent chance it's going to try to directly access
			// $row->img_user or $row->img_user_text and we can't give it
			// useful values here once those aren't being used anymore.
			throw new BadMethodCallException(
				'Cannot use ' . __METHOD__
					. ' when $wgActorTableSchemaMigrationStage has SCHEMA_COMPAT_READ_NEW'
			);
		}

		return [
			'img_name',
			'img_size',
			'img_width',
			'img_height',
			'img_metadata',
			'img_bits',
			'img_media_type',
			'img_major_mime',
			'img_minor_mime',
			'img_user',
			'img_user_text',
			'img_actor' => 'NULL',
			'img_timestamp',
			'img_sha1',
		] + MediaWikiServices::getInstance()->getCommentStore()->getFields( 'img_description' );
	}

	/**
	 * Return the tables, fields, and join conditions to be selected to create
	 * a new localfile object.
	 * @since 1.31
	 * @param string[] $options
	 *   - omit-lazy: Omit fields that are lazily cached.
	 * @return array[] With three keys:
	 *   - tables: (string[]) to include in the `$table` to `IDatabase->select()`
	 *   - fields: (string[]) to include in the `$vars` to `IDatabase->select()`
	 *   - joins: (array) to include in the `$join_conds` to `IDatabase->select()`
	 */
	public static function getQueryInfo( array $options = [] ) {
		$commentQuery = MediaWikiServices::getInstance()->getCommentStore()->getJoin( 'img_description' );
		$actorQuery = ActorMigration::newMigration()->getJoin( 'img_user' );
		$ret = [
			'tables' => [ 'image' ] + $commentQuery['tables'] + $actorQuery['tables'],
			'fields' => [
				'img_name',
				'img_size',
				'img_width',
				'img_height',
				'img_metadata',
				'img_bits',
				'img_media_type',
				'img_major_mime',
				'img_minor_mime',
				'img_timestamp',
				'img_sha1',
			] + $commentQuery['fields'] + $actorQuery['fields'],
			'joins' => $commentQuery['joins'] + $actorQuery['joins'],
		];

		if ( in_array( 'omit-nonlazy', $options, true ) ) {
			// Internal use only for getting only the lazy fields
			$ret['fields'] = [];
		}
		if ( !in_array( 'omit-lazy', $options, true ) ) {
			// Note: Keep this in sync with self::getLazyCacheFields()
			$ret['fields'][] = 'img_metadata';
		}

		return $ret;
	}

	/**
	 * Do not call this except from inside a repo class.
	 * @param Title $title
	 * @param FileRepo $repo
	 */
	function __construct( $title, $repo ) {
		parent::__construct( $title, $repo );

		$this->metadata = '';
		$this->historyLine = 0;
		$this->historyRes = null;
		$this->dataLoaded = false;
		$this->extraDataLoaded = false;

		$this->assertRepoDefined();
		$this->assertTitleDefined();
	}

	/**
	 * Get the memcached key for the main data for this file, or false if
	 * there is no access to the shared cache.
	 * @return string|bool
	 */
	function getCacheKey() {
		return $this->repo->getSharedCacheKey( 'file', sha1( $this->getName() ) );
	}

	/**
	 * @param WANObjectCache $cache
	 * @return string[]
	 * @since 1.28
	 */
	public function getMutableCacheKeys( WANObjectCache $cache ) {
		return [ $this->getCacheKey() ];
	}

	/**
	 * Try to load file metadata from memcached, falling back to the database
	 */
	private function loadFromCache() {
		$this->dataLoaded = false;
		$this->extraDataLoaded = false;

		$key = $this->getCacheKey();
		if ( !$key ) {
			$this->loadFromDB( self::READ_NORMAL );

			return;
		}

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cachedValues = $cache->getWithSetCallback(
			$key,
			$cache::TTL_WEEK,
			function ( $oldValue, &$ttl, array &$setOpts ) use ( $cache ) {
				$setOpts += Database::getCacheSetOptions( $this->repo->getReplicaDB() );

				$this->loadFromDB( self::READ_NORMAL );

				$fields = $this->getCacheFields( '' );
				$cacheVal['fileExists'] = $this->fileExists;
				if ( $this->fileExists ) {
					foreach ( $fields as $field ) {
						$cacheVal[$field] = $this->$field;
					}
				}
				$cacheVal['user'] = $this->user ? $this->user->getId() : 0;
				$cacheVal['user_text'] = $this->user ? $this->user->getName() : '';
				$cacheVal['actor'] = $this->user ? $this->user->getActorId() : null;

				// Strip off excessive entries from the subset of fields that can become large.
				// If the cache value gets to large it will not fit in memcached and nothing will
				// get cached at all, causing master queries for any file access.
				foreach ( $this->getLazyCacheFields( '' ) as $field ) {
					if ( isset( $cacheVal[$field] )
						&& strlen( $cacheVal[$field] ) > 100 * 1024
					) {
						unset( $cacheVal[$field] ); // don't let the value get too big
					}
				}

				if ( $this->fileExists ) {
					$ttl = $cache->adaptiveTTL( wfTimestamp( TS_UNIX, $this->timestamp ), $ttl );
				} else {
					$ttl = $cache::TTL_DAY;
				}

				return $cacheVal;
			},
			[ 'version' => self::VERSION ]
		);

		$this->fileExists = $cachedValues['fileExists'];
		if ( $this->fileExists ) {
			$this->setProps( $cachedValues );
		}

		$this->dataLoaded = true;
		$this->extraDataLoaded = true;
		foreach ( $this->getLazyCacheFields( '' ) as $field ) {
			$this->extraDataLoaded = $this->extraDataLoaded && isset( $cachedValues[$field] );
		}
	}

	/**
	 * Purge the file object/metadata cache
	 */
	public function invalidateCache() {
		$key = $this->getCacheKey();
		if ( !$key ) {
			return;
		}

		$this->repo->getMasterDB()->onTransactionPreCommitOrIdle(
			function () use ( $key ) {
				MediaWikiServices::getInstance()->getMainWANObjectCache()->delete( $key );
			},
			__METHOD__
		);
	}

	/**
	 * Load metadata from the file itself
	 */
	function loadFromFile() {
		$props = $this->repo->getFileProps( $this->getVirtualUrl() );
		$this->setProps( $props );
	}

	/**
	 * Returns the list of object properties that are included as-is in the cache.
	 * @param string $prefix Must be the empty string
	 * @return string[]
	 * @since 1.31 No longer accepts a non-empty $prefix
	 */
	protected function getCacheFields( $prefix = 'img_' ) {
		if ( $prefix !== '' ) {
			throw new InvalidArgumentException(
				__METHOD__ . ' with a non-empty prefix is no longer supported.'
			);
		}

		// See self::getQueryInfo() for the fetching of the data from the DB,
		// self::loadFromRow() for the loading of the object from the DB row,
		// and self::loadFromCache() for the caching, and self::setProps() for
		// populating the object from an array of data.
		return [ 'size', 'width', 'height', 'bits', 'media_type',
			'major_mime', 'minor_mime', 'metadata', 'timestamp', 'sha1', 'description' ];
	}

	/**
	 * Returns the list of object properties that are included as-is in the
	 * cache, only when they're not too big, and are lazily loaded by self::loadExtraFromDB().
	 * @param string $prefix Must be the empty string
	 * @return string[]
	 * @since 1.31 No longer accepts a non-empty $prefix
	 */
	protected function getLazyCacheFields( $prefix = 'img_' ) {
		if ( $prefix !== '' ) {
			throw new InvalidArgumentException(
				__METHOD__ . ' with a non-empty prefix is no longer supported.'
			);
		}

		// Keep this in sync with the omit-lazy option in self::getQueryInfo().
		return [ 'metadata' ];
	}

	/**
	 * Load file metadata from the DB
	 * @param int $flags
	 */
	function loadFromDB( $flags = 0 ) {
		$fname = static::class . '::' . __FUNCTION__;

		# Unconditionally set loaded=true, we don't want the accessors constantly rechecking
		$this->dataLoaded = true;
		$this->extraDataLoaded = true;

		$dbr = ( $flags & self::READ_LATEST )
			? $this->repo->getMasterDB()
			: $this->repo->getReplicaDB();

		$fileQuery = static::getQueryInfo();
		$row = $dbr->selectRow(
			$fileQuery['tables'],
			$fileQuery['fields'],
			[ 'img_name' => $this->getName() ],
			$fname,
			[],
			$fileQuery['joins']
		);

		if ( $row ) {
			$this->loadFromRow( $row );
		} else {
			$this->fileExists = false;
		}
	}

	/**
	 * Load lazy file metadata from the DB.
	 * This covers fields that are sometimes not cached.
	 */
	protected function loadExtraFromDB() {
		$fname = static::class . '::' . __FUNCTION__;

		# Unconditionally set loaded=true, we don't want the accessors constantly rechecking
		$this->extraDataLoaded = true;

		$fieldMap = $this->loadExtraFieldsWithTimestamp( $this->repo->getReplicaDB(), $fname );
		if ( !$fieldMap ) {
			$fieldMap = $this->loadExtraFieldsWithTimestamp( $this->repo->getMasterDB(), $fname );
		}

		if ( $fieldMap ) {
			foreach ( $fieldMap as $name => $value ) {
				$this->$name = $value;
			}
		} else {
			throw new MWException( "Could not find data for image '{$this->getName()}'." );
		}
	}

	/**
	 * @param IDatabase $dbr
	 * @param string $fname
	 * @return string[]|bool
	 */
	private function loadExtraFieldsWithTimestamp( $dbr, $fname ) {
		$fieldMap = false;

		$fileQuery = self::getQueryInfo( [ 'omit-nonlazy' ] );
		$row = $dbr->selectRow(
			$fileQuery['tables'],
			$fileQuery['fields'],
			[
				'img_name' => $this->getName(),
				'img_timestamp' => $dbr->timestamp( $this->getTimestamp() ),
			],
			$fname,
			[],
			$fileQuery['joins']
		);
		if ( $row ) {
			$fieldMap = $this->unprefixRow( $row, 'img_' );
		} else {
			# File may have been uploaded over in the meantime; check the old versions
			$fileQuery = OldLocalFile::getQueryInfo( [ 'omit-nonlazy' ] );
			$row = $dbr->selectRow(
				$fileQuery['tables'],
				$fileQuery['fields'],
				[
					'oi_name' => $this->getName(),
					'oi_timestamp' => $dbr->timestamp( $this->getTimestamp() ),
				],
				$fname,
				[],
				$fileQuery['joins']
			);
			if ( $row ) {
				$fieldMap = $this->unprefixRow( $row, 'oi_' );
			}
		}

		if ( isset( $fieldMap['metadata'] ) ) {
			$fieldMap['metadata'] = $this->repo->getReplicaDB()->decodeBlob( $fieldMap['metadata'] );
		}

		return $fieldMap;
	}

	/**
	 * @param array|object $row
	 * @param string $prefix
	 * @throws MWException
	 * @return array
	 */
	protected function unprefixRow( $row, $prefix = 'img_' ) {
		$array = (array)$row;
		$prefixLength = strlen( $prefix );

		// Sanity check prefix once
		if ( substr( key( $array ), 0, $prefixLength ) !== $prefix ) {
			throw new MWException( __METHOD__ . ': incorrect $prefix parameter' );
		}

		$decoded = [];
		foreach ( $array as $name => $value ) {
			$decoded[substr( $name, $prefixLength )] = $value;
		}

		return $decoded;
	}

	/**
	 * Decode a row from the database (either object or array) to an array
	 * with timestamps and MIME types decoded, and the field prefix removed.
	 * @param object $row
	 * @param string $prefix
	 * @throws MWException
	 * @return array
	 */
	function decodeRow( $row, $prefix = 'img_' ) {
		$decoded = $this->unprefixRow( $row, $prefix );

		$decoded['description'] = MediaWikiServices::getInstance()->getCommentStore()
			->getComment( 'description', (object)$decoded )->text;

		$decoded['user'] = User::newFromAnyId(
			$decoded['user'] ?? null,
			$decoded['user_text'] ?? null,
			$decoded['actor'] ?? null
		);
		unset( $decoded['user_text'], $decoded['actor'] );

		$decoded['timestamp'] = wfTimestamp( TS_MW, $decoded['timestamp'] );

		$decoded['metadata'] = $this->repo->getReplicaDB()->decodeBlob( $decoded['metadata'] );

		if ( empty( $decoded['major_mime'] ) ) {
			$decoded['mime'] = 'unknown/unknown';
		} else {
			if ( !$decoded['minor_mime'] ) {
				$decoded['minor_mime'] = 'unknown';
			}
			$decoded['mime'] = $decoded['major_mime'] . '/' . $decoded['minor_mime'];
		}

		// Trim zero padding from char/binary field
		$decoded['sha1'] = rtrim( $decoded['sha1'], "\0" );

		// Normalize some fields to integer type, per their database definition.
		// Use unary + so that overflows will be upgraded to double instead of
		// being trucated as with intval(). This is important to allow >2GB
		// files on 32-bit systems.
		foreach ( [ 'size', 'width', 'height', 'bits' ] as $field ) {
			$decoded[$field] = +$decoded[$field];
		}

		return $decoded;
	}

	/**
	 * Load file metadata from a DB result row
	 *
	 * @param object $row
	 * @param string $prefix
	 */
	function loadFromRow( $row, $prefix = 'img_' ) {
		$this->dataLoaded = true;
		$this->extraDataLoaded = true;

		$array = $this->decodeRow( $row, $prefix );

		foreach ( $array as $name => $value ) {
			$this->$name = $value;
		}

		$this->fileExists = true;
	}

	/**
	 * Load file metadata from cache or DB, unless already loaded
	 * @param int $flags
	 */
	function load( $flags = 0 ) {
		if ( !$this->dataLoaded ) {
			if ( $flags & self::READ_LATEST ) {
				$this->loadFromDB( $flags );
			} else {
				$this->loadFromCache();
			}
		}

		if ( ( $flags & self::LOAD_ALL ) && !$this->extraDataLoaded ) {
			// @note: loads on name/timestamp to reduce race condition problems
			$this->loadExtraFromDB();
		}
	}

	/**
	 * Upgrade a row if it needs it
	 */
	protected function maybeUpgradeRow() {
		global $wgUpdateCompatibleMetadata;

		if ( wfReadOnly() || $this->upgrading ) {
			return;
		}

		$upgrade = false;
		if ( is_null( $this->media_type ) || $this->mime == 'image/svg' ) {
			$upgrade = true;
		} else {
			$handler = $this->getHandler();
			if ( $handler ) {
				$validity = $handler->isMetadataValid( $this, $this->getMetadata() );
				if ( $validity === MediaHandler::METADATA_BAD ) {
					$upgrade = true;
				} elseif ( $validity === MediaHandler::METADATA_COMPATIBLE ) {
					$upgrade = $wgUpdateCompatibleMetadata;
				}
			}
		}

		if ( $upgrade ) {
			$this->upgrading = true;
			// Defer updates unless in auto-commit CLI mode
			DeferredUpdates::addCallableUpdate( function () {
				$this->upgrading = false; // avoid duplicate updates
				try {
					$this->upgradeRow();
				} catch ( LocalFileLockError $e ) {
					// let the other process handle it (or do it next time)
				}
			} );
		}
	}

	/**
	 * @return bool Whether upgradeRow() ran for this object
	 */
	function getUpgraded() {
		return $this->upgraded;
	}

	/**
	 * Fix assorted version-related problems with the image row by reloading it from the file
	 */
	function upgradeRow() {
		$this->lock();

		$this->loadFromFile();

		# Don't destroy file info of missing files
		if ( !$this->fileExists ) {
			$this->unlock();
			wfDebug( __METHOD__ . ": file does not exist, aborting\n" );

			return;
		}

		$dbw = $this->repo->getMasterDB();
		list( $major, $minor ) = self::splitMime( $this->mime );

		if ( wfReadOnly() ) {
			$this->unlock();

			return;
		}
		wfDebug( __METHOD__ . ': upgrading ' . $this->getName() . " to the current schema\n" );

		$dbw->update( 'image',
			[
				'img_size' => $this->size, // sanity
				'img_width' => $this->width,
				'img_height' => $this->height,
				'img_bits' => $this->bits,
				'img_media_type' => $this->media_type,
				'img_major_mime' => $major,
				'img_minor_mime' => $minor,
				'img_metadata' => $dbw->encodeBlob( $this->metadata ),
				'img_sha1' => $this->sha1,
			],
			[ 'img_name' => $this->getName() ],
			__METHOD__
		);

		$this->invalidateCache();

		$this->unlock();
		$this->upgraded = true; // avoid rework/retries
	}

	/**
	 * Set properties in this object to be equal to those given in the
	 * associative array $info. Only cacheable fields can be set.
	 * All fields *must* be set in $info except for getLazyCacheFields().
	 *
	 * If 'mime' is given, it will be split into major_mime/minor_mime.
	 * If major_mime/minor_mime are given, $this->mime will also be set.
	 *
	 * @param array $info
	 */
	function setProps( $info ) {
		$this->dataLoaded = true;
		$fields = $this->getCacheFields( '' );
		$fields[] = 'fileExists';

		foreach ( $fields as $field ) {
			if ( isset( $info[$field] ) ) {
				$this->$field = $info[$field];
			}
		}

		if ( isset( $info['user'] ) || isset( $info['user_text'] ) || isset( $info['actor'] ) ) {
			$this->user = User::newFromAnyId(
				$info['user'] ?? null,
				$info['user_text'] ?? null,
				$info['actor'] ?? null
			);
		}

		// Fix up mime fields
		if ( isset( $info['major_mime'] ) ) {
			$this->mime = "{$info['major_mime']}/{$info['minor_mime']}";
		} elseif ( isset( $info['mime'] ) ) {
			$this->mime = $info['mime'];
			list( $this->major_mime, $this->minor_mime ) = self::splitMime( $this->mime );
		}
	}

	/** splitMime inherited */
	/** getName inherited */
	/** getTitle inherited */
	/** getURL inherited */
	/** getViewURL inherited */
	/** getPath inherited */
	/** isVisible inherited */

	/**
	 * Checks if this file exists in its parent repo, as referenced by its
	 * virtual URL.
	 *
	 * @return bool
	 */
	function isMissing() {
		if ( $this->missing === null ) {
			$fileExists = $this->repo->fileExists( $this->getVirtualUrl() );
			$this->missing = !$fileExists;
		}

		return $this->missing;
	}

	/**
	 * Return the width of the image
	 *
	 * @param int $page
	 * @return int
	 */
	public function getWidth( $page = 1 ) {
		$page = (int)$page;
		if ( $page < 1 ) {
			$page = 1;
		}

		$this->load();

		if ( $this->isMultipage() ) {
			$handler = $this->getHandler();
			if ( !$handler ) {
				return 0;
			}
			$dim = $handler->getPageDimensions( $this, $page );
			if ( $dim ) {
				return $dim['width'];
			} else {
				// For non-paged media, the false goes through an
				// intval, turning failure into 0, so do same here.
				return 0;
			}
		} else {
			return $this->width;
		}
	}

	/**
	 * Return the height of the image
	 *
	 * @param int $page
	 * @return int
	 */
	public function getHeight( $page = 1 ) {
		$page = (int)$page;
		if ( $page < 1 ) {
			$page = 1;
		}

		$this->load();

		if ( $this->isMultipage() ) {
			$handler = $this->getHandler();
			if ( !$handler ) {
				return 0;
			}
			$dim = $handler->getPageDimensions( $this, $page );
			if ( $dim ) {
				return $dim['height'];
			} else {
				// For non-paged media, the false goes through an
				// intval, turning failure into 0, so do same here.
				return 0;
			}
		} else {
			return $this->height;
		}
	}

	/**
	 * Returns user who uploaded the file
	 *
	 * @param string $type 'text', 'id', or 'object'
	 * @return int|string|User
	 * @since 1.31 Added 'object'
	 */
	function getUser( $type = 'text' ) {
		$this->load();

		if ( $type === 'object' ) {
			return $this->user;
		} elseif ( $type === 'text' ) {
			return $this->user->getName();
		} elseif ( $type === 'id' ) {
			return $this->user->getId();
		}

		throw new MWException( "Unknown type '$type'." );
	}

	/**
	 * Get short description URL for a file based on the page ID.
	 *
	 * @return string|null
	 * @throws MWException
	 * @since 1.27
	 */
	public function getDescriptionShortUrl() {
		$pageId = $this->title->getArticleID();

		if ( $pageId !== null ) {
			$url = $this->repo->makeUrl( [ 'curid' => $pageId ] );
			if ( $url !== false ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * Get handler-specific metadata
	 * @return string
	 */
	function getMetadata() {
		$this->load( self::LOAD_ALL ); // large metadata is loaded in another step
		return $this->metadata;
	}

	/**
	 * @return int
	 */
	function getBitDepth() {
		$this->load();

		return (int)$this->bits;
	}

	/**
	 * Returns the size of the image file, in bytes
	 * @return int
	 */
	public function getSize() {
		$this->load();

		return $this->size;
	}

	/**
	 * Returns the MIME type of the file.
	 * @return string
	 */
	function getMimeType() {
		$this->load();

		return $this->mime;
	}

	/**
	 * Returns the type of the media in the file.
	 * Use the value returned by this function with the MEDIATYPE_xxx constants.
	 * @return string
	 */
	function getMediaType() {
		$this->load();

		return $this->media_type;
	}

	/** canRender inherited */
	/** mustRender inherited */
	/** allowInlineDisplay inherited */
	/** isSafeFile inherited */
	/** isTrustedFile inherited */

	/**
	 * Returns true if the file exists on disk.
	 * @return bool Whether file exist on disk.
	 */
	public function exists() {
		$this->load();

		return $this->fileExists;
	}

	/** getTransformScript inherited */
	/** getUnscaledThumb inherited */
	/** thumbName inherited */
	/** createThumb inherited */
	/** transform inherited */

	/** getHandler inherited */
	/** iconThumb inherited */
	/** getLastError inherited */

	/**
	 * Get all thumbnail names previously generated for this file
	 * @param string|bool $archiveName Name of an archive file, default false
	 * @return array First element is the base dir, then files in that base dir.
	 */
	function getThumbnails( $archiveName = false ) {
		if ( $archiveName ) {
			$dir = $this->getArchiveThumbPath( $archiveName );
		} else {
			$dir = $this->getThumbPath();
		}

		$backend = $this->repo->getBackend();
		$files = [ $dir ];
		try {
			$iterator = $backend->getFileList( [ 'dir' => $dir ] );
			foreach ( $iterator as $file ) {
				$files[] = $file;
			}
		} catch ( FileBackendError $e ) {
		} // suppress (T56674)

		return $files;
	}

	/**
	 * Refresh metadata in memcached, but don't touch thumbnails or CDN
	 */
	function purgeMetadataCache() {
		$this->invalidateCache();
	}

	/**
	 * Delete all previously generated thumbnails, refresh metadata in memcached and purge the CDN.
	 *
	 * @param array $options An array potentially with the key forThumbRefresh.
	 *
	 * @note This used to purge old thumbnails by default as well, but doesn't anymore.
	 */
	function purgeCache( $options = [] ) {
		// Refresh metadata cache
		$this->maybeUpgradeRow();
		$this->purgeMetadataCache();

		// Delete thumbnails
		$this->purgeThumbnails( $options );

		// Purge CDN cache for this file
		DeferredUpdates::addUpdate(
			new CdnCacheUpdate( [ $this->getUrl() ] ),
			DeferredUpdates::PRESEND
		);
	}

	/**
	 * Delete cached transformed files for an archived version only.
	 * @param string $archiveName Name of the archived file
	 */
	function purgeOldThumbnails( $archiveName ) {
		// Get a list of old thumbnails and URLs
		$files = $this->getThumbnails( $archiveName );

		// Purge any custom thumbnail caches
		Hooks::run( 'LocalFilePurgeThumbnails', [ $this, $archiveName ] );

		// Delete thumbnails
		$dir = array_shift( $files );
		$this->purgeThumbList( $dir, $files );

		// Purge the CDN
		$urls = [];
		foreach ( $files as $file ) {
			$urls[] = $this->getArchiveThumbUrl( $archiveName, $file );
		}
		DeferredUpdates::addUpdate( new CdnCacheUpdate( $urls ), DeferredUpdates::PRESEND );
	}

	/**
	 * Delete cached transformed files for the current version only.
	 * @param array $options
	 */
	public function purgeThumbnails( $options = [] ) {
		$files = $this->getThumbnails();
		// Always purge all files from CDN regardless of handler filters
		$urls = [];
		foreach ( $files as $file ) {
			$urls[] = $this->getThumbUrl( $file );
		}
		array_shift( $urls ); // don't purge directory

		// Give media handler a chance to filter the file purge list
		if ( !empty( $options['forThumbRefresh'] ) ) {
			$handler = $this->getHandler();
			if ( $handler ) {
				$handler->filterThumbnailPurgeList( $files, $options );
			}
		}

		// Purge any custom thumbnail caches
		Hooks::run( 'LocalFilePurgeThumbnails', [ $this, false ] );

		// Delete thumbnails
		$dir = array_shift( $files );
		$this->purgeThumbList( $dir, $files );

		// Purge the CDN
		DeferredUpdates::addUpdate( new CdnCacheUpdate( $urls ), DeferredUpdates::PRESEND );
	}

	/**
	 * Prerenders a configurable set of thumbnails
	 *
	 * @since 1.28
	 */
	public function prerenderThumbnails() {
		global $wgUploadThumbnailRenderMap;

		$jobs = [];

		$sizes = $wgUploadThumbnailRenderMap;
		rsort( $sizes );

		foreach ( $sizes as $size ) {
			if ( $this->isVectorized() || $this->getWidth() > $size ) {
				$jobs[] = new ThumbnailRenderJob(
					$this->getTitle(),
					[ 'transformParams' => [ 'width' => $size ] ]
				);
			}
		}

		if ( $jobs ) {
			JobQueueGroup::singleton()->lazyPush( $jobs );
		}
	}

	/**
	 * Delete a list of thumbnails visible at urls
	 * @param string $dir Base dir of the files.
	 * @param array $files Array of strings: relative filenames (to $dir)
	 */
	protected function purgeThumbList( $dir, $files ) {
		$fileListDebug = strtr(
			var_export( $files, true ),
			[ "\n" => '' ]
		);
		wfDebug( __METHOD__ . ": $fileListDebug\n" );

		$purgeList = [];
		foreach ( $files as $file ) {
			if ( $this->repo->supportsSha1URLs() ) {
				$reference = $this->getSha1();
			} else {
				$reference = $this->getName();
			}

			# Check that the reference (filename or sha1) is part of the thumb name
			# This is a basic sanity check to avoid erasing unrelated directories
			if ( strpos( $file, $reference ) !== false
				|| strpos( $file, "-thumbnail" ) !== false // "short" thumb name
			) {
				$purgeList[] = "{$dir}/{$file}";
			}
		}

		# Delete the thumbnails
		$this->repo->quickPurgeBatch( $purgeList );
		# Clear out the thumbnail directory if empty
		$this->repo->quickCleanDir( $dir );
	}

	/** purgeDescription inherited */
	/** purgeEverything inherited */

	/**
	 * @param int|null $limit Optional: Limit to number of results
	 * @param string|int|null $start Optional: Timestamp, start from
	 * @param string|int|null $end Optional: Timestamp, end at
	 * @param bool $inc
	 * @return OldLocalFile[]
	 */
	function getHistory( $limit = null, $start = null, $end = null, $inc = true ) {
		$dbr = $this->repo->getReplicaDB();
		$oldFileQuery = OldLocalFile::getQueryInfo();

		$tables = $oldFileQuery['tables'];
		$fields = $oldFileQuery['fields'];
		$join_conds = $oldFileQuery['joins'];
		$conds = $opts = [];
		$eq = $inc ? '=' : '';
		$conds[] = "oi_name = " . $dbr->addQuotes( $this->title->getDBkey() );

		if ( $start ) {
			$conds[] = "oi_timestamp <$eq " . $dbr->addQuotes( $dbr->timestamp( $start ) );
		}

		if ( $end ) {
			$conds[] = "oi_timestamp >$eq " . $dbr->addQuotes( $dbr->timestamp( $end ) );
		}

		if ( $limit ) {
			$opts['LIMIT'] = $limit;
		}

		// Search backwards for time > x queries
		$order = ( !$start && $end !== null ) ? 'ASC' : 'DESC';
		$opts['ORDER BY'] = "oi_timestamp $order";
		$opts['USE INDEX'] = [ 'oldimage' => 'oi_name_timestamp' ];

		// Avoid PHP 7.1 warning from passing $this by reference
		$localFile = $this;
		Hooks::run( 'LocalFile::getHistory', [ &$localFile, &$tables, &$fields,
			&$conds, &$opts, &$join_conds ] );

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $opts, $join_conds );
		$r = [];

		foreach ( $res as $row ) {
			$r[] = $this->repo->newFileFromRow( $row );
		}

		if ( $order == 'ASC' ) {
			$r = array_reverse( $r ); // make sure it ends up descending
		}

		return $r;
	}

	/**
	 * Returns the history of this file, line by line.
	 * starts with current version, then old versions.
	 * uses $this->historyLine to check which line to return:
	 *  0      return line for current version
	 *  1      query for old versions, return first one
	 *  2, ... return next old version from above query
	 * @return bool
	 */
	public function nextHistoryLine() {
		# Polymorphic function name to distinguish foreign and local fetches
		$fname = static::class . '::' . __FUNCTION__;

		$dbr = $this->repo->getReplicaDB();

		if ( $this->historyLine == 0 ) { // called for the first time, return line from cur
			$fileQuery = self::getQueryInfo();
			$this->historyRes = $dbr->select( $fileQuery['tables'],
				$fileQuery['fields'] + [
					'oi_archive_name' => $dbr->addQuotes( '' ),
					'oi_deleted' => 0,
				],
				[ 'img_name' => $this->title->getDBkey() ],
				$fname,
				[],
				$fileQuery['joins']
			);

			if ( $dbr->numRows( $this->historyRes ) == 0 ) {
				$this->historyRes = null;

				return false;
			}
		} elseif ( $this->historyLine == 1 ) {
			$fileQuery = OldLocalFile::getQueryInfo();
			$this->historyRes = $dbr->select(
				$fileQuery['tables'],
				$fileQuery['fields'],
				[ 'oi_name' => $this->title->getDBkey() ],
				$fname,
				[ 'ORDER BY' => 'oi_timestamp DESC' ],
				$fileQuery['joins']
			);
		}
		$this->historyLine++;

		return $dbr->fetchObject( $this->historyRes );
	}

	/**
	 * Reset the history pointer to the first element of the history
	 */
	public function resetHistory() {
		$this->historyLine = 0;

		if ( !is_null( $this->historyRes ) ) {
			$this->historyRes = null;
		}
	}

	/** getHashPath inherited */
	/** getRel inherited */
	/** getUrlRel inherited */
	/** getArchiveRel inherited */
	/** getArchivePath inherited */
	/** getThumbPath inherited */
	/** getArchiveUrl inherited */
	/** getThumbUrl inherited */
	/** getArchiveVirtualUrl inherited */
	/** getThumbVirtualUrl inherited */
	/** isHashed inherited */

	/**
	 * Upload a file and record it in the DB
	 * @param string|FSFile $src Source storage path, virtual URL, or filesystem path
	 * @param string $comment Upload description
	 * @param string $pageText Text to use for the new description page,
	 *   if a new description page is created
	 * @param int|bool $flags Flags for publish()
	 * @param array|bool $props File properties, if known. This can be used to
	 *   reduce the upload time when uploading virtual URLs for which the file
	 *   info is already known
	 * @param string|bool $timestamp Timestamp for img_timestamp, or false to use the
	 *   current time
	 * @param User|null $user User object or null to use $wgUser
	 * @param string[] $tags Change tags to add to the log entry and page revision.
	 *   (This doesn't check $user's permissions.)
	 * @param bool $createNullRevision Set to false to avoid creation of a null revision on file
	 *   upload, see T193621
	 * @param bool $revert If this file upload is a revert
	 * @return Status On success, the value member contains the
	 *     archive name, or an empty string if it was a new file.
	 */
	function upload( $src, $comment, $pageText, $flags = 0, $props = false,
		$timestamp = false, $user = null, $tags = [],
		$createNullRevision = true, $revert = false
	) {
		if ( $this->getRepo()->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		} elseif ( MediaWikiServices::getInstance()->getRevisionStore()->isReadOnly() ) {
			// Check this in advance to avoid writing to FileBackend and the file tables,
			// only to fail on insert the revision due to the text store being unavailable.
			return $this->readOnlyFatalStatus();
		}

		$srcPath = ( $src instanceof FSFile ) ? $src->getPath() : $src;
		if ( !$props ) {
			if ( FileRepo::isVirtualUrl( $srcPath )
				|| FileBackend::isStoragePath( $srcPath )
			) {
				$props = $this->repo->getFileProps( $srcPath );
			} else {
				$mwProps = new MWFileProps( MediaWikiServices::getInstance()->getMimeAnalyzer() );
				$props = $mwProps->getPropsFromPath( $srcPath, true );
			}
		}

		$options = [];
		$handler = MediaHandler::getHandler( $props['mime'] );
		if ( $handler ) {
			$metadata = AtEase::quietCall( 'unserialize', $props['metadata'] );

			if ( !is_array( $metadata ) ) {
				$metadata = [];
			}

			$options['headers'] = $handler->getContentHeaders( $metadata );
		} else {
			$options['headers'] = [];
		}

		// Trim spaces on user supplied text
		$comment = trim( $comment );

		$this->lock();
		$status = $this->publish( $src, $flags, $options );

		if ( $status->successCount >= 2 ) {
			// There will be a copy+(one of move,copy,store).
			// The first succeeding does not commit us to updating the DB
			// since it simply copied the current version to a timestamped file name.
			// It is only *preferable* to avoid leaving such files orphaned.
			// Once the second operation goes through, then the current version was
			// updated and we must therefore update the DB too.
			$oldver = $status->value;
			$uploadStatus = $this->recordUpload2(
				$oldver,
				$comment,
				$pageText,
				$props,
				$timestamp,
				$user,
				$tags,
				$createNullRevision,
				$revert
			);
			if ( !$uploadStatus->isOK() ) {
				if ( $uploadStatus->hasMessage( 'filenotfound' ) ) {
					// update filenotfound error with more specific path
					$status->fatal( 'filenotfound', $srcPath );
				} else {
					$status->merge( $uploadStatus );
				}
			}
		}

		$this->unlock();
		return $status;
	}

	/**
	 * Record a file upload in the upload log and the image table
	 * @param string $oldver
	 * @param string $desc
	 * @param string $license
	 * @param string $copyStatus
	 * @param string $source
	 * @param bool $watch
	 * @param string|bool $timestamp
	 * @param User|null $user User object or null to use $wgUser
	 * @return bool
	 */
	function recordUpload( $oldver, $desc, $license = '', $copyStatus = '', $source = '',
		$watch = false, $timestamp = false, User $user = null ) {
		if ( !$user ) {
			global $wgUser;
			$user = $wgUser;
		}

		$pageText = SpecialUpload::getInitialPageText( $desc, $license, $copyStatus, $source );

		if ( !$this->recordUpload2( $oldver, $desc, $pageText, false, $timestamp, $user )->isOK() ) {
			return false;
		}

		if ( $watch ) {
			$user->addWatch( $this->getTitle() );
		}

		return true;
	}

	/**
	 * Record a file upload in the upload log and the image table
	 * @param string $oldver
	 * @param string $comment
	 * @param string $pageText
	 * @param bool|array $props
	 * @param string|bool $timestamp
	 * @param null|User $user
	 * @param string[] $tags
	 * @param bool $createNullRevision Set to false to avoid creation of a null revision on file
	 *   upload, see T193621
	 * @param bool $revert If this file upload is a revert
	 * @return Status
	 */
	function recordUpload2(
		$oldver, $comment, $pageText, $props = false, $timestamp = false, $user = null, $tags = [],
		$createNullRevision = true, $revert = false
	) {
		global $wgActorTableSchemaMigrationStage;

		if ( is_null( $user ) ) {
			global $wgUser;
			$user = $wgUser;
		}

		$dbw = $this->repo->getMasterDB();

		# Imports or such might force a certain timestamp; otherwise we generate
		# it and can fudge it slightly to keep (name,timestamp) unique on re-upload.
		if ( $timestamp === false ) {
			$timestamp = $dbw->timestamp();
			$allowTimeKludge = true;
		} else {
			$allowTimeKludge = false;
		}

		$props = $props ?: $this->repo->getFileProps( $this->getVirtualUrl() );
		$props['description'] = $comment;
		$props['user'] = $user->getId();
		$props['user_text'] = $user->getName();
		$props['actor'] = $user->getActorId( $dbw );
		$props['timestamp'] = wfTimestamp( TS_MW, $timestamp ); // DB -> TS_MW
		$this->setProps( $props );

		# Fail now if the file isn't there
		if ( !$this->fileExists ) {
			wfDebug( __METHOD__ . ": File " . $this->getRel() . " went missing!\n" );

			return Status::newFatal( 'filenotfound', $this->getRel() );
		}

		$dbw->startAtomic( __METHOD__ );

		# Test to see if the row exists using INSERT IGNORE
		# This avoids race conditions by locking the row until the commit, and also
		# doesn't deadlock. SELECT FOR UPDATE causes a deadlock for every race condition.
		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentFields = $commentStore->insert( $dbw, 'img_description', $comment );
		$actorMigration = ActorMigration::newMigration();
		$actorFields = $actorMigration->getInsertValues( $dbw, 'img_user', $user );
		$dbw->insert( 'image',
			[
				'img_name' => $this->getName(),
				'img_size' => $this->size,
				'img_width' => intval( $this->width ),
				'img_height' => intval( $this->height ),
				'img_bits' => $this->bits,
				'img_media_type' => $this->media_type,
				'img_major_mime' => $this->major_mime,
				'img_minor_mime' => $this->minor_mime,
				'img_timestamp' => $timestamp,
				'img_metadata' => $dbw->encodeBlob( $this->metadata ),
				'img_sha1' => $this->sha1
			] + $commentFields + $actorFields,
			__METHOD__,
			[ 'IGNORE' ]
		);
		$reupload = ( $dbw->affectedRows() == 0 );

		if ( $reupload ) {
			$row = $dbw->selectRow(
				'image',
				[ 'img_timestamp', 'img_sha1' ],
				[ 'img_name' => $this->getName() ],
				__METHOD__,
				[ 'LOCK IN SHARE MODE' ]
			);

			if ( $row && $row->img_sha1 === $this->sha1 ) {
				$dbw->endAtomic( __METHOD__ );
				wfDebug( __METHOD__ . ": File " . $this->getRel() . " already exists!\n" );
				$title = Title::newFromText( $this->getName(), NS_FILE );
				return Status::newFatal( 'fileexists-no-change', $title->getPrefixedText() );
			}

			if ( $allowTimeKludge ) {
				# Use LOCK IN SHARE MODE to ignore any transaction snapshotting
				$lUnixtime = $row ? wfTimestamp( TS_UNIX, $row->img_timestamp ) : false;
				# Avoid a timestamp that is not newer than the last version
				# TODO: the image/oldimage tables should be like page/revision with an ID field
				if ( $lUnixtime && wfTimestamp( TS_UNIX, $timestamp ) <= $lUnixtime ) {
					sleep( 1 ); // fast enough re-uploads would go far in the future otherwise
					$timestamp = $dbw->timestamp( $lUnixtime + 1 );
					$this->timestamp = wfTimestamp( TS_MW, $timestamp ); // DB -> TS_MW
				}
			}

			$tables = [ 'image' ];
			$fields = [
				'oi_name' => 'img_name',
				'oi_archive_name' => $dbw->addQuotes( $oldver ),
				'oi_size' => 'img_size',
				'oi_width' => 'img_width',
				'oi_height' => 'img_height',
				'oi_bits' => 'img_bits',
				'oi_description_id' => 'img_description_id',
				'oi_timestamp' => 'img_timestamp',
				'oi_metadata' => 'img_metadata',
				'oi_media_type' => 'img_media_type',
				'oi_major_mime' => 'img_major_mime',
				'oi_minor_mime' => 'img_minor_mime',
				'oi_sha1' => 'img_sha1',
			];
			$joins = [];

			if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) {
				$fields['oi_user'] = 'img_user';
				$fields['oi_user_text'] = 'img_user_text';
			}
			if ( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
				$fields['oi_actor'] = 'img_actor';
			}

			if (
				( $wgActorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_BOTH ) === SCHEMA_COMPAT_WRITE_BOTH
			) {
				// Upgrade any rows that are still old-style. Otherwise an upgrade
				// might be missed if a deletion happens while the migration script
				// is running.
				$res = $dbw->select(
					[ 'image' ],
					[ 'img_name', 'img_user', 'img_user_text' ],
					[ 'img_name' => $this->getName(), 'img_actor' => 0 ],
					__METHOD__
				);
				foreach ( $res as $row ) {
					$actorId = User::newFromAnyId( $row->img_user, $row->img_user_text, null )->getActorId( $dbw );
					$dbw->update(
						'image',
						[ 'img_actor' => $actorId ],
						[ 'img_name' => $row->img_name, 'img_actor' => 0 ],
						__METHOD__
					);
				}
			}

			# (T36993) Note: $oldver can be empty here, if the previous
			# version of the file was broken. Allow registration of the new
			# version to continue anyway, because that's better than having
			# an image that's not fixable by user operations.
			# Collision, this is an update of a file
			# Insert previous contents into oldimage
			$dbw->insertSelect( 'oldimage', $tables, $fields,
				[ 'img_name' => $this->getName() ], __METHOD__, [], [], $joins );

			# Update the current image row
			$dbw->update( 'image',
				[
					'img_size' => $this->size,
					'img_width' => intval( $this->width ),
					'img_height' => intval( $this->height ),
					'img_bits' => $this->bits,
					'img_media_type' => $this->media_type,
					'img_major_mime' => $this->major_mime,
					'img_minor_mime' => $this->minor_mime,
					'img_timestamp' => $timestamp,
					'img_metadata' => $dbw->encodeBlob( $this->metadata ),
					'img_sha1' => $this->sha1
				] + $commentFields + $actorFields,
				[ 'img_name' => $this->getName() ],
				__METHOD__
			);
		}

		$descTitle = $this->getTitle();
		$descId = $descTitle->getArticleID();
		$wikiPage = new WikiFilePage( $descTitle );
		$wikiPage->setFile( $this );

		// Determine log action. If reupload is done by reverting, use a special log_action.
		if ( $revert === true ) {
			$logAction = 'revert';
		} elseif ( $reupload === true ) {
			$logAction = 'overwrite';
		} else {
			$logAction = 'upload';
		}
		// Add the log entry...
		$logEntry = new ManualLogEntry( 'upload', $logAction );
		$logEntry->setTimestamp( $this->timestamp );
		$logEntry->setPerformer( $user );
		$logEntry->setComment( $comment );
		$logEntry->setTarget( $descTitle );
		// Allow people using the api to associate log entries with the upload.
		// Log has a timestamp, but sometimes different from upload timestamp.
		$logEntry->setParameters(
			[
				'img_sha1' => $this->sha1,
				'img_timestamp' => $timestamp,
			]
		);
		// Note we keep $logId around since during new image
		// creation, page doesn't exist yet, so log_page = 0
		// but we want it to point to the page we're making,
		// so we later modify the log entry.
		// For a similar reason, we avoid making an RC entry
		// now and wait until the page exists.
		$logId = $logEntry->insert();

		if ( $descTitle->exists() ) {
			// Use own context to get the action text in content language
			$formatter = LogFormatter::newFromEntry( $logEntry );
			$formatter->setContext( RequestContext::newExtraneousContext( $descTitle ) );
			$editSummary = $formatter->getPlainActionText();

			$nullRevision = $createNullRevision === false ? null : Revision::newNullRevision(
				$dbw,
				$descId,
				$editSummary,
				false,
				$user
			);
			if ( $nullRevision ) {
				$nullRevision->insertOn( $dbw );
				Hooks::run(
					'NewRevisionFromEditComplete',
					[ $wikiPage, $nullRevision, $nullRevision->getParentId(), $user ]
				);
				$wikiPage->updateRevisionOn( $dbw, $nullRevision );
				// Associate null revision id
				$logEntry->setAssociatedRevId( $nullRevision->getId() );
			}

			$newPageContent = null;
		} else {
			// Make the description page and RC log entry post-commit
			$newPageContent = ContentHandler::makeContent( $pageText, $descTitle );
		}

		# Defer purges, page creation, and link updates in case they error out.
		# The most important thing is that files and the DB registry stay synced.
		$dbw->endAtomic( __METHOD__ );
		$fname = __METHOD__;

		# Do some cache purges after final commit so that:
		# a) Changes are more likely to be seen post-purge
		# b) They won't cause rollback of the log publish/update above
		DeferredUpdates::addUpdate(
			new AutoCommitUpdate(
				$dbw,
				__METHOD__,
				function () use (
					$reupload, $wikiPage, $newPageContent, $comment, $user,
					$logEntry, $logId, $descId, $tags, $fname
				) {
					# Update memcache after the commit
					$this->invalidateCache();

					$updateLogPage = false;
					if ( $newPageContent ) {
						# New file page; create the description page.
						# There's already a log entry, so don't make a second RC entry
						# CDN and file cache for the description page are purged by doEditContent.
						$status = $wikiPage->doEditContent(
							$newPageContent,
							$comment,
							EDIT_NEW | EDIT_SUPPRESS_RC,
							false,
							$user
						);

						if ( isset( $status->value['revision'] ) ) {
							/** @var Revision $rev */
							$rev = $status->value['revision'];
							// Associate new page revision id
							$logEntry->setAssociatedRevId( $rev->getId() );
						}
						// This relies on the resetArticleID() call in WikiPage::insertOn(),
						// which is triggered on $descTitle by doEditContent() above.
						if ( isset( $status->value['revision'] ) ) {
							/** @var Revision $rev */
							$rev = $status->value['revision'];
							$updateLogPage = $rev->getPage();
						}
					} else {
						# Existing file page: invalidate description page cache
						$wikiPage->getTitle()->invalidateCache();
						$wikiPage->getTitle()->purgeSquid();
						# Allow the new file version to be patrolled from the page footer
						Article::purgePatrolFooterCache( $descId );
					}

					# Update associated rev id. This should be done by $logEntry->insert() earlier,
					# but setAssociatedRevId() wasn't called at that point yet...
					$logParams = $logEntry->getParameters();
					$logParams['associated_rev_id'] = $logEntry->getAssociatedRevId();
					$update = [ 'log_params' => LogEntryBase::makeParamBlob( $logParams ) ];
					if ( $updateLogPage ) {
						# Also log page, in case where we just created it above
						$update['log_page'] = $updateLogPage;
					}
					$this->getRepo()->getMasterDB()->update(
						'logging',
						$update,
						[ 'log_id' => $logId ],
						$fname
					);
					$this->getRepo()->getMasterDB()->insert(
						'log_search',
						[
							'ls_field' => 'associated_rev_id',
							'ls_value' => $logEntry->getAssociatedRevId(),
							'ls_log_id' => $logId,
						],
						$fname
					);

					# Add change tags, if any
					if ( $tags ) {
						$logEntry->setTags( $tags );
					}

					# Uploads can be patrolled
					$logEntry->setIsPatrollable( true );

					# Now that the log entry is up-to-date, make an RC entry.
					$logEntry->publish( $logId );

					# Run hook for other updates (typically more cache purging)
					Hooks::run( 'FileUpload', [ $this, $reupload, !$newPageContent ] );

					if ( $reupload ) {
						# Delete old thumbnails
						$this->purgeThumbnails();
						# Remove the old file from the CDN cache
						DeferredUpdates::addUpdate(
							new CdnCacheUpdate( [ $this->getUrl() ] ),
							DeferredUpdates::PRESEND
						);
					} else {
						# Update backlink pages pointing to this title if created
						LinksUpdate::queueRecursiveJobsForTable(
							$this->getTitle(),
							'imagelinks',
							'upload-image',
							$user->getName()
						);
					}

					$this->prerenderThumbnails();
				}
			),
			DeferredUpdates::PRESEND
		);

		if ( !$reupload ) {
			# This is a new file, so update the image count
			DeferredUpdates::addUpdate( SiteStatsUpdate::factory( [ 'images' => 1 ] ) );
		}

		# Invalidate cache for all pages using this file
		DeferredUpdates::addUpdate(
			new HTMLCacheUpdate( $this->getTitle(), 'imagelinks', 'file-upload' )
		);

		return Status::newGood();
	}

	/**
	 * Move or copy a file to its public location. If a file exists at the
	 * destination, move it to an archive. Returns a Status object with
	 * the archive name in the "value" member on success.
	 *
	 * The archive name should be passed through to recordUpload for database
	 * registration.
	 *
	 * @param string|FSFile $src Local filesystem path or virtual URL to the source image
	 * @param int $flags A bitwise combination of:
	 *     File::DELETE_SOURCE    Delete the source file, i.e. move rather than copy
	 * @param array $options Optional additional parameters
	 * @return Status On success, the value member contains the
	 *     archive name, or an empty string if it was a new file.
	 */
	function publish( $src, $flags = 0, array $options = [] ) {
		return $this->publishTo( $src, $this->getRel(), $flags, $options );
	}

	/**
	 * Move or copy a file to a specified location. Returns a Status
	 * object with the archive name in the "value" member on success.
	 *
	 * The archive name should be passed through to recordUpload for database
	 * registration.
	 *
	 * @param string|FSFile $src Local filesystem path or virtual URL to the source image
	 * @param string $dstRel Target relative path
	 * @param int $flags A bitwise combination of:
	 *     File::DELETE_SOURCE    Delete the source file, i.e. move rather than copy
	 * @param array $options Optional additional parameters
	 * @return Status On success, the value member contains the
	 *     archive name, or an empty string if it was a new file.
	 */
	function publishTo( $src, $dstRel, $flags = 0, array $options = [] ) {
		$srcPath = ( $src instanceof FSFile ) ? $src->getPath() : $src;

		$repo = $this->getRepo();
		if ( $repo->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		}

		$this->lock();

		if ( $this->isOld() ) {
			$archiveRel = $dstRel;
			$archiveName = basename( $archiveRel );
		} else {
			$archiveName = wfTimestamp( TS_MW ) . '!' . $this->getName();
			$archiveRel = $this->getArchiveRel( $archiveName );
		}

		if ( $repo->hasSha1Storage() ) {
			$sha1 = FileRepo::isVirtualUrl( $srcPath )
				? $repo->getFileSha1( $srcPath )
				: FSFile::getSha1Base36FromPath( $srcPath );
			/** @var FileBackendDBRepoWrapper $wrapperBackend */
			$wrapperBackend = $repo->getBackend();
			$dst = $wrapperBackend->getPathForSHA1( $sha1 );
			$status = $repo->quickImport( $src, $dst );
			if ( $flags & File::DELETE_SOURCE ) {
				unlink( $srcPath );
			}

			if ( $this->exists() ) {
				$status->value = $archiveName;
			}
		} else {
			$flags = $flags & File::DELETE_SOURCE ? LocalRepo::DELETE_SOURCE : 0;
			$status = $repo->publish( $srcPath, $dstRel, $archiveRel, $flags, $options );

			if ( $status->value == 'new' ) {
				$status->value = '';
			} else {
				$status->value = $archiveName;
			}
		}

		$this->unlock();
		return $status;
	}

	/** getLinksTo inherited */
	/** getExifData inherited */
	/** isLocal inherited */
	/** wasDeleted inherited */

	/**
	 * Move file to the new title
	 *
	 * Move current, old version and all thumbnails
	 * to the new filename. Old file is deleted.
	 *
	 * Cache purging is done; checks for validity
	 * and logging are caller's responsibility
	 *
	 * @param Title $target New file name
	 * @return Status
	 */
	function move( $target ) {
		$localRepo = MediaWikiServices::getInstance()->getRepoGroup();
		if ( $this->getRepo()->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		}

		wfDebugLog( 'imagemove', "Got request to move {$this->name} to " . $target->getText() );
		$batch = new LocalFileMoveBatch( $this, $target );

		$this->lock();
		$batch->addCurrent();
		$archiveNames = $batch->addOlds();
		$status = $batch->execute();
		$this->unlock();

		wfDebugLog( 'imagemove', "Finished moving {$this->name}" );

		// Purge the source and target files...
		$oldTitleFile = $localRepo->findFile( $this->title );
		$newTitleFile = $localRepo->findFile( $target );
		// To avoid slow purges in the transaction, move them outside...
		DeferredUpdates::addUpdate(
			new AutoCommitUpdate(
				$this->getRepo()->getMasterDB(),
				__METHOD__,
				function () use ( $oldTitleFile, $newTitleFile, $archiveNames ) {
					$oldTitleFile->purgeEverything();
					foreach ( $archiveNames as $archiveName ) {
						$oldTitleFile->purgeOldThumbnails( $archiveName );
					}
					$newTitleFile->purgeEverything();
				}
			),
			DeferredUpdates::PRESEND
		);

		if ( $status->isOK() ) {
			// Now switch the object
			$this->title = $target;
			// Force regeneration of the name and hashpath
			unset( $this->name );
			unset( $this->hashPath );
		}

		return $status;
	}

	/**
	 * Delete all versions of the file.
	 *
	 * Moves the files into an archive directory (or deletes them)
	 * and removes the database rows.
	 *
	 * Cache purging is done; logging is caller's responsibility.
	 *
	 * @param string $reason
	 * @param bool $suppress
	 * @param User|null $user
	 * @return Status
	 */
	function delete( $reason, $suppress = false, $user = null ) {
		if ( $this->getRepo()->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		}

		$batch = new LocalFileDeleteBatch( $this, $reason, $suppress, $user );

		$this->lock();
		$batch->addCurrent();
		// Get old version relative paths
		$archiveNames = $batch->addOlds();
		$status = $batch->execute();
		$this->unlock();

		if ( $status->isOK() ) {
			DeferredUpdates::addUpdate( SiteStatsUpdate::factory( [ 'images' => -1 ] ) );
		}

		// To avoid slow purges in the transaction, move them outside...
		DeferredUpdates::addUpdate(
			new AutoCommitUpdate(
				$this->getRepo()->getMasterDB(),
				__METHOD__,
				function () use ( $archiveNames ) {
					$this->purgeEverything();
					foreach ( $archiveNames as $archiveName ) {
						$this->purgeOldThumbnails( $archiveName );
					}
				}
			),
			DeferredUpdates::PRESEND
		);

		// Purge the CDN
		$purgeUrls = [];
		foreach ( $archiveNames as $archiveName ) {
			$purgeUrls[] = $this->getArchiveUrl( $archiveName );
		}
		DeferredUpdates::addUpdate( new CdnCacheUpdate( $purgeUrls ), DeferredUpdates::PRESEND );

		return $status;
	}

	/**
	 * Delete an old version of the file.
	 *
	 * Moves the file into an archive directory (or deletes it)
	 * and removes the database row.
	 *
	 * Cache purging is done; logging is caller's responsibility.
	 *
	 * @param string $archiveName
	 * @param string $reason
	 * @param bool $suppress
	 * @param User|null $user
	 * @throws MWException Exception on database or file store failure
	 * @return Status
	 */
	function deleteOld( $archiveName, $reason, $suppress = false, $user = null ) {
		if ( $this->getRepo()->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		}

		$batch = new LocalFileDeleteBatch( $this, $reason, $suppress, $user );

		$this->lock();
		$batch->addOld( $archiveName );
		$status = $batch->execute();
		$this->unlock();

		$this->purgeOldThumbnails( $archiveName );
		if ( $status->isOK() ) {
			$this->purgeDescription();
		}

		DeferredUpdates::addUpdate(
			new CdnCacheUpdate( [ $this->getArchiveUrl( $archiveName ) ] ),
			DeferredUpdates::PRESEND
		);

		return $status;
	}

	/**
	 * Restore all or specified deleted revisions to the given file.
	 * Permissions and logging are left to the caller.
	 *
	 * May throw database exceptions on error.
	 *
	 * @param array $versions Set of record ids of deleted items to restore,
	 *   or empty to restore all revisions.
	 * @param bool $unsuppress
	 * @return Status
	 */
	function restore( $versions = [], $unsuppress = false ) {
		if ( $this->getRepo()->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		}

		$batch = new LocalFileRestoreBatch( $this, $unsuppress );

		$this->lock();
		if ( !$versions ) {
			$batch->addAll();
		} else {
			$batch->addIds( $versions );
		}
		$status = $batch->execute();
		if ( $status->isGood() ) {
			$cleanupStatus = $batch->cleanup();
			$cleanupStatus->successCount = 0;
			$cleanupStatus->failCount = 0;
			$status->merge( $cleanupStatus );
		}

		$this->unlock();
		return $status;
	}

	/** isMultipage inherited */
	/** pageCount inherited */
	/** scaleHeight inherited */
	/** getImageSize inherited */

	/**
	 * Get the URL of the file description page.
	 * @return string
	 */
	function getDescriptionUrl() {
		return $this->title->getLocalURL();
	}

	/**
	 * Get the HTML text of the description page
	 * This is not used by ImagePage for local files, since (among other things)
	 * it skips the parser cache.
	 *
	 * @param Language|null $lang What language to get description in (Optional)
	 * @return string|false
	 */
	function getDescriptionText( Language $lang = null ) {
		$store = MediaWikiServices::getInstance()->getRevisionStore();
		$revision = $store->getRevisionByTitle( $this->title, 0, Revision::READ_NORMAL );
		if ( !$revision ) {
			return false;
		}

		$renderer = MediaWikiServices::getInstance()->getRevisionRenderer();
		$rendered = $renderer->getRenderedRevision( $revision, new ParserOptions( null, $lang ) );

		if ( !$rendered ) {
			// audience check failed
			return false;
		}

		$pout = $rendered->getRevisionParserOutput();
		return $pout->getText();
	}

	/**
	 * @param int $audience
	 * @param User|null $user
	 * @return string
	 */
	function getDescription( $audience = self::FOR_PUBLIC, User $user = null ) {
		$this->load();
		if ( $audience == self::FOR_PUBLIC && $this->isDeleted( self::DELETED_COMMENT ) ) {
			return '';
		} elseif ( $audience == self::FOR_THIS_USER
			&& !$this->userCan( self::DELETED_COMMENT, $user )
		) {
			return '';
		} else {
			return $this->description;
		}
	}

	/**
	 * @return bool|string
	 */
	function getTimestamp() {
		$this->load();

		return $this->timestamp;
	}

	/**
	 * @return bool|string
	 */
	public function getDescriptionTouched() {
		// The DB lookup might return false, e.g. if the file was just deleted, or the shared DB repo
		// itself gets it from elsewhere. To avoid repeating the DB lookups in such a case, we
		// need to differentiate between null (uninitialized) and false (failed to load).
		if ( $this->descriptionTouched === null ) {
			$cond = [
				'page_namespace' => $this->title->getNamespace(),
				'page_title' => $this->title->getDBkey()
			];
			$touched = $this->repo->getReplicaDB()->selectField( 'page', 'page_touched', $cond, __METHOD__ );
			$this->descriptionTouched = $touched ? wfTimestamp( TS_MW, $touched ) : false;
		}

		return $this->descriptionTouched;
	}

	/**
	 * @return string
	 */
	function getSha1() {
		$this->load();
		// Initialise now if necessary
		if ( $this->sha1 == '' && $this->fileExists ) {
			$this->lock();

			$this->sha1 = $this->repo->getFileSha1( $this->getPath() );
			if ( !wfReadOnly() && strval( $this->sha1 ) != '' ) {
				$dbw = $this->repo->getMasterDB();
				$dbw->update( 'image',
					[ 'img_sha1' => $this->sha1 ],
					[ 'img_name' => $this->getName() ],
					__METHOD__ );
				$this->invalidateCache();
			}

			$this->unlock();
		}

		return $this->sha1;
	}

	/**
	 * @return bool Whether to cache in RepoGroup (this avoids OOMs)
	 */
	function isCacheable() {
		$this->load();

		// If extra data (metadata) was not loaded then it must have been large
		return $this->extraDataLoaded
			&& strlen( serialize( $this->metadata ) ) <= self::CACHE_FIELD_MAX_LEN;
	}

	/**
	 * @return Status
	 * @since 1.28
	 */
	public function acquireFileLock() {
		return Status::wrap( $this->getRepo()->getBackend()->lockFiles(
			[ $this->getPath() ], LockManager::LOCK_EX, 10
		) );
	}

	/**
	 * @return Status
	 * @since 1.28
	 */
	public function releaseFileLock() {
		return Status::wrap( $this->getRepo()->getBackend()->unlockFiles(
			[ $this->getPath() ], LockManager::LOCK_EX
		) );
	}

	/**
	 * Start an atomic DB section and lock the image for update
	 * or increments a reference counter if the lock is already held
	 *
	 * This method should not be used outside of LocalFile/LocalFile*Batch
	 *
	 * @throws LocalFileLockError Throws an error if the lock was not acquired
	 * @return bool Whether the file lock owns/spawned the DB transaction
	 */
	public function lock() {
		if ( !$this->locked ) {
			$logger = LoggerFactory::getInstance( 'LocalFile' );

			$dbw = $this->repo->getMasterDB();
			$makesTransaction = !$dbw->trxLevel();
			$dbw->startAtomic( self::ATOMIC_SECTION_LOCK );
			// T56736: use simple lock to handle when the file does not exist.
			// SELECT FOR UPDATE prevents changes, not other SELECTs with FOR UPDATE.
			// Also, that would cause contention on INSERT of similarly named rows.
			$status = $this->acquireFileLock(); // represents all versions of the file
			if ( !$status->isGood() ) {
				$dbw->endAtomic( self::ATOMIC_SECTION_LOCK );
				$logger->warning( "Failed to lock '{file}'", [ 'file' => $this->name ] );

				throw new LocalFileLockError( $status );
			}
			// Release the lock *after* commit to avoid row-level contention.
			// Make sure it triggers on rollback() as well as commit() (T132921).
			$dbw->onTransactionResolution(
				function () use ( $logger ) {
					$status = $this->releaseFileLock();
					if ( !$status->isGood() ) {
						$logger->error( "Failed to unlock '{file}'", [ 'file' => $this->name ] );
					}
				},
				__METHOD__
			);
			// Callers might care if the SELECT snapshot is safely fresh
			$this->lockedOwnTrx = $makesTransaction;
		}

		$this->locked++;

		return $this->lockedOwnTrx;
	}

	/**
	 * Decrement the lock reference count and end the atomic section if it reaches zero
	 *
	 * This method should not be used outside of LocalFile/LocalFile*Batch
	 *
	 * The commit and loc release will happen when no atomic sections are active, which
	 * may happen immediately or at some point after calling this
	 */
	public function unlock() {
		if ( $this->locked ) {
			--$this->locked;
			if ( !$this->locked ) {
				$dbw = $this->repo->getMasterDB();
				$dbw->endAtomic( self::ATOMIC_SECTION_LOCK );
				$this->lockedOwnTrx = false;
			}
		}
	}

	/**
	 * @return Status
	 */
	protected function readOnlyFatalStatus() {
		return $this->getRepo()->newFatal( 'filereadonlyerror', $this->getName(),
			$this->getRepo()->getName(), $this->getRepo()->getReadOnlyReason() );
	}

	/**
	 * Clean up any dangling locks
	 */
	function __destruct() {
		$this->unlock();
	}
}
