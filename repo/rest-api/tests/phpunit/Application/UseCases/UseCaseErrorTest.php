<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCases;

use Generator;
use LogicException;
use PHPUnit\Framework\TestCase;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCases\UseCaseError
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class UseCaseErrorTest extends TestCase {

	/**
	 * @dataProvider provideValidUseCaseErrorData
	 */
	public function testHappyPath( string $errorCode, string $errorMessage, array $errorContext = [] ): void {
		$useCaseError = new UseCaseError( $errorCode, $errorMessage, $errorContext );

		$this->assertSame( $errorCode, $useCaseError->getErrorCode() );
		$this->assertSame( $errorMessage, $useCaseError->getErrorMessage() );
		$this->assertSame( $errorContext, $useCaseError->getErrorContext() );
	}

	public function provideValidUseCaseErrorData(): Generator {
		yield 'valid error without context' => [
			UseCaseError::RESOURCE_NOT_FOUND,
			'The requested resource does not exist',
			[ UseCaseError::CONTEXT_RESOURCE_TYPE => 'aliases' ],
		];

		yield 'valid error with context' => [
			UseCaseError::INVALID_PATH_PARAMETER,
			"Invalid path parameter: 'property_id'",
			[ UseCaseError::CONTEXT_PARAMETER => 'property_id' ],
		];

		yield 'valid error with additional path context' => [
			UseCaseError::SITELINK_TITLE_NOT_FOUND,
			'Page with title Test_article does not exist on the given site',
			[ UseCaseError::CONTEXT_SITE_ID => 'enwiki' ],
		];

		yield 'valid error without additional path context' => [
			UseCaseError::SITELINK_TITLE_NOT_FOUND,
			'Page with title Test_article does not exist on the given site',
		];
	}

	/**
	 * @dataProvider provideInvalidUseCaseErrorData
	 */
	public function testInvalidInstantiation( string $errorCode, string $errorMessage, array $errorContext = [] ): void {
		$this->expectException( LogicException::class );
		new UseCaseError( $errorCode, $errorMessage, $errorContext );
	}

	public function provideInvalidUseCaseErrorData(): Generator {
		yield 'error code not defined' => [ 'not-a-valid-error-code', 'not a valid error code' ];

		yield 'error context contains incorrect key' => [
			UseCaseError::INVALID_PATH_PARAMETER,
			'incorrect context key',
			[ 'incorrect-context-key' => 'potato', UseCaseError::INVALID_PATH_PARAMETER => 'property_id' ],
		];

		yield 'error context is missing expected keys' => [
			UseCaseError::INVALID_PATH_PARAMETER,
			'error context key is missing',
		];

		yield 'wrong path context field name' => [
			UseCaseError::SITELINK_TITLE_NOT_FOUND,
			'Page with title Test_article does not exist on the given site',
			[ UseCaseError::CONTEXT_TITLE => 'Test_article' ],
		];
	}

}
