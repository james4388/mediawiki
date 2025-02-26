<?php

namespace MediaWiki\Rest;

use AppendIterator;
use BagOStuff;
use MediaWiki\Rest\PathTemplateMatcher\PathMatcher;
use Wikimedia\ObjectFactory;

/**
 * The REST router is responsible for gathering handler configuration, matching
 * an input path and HTTP method against the defined routes, and constructing
 * and executing the relevant handler for a request.
 */
class Router {
	/** @var string[] */
	private $routeFiles;

	/** @var array */
	private $extraRoutes;

	/** @var array|null */
	private $routesFromFiles;

	/** @var int[]|null */
	private $routeFileTimestamps;

	/** @var string */
	private $rootPath;

	/** @var \BagOStuff */
	private $cacheBag;

	/** @var PathMatcher[]|null Path matchers by method */
	private $matchers;

	/** @var string|null */
	private $configHash;

	/** @var ResponseFactory */
	private $responseFactory;

	/**
	 * @param string[] $routeFiles List of names of JSON files containing routes
	 * @param array $extraRoutes Extension route array
	 * @param string $rootPath The base URL path
	 * @param BagOStuff $cacheBag A cache in which to store the matcher trees
	 * @param ResponseFactory $responseFactory
	 */
	public function __construct( $routeFiles, $extraRoutes, $rootPath,
		BagOStuff $cacheBag, ResponseFactory $responseFactory
	) {
		$this->routeFiles = $routeFiles;
		$this->extraRoutes = $extraRoutes;
		$this->rootPath = $rootPath;
		$this->cacheBag = $cacheBag;
		$this->responseFactory = $responseFactory;
	}

	/**
	 * Get the cache data, or false if it is missing or invalid
	 *
	 * @return bool|array
	 */
	private function fetchCacheData() {
		$cacheData = $this->cacheBag->get( $this->getCacheKey() );
		if ( $cacheData && $cacheData['CONFIG-HASH'] === $this->getConfigHash() ) {
			unset( $cacheData['CONFIG-HASH'] );
			return $cacheData;
		} else {
			return false;
		}
	}

	/**
	 * @return string The cache key
	 */
	private function getCacheKey() {
		return $this->cacheBag->makeKey( __CLASS__, '1' );
	}

	/**
	 * Get a config version hash for cache invalidation
	 *
	 * @return string
	 */
	private function getConfigHash() {
		if ( $this->configHash === null ) {
			$this->configHash = md5( json_encode( [
				$this->extraRoutes,
				$this->getRouteFileTimestamps()
			] ) );
		}
		return $this->configHash;
	}

	/**
	 * Load the defined JSON files and return the merged routes
	 *
	 * @return array
	 */
	private function getRoutesFromFiles() {
		if ( $this->routesFromFiles === null ) {
			$this->routeFileTimestamps = [];
			foreach ( $this->routeFiles as $fileName ) {
				$this->routeFileTimestamps[$fileName] = filemtime( $fileName );
				$routes = json_decode( file_get_contents( $fileName ), true );
				if ( $this->routesFromFiles === null ) {
					$this->routesFromFiles = $routes;
				} else {
					$this->routesFromFiles = array_merge( $this->routesFromFiles, $routes );
				}
			}
		}
		return $this->routesFromFiles;
	}

	/**
	 * Get an array of last modification times of the defined route files.
	 *
	 * @return int[] Last modification times
	 */
	private function getRouteFileTimestamps() {
		if ( $this->routeFileTimestamps === null ) {
			$this->routeFileTimestamps = [];
			foreach ( $this->routeFiles as $fileName ) {
				$this->routeFileTimestamps[$fileName] = filemtime( $fileName );
			}
		}
		return $this->routeFileTimestamps;
	}

	/**
	 * Get an iterator for all defined routes, including loading the routes from
	 * the JSON files.
	 *
	 * @return AppendIterator
	 */
	private function getAllRoutes() {
		$iterator = new AppendIterator;
		$iterator->append( new \ArrayIterator( $this->getRoutesFromFiles() ) );
		$iterator->append( new \ArrayIterator( $this->extraRoutes ) );
		return $iterator;
	}

