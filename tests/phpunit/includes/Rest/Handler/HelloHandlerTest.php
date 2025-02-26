<?php

namespace MediaWiki\Tests\Rest\Handler;

use EmptyBagOStuff;
use GuzzleHttp\Psr7\Uri;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseFactory;
use MediaWiki\Rest\Router;
use MediaWikiTestCase;

/**
 * @covers \MediaWiki\Rest\Handler\HelloHandler
 */
class HelloHandlerTest extends MediaWikiTestCase {
	public static function provideTestViaRouter() {
		return [
			'normal' => [
				[
					'method' => 'GET',
					'uri' => self::makeUri( '/user/Tim/hello' ),
				],
				[
					'statusCode' => 200,
					'reasonPhrase' => 'OK',
					'protocolVersion' => '1.1',
					'body' => '{"message":"Hello, Tim!"}',
				],
			],
			'method not allowed' => [
				[
					'method' => 'POST',
					'uri' => self::makeUri( '/user/Tim/hello' ),
				],
				[
					'statusCode' => 405,
					'reasonPhrase' => 'Method Not Allowed',
					'protocolVersion' => '1.1',
					'body' => '{"httpCode":405,"httpReason":"Method Not Allowed"}',
				],
			],
		];
	}

	private static function makeUri( $path ) {
		return new Uri( "http://www.example.com/rest$path" );
	}

	/** @dataProvider provideTestViaRouter */
	public function testViaRouter( $requestInfo, $responseInfo ) {
		$router = new Router(
			[ __DIR__ . '/../testRoutes.json' ],
			[],
			'/rest',
			new EmptyBagOStuff(),
			new ResponseFactory() );
		$request = new RequestData( $requestInfo );
		$response = $router->execute( $request );
		if ( isset( $responseInfo['statusCode'] ) ) {
			$this->assertSame( $responseInfo['statusCode'], $response->getStatusCode() );
		}
		if ( isset( $responseInfo['reasonPhrase'] ) ) {
			$this->assertSame( $responseInfo['reasonPhrase'], $response->getReasonPhrase() );
		}
		if ( isset( $responseInfo['protocolVersion'] ) ) {
			$this->assertSame( $responseInfo['protocolVersion'], $response->getProtocolVersion() );
		}
		if ( isset( $responseInfo['body'] ) ) {
			$this->assertSame( $responseInfo['body'], $response->getBody()->getContents() );
		}
		$this->assertSame(
			[],
			array_diff( array_keys( $responseInfo ), [
				'statusCode',
				'reasonPhrase',
				'protocolVersion',
				'body'
			] ),
			'$responseInfo may not contain unknown keys' );
	}
}
