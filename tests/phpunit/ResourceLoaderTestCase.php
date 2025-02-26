<?php

use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

abstract class ResourceLoaderTestCase extends MediaWikiTestCase {
	// Version hash for a blank file module.
	// Result of ResourceLoader::makeHash(), ResourceLoaderTestModule
	// and ResourceLoaderFileModule::getDefinitionSummary().
	const BLANK_VERSION = '09p30q0';

	/**
	 * @param array|string $options Language code or options array
	 * - string 'lang' Language code
	 * - string 'dir' Language direction (ltr or rtl)
	 * - string 'modules' Pipe-separated list of module names
	 * - string|null 'only' "scripts" (unwrapped script), "styles" (stylesheet), or null
	 *    (mw.loader.implement).
	 * @param ResourceLoader|null $rl
	 * @return ResourceLoaderContext
	 */
	protected function getResourceLoaderContext( $options = [], ResourceLoader $rl = null ) {
		if ( is_string( $options ) ) {
			// Back-compat for extension tests
			$options = [ 'lang' => $options ];
		}
		$options += [
			'debug' => 'true',
			'lang' => 'en',
			'dir' => 'ltr',
			'skin' => 'fallback',
			'modules' => 'startup',
			'only' => 'scripts',
			'safemode' => null,
		];
		$resourceLoader = $rl ?: new ResourceLoader( MediaWikiServices::getInstance()->getMainConfig() );
		$request = new FauxRequest( [
				'debug' => $options['debug'],
				'lang' => $options['lang'],
				'modules' => $options['modules'],
				'only' => $options['only'],
				'safemode' => $options['safemode'],
				'skin' => $options['skin'],
				'target' => 'phpunit',
		] );
		$ctx = $this->getMockBuilder( ResourceLoaderContext::class )
			->setConstructorArgs( [ $resourceLoader, $request ] )
			->setMethods( [ 'getDirection' ] )
			->getMock();
		$ctx->method( 'getDirection' )->willReturn( $options['dir'] );
		return $ctx;
	}

	public static function getSettings() {
		return [
			// For ResourceLoader::inDebugMode since it doesn't have context
			'ResourceLoaderDebug' => true,

			// For ResourceLoaderStartUpModule and ResourceLoader::__construct()
			'ScriptPath' => '/w',
			'Script' => '/w/index.php',
			'LoadScript' => '/w/load.php',

			// For ResourceLoader::register() - TODO: Inject somehow T32956
			'ResourceModuleSkinStyles' => [],

			// For ResourceLoader::respond() - TODO: Inject somehow T32956
			'UseFileCache' => false,
		];
	}

	public static function getMinimalConfig() {
		return new HashConfig( self::getSettings() );
	}

	protected function setUp() {
		parent::setUp();

		ResourceLoader::clearCache();

		$globals = [];
		foreach ( self::getSettings() as $key => $value ) {
			$globals['wg' . $key] = $value;
		}
		$this->setMwGlobals( $globals );
	}
}

/* Stubs */

class ResourceLoaderTestModule extends ResourceLoaderModule {
	protected $messages = [];
	protected $dependencies = [];
	protected $group = null;
	protected $source = 'local';
	protected $script = '';
	protected $styles = '';
	protected $skipFunction = null;
	protected $isRaw = false;
	protected $isKnownEmpty = false;
	protected $type = ResourceLoaderModule::LOAD_GENERAL;
	protected $targets = [ 'phpunit' ];
	protected $shouldEmbed = null;

	public function __construct( $options = [] ) {
		foreach ( $options as $key => $value ) {
			$this->$key = $value;
		}
	}

	public function getScript( ResourceLoaderContext $context ) {
		return $this->validateScriptFile( 'input', $this->script );
	}

	public function getStyles( ResourceLoaderContext $context ) {
		return [ '' => $this->styles ];
	}

	public function getMessages() {
		return $this->messages;
	}

	public function getDependencies( ResourceLoaderContext $context = null ) {
		return $this->dependencies;
	}

	public function getGroup() {
		return $this->group;
	}

	public function getSource() {
		return $this->source;
	}

	public function getType() {
		return $this->type;
	}

	public function getSkipFunction() {
		return $this->skipFunction;
	}

	public function isRaw() {
		return $this->isRaw;
	}

	public function isKnownEmpty( ResourceLoaderContext $context ) {
		return $this->isKnownEmpty;
	}

	public function shouldEmbedModule( ResourceLoaderContext $context ) {
		return $this->shouldEmbed ?? parent::shouldEmbedModule( $context );
	}

	public function enableModuleContentVersion() {
		return true;
	}
}

class ResourceLoaderFileTestModule extends ResourceLoaderFileModule {
	protected $lessVars = [];

	public function __construct( $options = [] ) {
		if ( isset( $options['lessVars'] ) ) {
			$this->lessVars = $options['lessVars'];
			unset( $options['lessVars'] );
		}

		parent::__construct( $options );
	}

	public function getLessVars( ResourceLoaderContext $context ) {
		return $this->lessVars;
	}
}

class ResourceLoaderFileModuleTestModule extends ResourceLoaderFileModule {
}

class EmptyResourceLoader extends ResourceLoader {
	public function __construct( Config $config = null, LoggerInterface $logger = null ) {
		parent::__construct( $config ?: ResourceLoaderTestCase::getMinimalConfig(), $logger );
	}

	public function getErrors() {
		return $this->errors;
	}
}