	/**
	 * Get an array of PathMatcher objects indexed by HTTP method
	 *
	 * @return PathMatcher[]
	 */
	private function getMatchers() {
		if ( $this->matchers === null ) {
			$cacheData = $this->fetchCacheData();
			$matchers = [];
			if ( $cacheData ) {
				foreach ( $cacheData as $method => $data ) {
					$matchers[$method] = PathMatcher::newFromCache( $data );
				}
			} else {
				foreach ( $this->getAllRoutes() as $spec ) {
					$methods = $spec['method'] ?? [ 'GET' ];
					if ( !is_array( $methods ) ) {
						$methods = [ $methods ];
					}
					foreach ( $methods as $method ) {
						if ( !isset( $matchers[$method] ) ) {
							$matchers[$method] = new PathMatcher;
						}
						$matchers[$method]->add( $spec['path'], $spec );
					}
				}

				$cacheData = [ 'CONFIG-HASH' => $this->getConfigHash() ];
				foreach ( $matchers as $method => $matcher ) {
					$cacheData[$method] = $matcher->getCacheData();
				}
				$this->cacheBag->set( $this->getCacheKey(), $cacheData );
			}
			$this->matchers = $matchers;
		}
		return $this->matchers;
	}

	/**
	 * Remove the path prefix $this->rootPath. Return the part of the path with the
	 * prefix removed, or false if the prefix did not match.
	 *
	 * @param string $path
	 * @return false|string
	 */
	private function getRelativePath( $path ) {
		if ( substr_compare( $path, $this->rootPath, 0, strlen( $this->rootPath ) ) !== 0 ) {
			return false;
		}
		return substr( $path, strlen( $this->rootPath ) );
	}

	/**
	 * Find the handler for a request and execute it
	 *
	 * @param RequestInterface $request
	 * @return ResponseInterface
	 */
	public function execute( RequestInterface $request ) {
		$path = $request->getUri()->getPath();
		$relPath = $this->getRelativePath( $path );
		if ( $relPath === false ) {
			return $this->responseFactory->createHttpError( 404 );
		}

		$matchers = $this->getMatchers();
		$matcher = $matchers[$request->getMethod()] ?? null;
		$match = $matcher ? $matcher->match( $relPath ) : null;

		if ( !$match ) {
			// Check for 405 wrong method
			$allowed = [];
			foreach ( $matchers as $allowedMethod => $allowedMatcher ) {
				if ( $allowedMethod === $request->getMethod() ) {
					continue;
				}
				if ( $allowedMatcher->match( $relPath ) ) {
					$allowed[] = $allowedMethod;
				}
			}
			if ( $allowed ) {
				$response = $this->responseFactory->createHttpError( 405 );
				$response->setHeader( 'Allow', $allowed );
				return $response;
			} else {
				// Did not match with any other method, must be 404
				return $this->responseFactory->createHttpError( 404 );
			}
		}

		$request->setPathParams( $match['params'] );
		$spec = $match['userData'];
		$objectFactorySpec = array_intersect_key( $spec,
			[ 'factory' => true, 'class' => true, 'args' => true ] );
		$handler = ObjectFactory::getObjectFromSpec( $objectFactorySpec );
		$handler->init( $request, $spec, $this->responseFactory );

		try {
			return $this->executeHandler( $handler );
		} catch ( HttpException $e ) {
			return $this->responseFactory->createFromException( $e );
		}
	}

	/**
	 * Execute a fully-constructed handler
	 * @param Handler $handler
	 * @return ResponseInterface
	 */
	private function executeHandler( $handler ): ResponseInterface {
		$response = $handler->execute();
		if ( !( $response instanceof ResponseInterface ) ) {
			$response = $this->responseFactory->createFromReturnValue( $response );
		}
		return $response;
	}
}
