<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCases\RemoveItemStatement;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Statement\StatementGuid;
use Wikibase\Repo\RestApi\Application\UseCases\AssertItemExists;
use Wikibase\Repo\RestApi\Application\UseCases\RemoveItemStatement\RemoveItemStatement;
use Wikibase\Repo\RestApi\Application\UseCases\RemoveItemStatement\RemoveItemStatementRequest;
use Wikibase\Repo\RestApi\Application\UseCases\RemoveStatement\RemoveStatement;
use Wikibase\Repo\RestApi\Application\UseCases\RemoveStatement\RemoveStatementRequest;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseException;
use Wikibase\Repo\Tests\RestApi\Domain\Model\EditMetadataHelper;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCases\RemoveItemStatement\RemoveItemStatement
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 *
 */
class RemoveItemStatementTest extends TestCase {
	use EditMetadataHelper;

	private AssertItemExists $assertItemExists;
	private RemoveStatement $removeStatement;

	protected function setUp(): void {
		parent::setUp();

		$this->assertItemExists = $this->createStub( AssertItemExists::class );
		$this->removeStatement  = $this->createStub( RemoveStatement::class );
	}

	public function testGivenValidRemoveItemStatementRequest_callsRemoveStatementUseCase(): void {
		$itemId = new ItemId( 'Q123' );
		$statementId = new StatementGuid( $itemId, 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' );
		$newStatementSerialization = [ 'some' => 'statement' ];
		$editTags = [ 'some', 'tags' ];
		$isBot = false;
		$comment = 'statement removed by ' . __method__;

		$request = $this->newUseCaseRequest( [
			'$itemId' => (string)$itemId,
			'$statementId' => (string)$statementId,
			'$statement' => $newStatementSerialization,
			'$editTags' => $editTags,
			'$isBot' => $isBot,
			'$comment' => $comment,
		] );

		$removeStatementRequest = new RemoveStatementRequest(
			(string)$statementId,
			$editTags,
			$isBot,
			$comment,
			null
		);

		$this->removeStatement = $this->createMock( RemoveStatement::class );
		$this->removeStatement->expects( $this->once() )
			->method( 'execute' )
			->with( $removeStatementRequest );

		$this->newUseCase()->execute( $request );
	}

	public function testGivenInvalidRemoveStatementRequest_throws(): void {
		$useCaseRequest = $this->createStub( RemoveItemStatementRequest::class );
		$expectedUseCaseError = $this->createStub( UseCaseError::class );
		$this->removeStatement  = $this->createStub( RemoveStatement::class );
		$this->removeStatement->method( 'assertValidRequest' )->willThrowException( $expectedUseCaseError );

		try {
			$this->newUseCase()->execute( $useCaseRequest );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedUseCaseError, $e );
		}
	}

	public function testGivenStatementIdDoesNotMatchItemId_throws(): void {
		$statementId = 'Q456$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
		$request = $this->createStub( RemoveItemStatementRequest::class );
		$request->method( 'getItemId' )->willReturn( 'Q123' );
		$request->method( 'getStatementId' )->willReturn( $statementId );

		try {
			$this->newUseCase()->execute( $request );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::STATEMENT_NOT_FOUND, $e->getErrorCode() );
			$this->assertStringContainsString( $statementId, $e->getErrorMessage() );
		}
	}

	public function testGivenItemNotFoundOrRedirect_throws(): void {
		$itemId = new ItemId( 'Q123' );
		$statementId = new StatementGuid( $itemId, 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' );
		$editTags = [ 'some', 'tags' ];
		$isBot = false;
		$comment = 'statement removed by ' . __method__;
		$request = $this->newUseCaseRequest( [
			'$itemId' => (string)$itemId,
			'$statementId' => (string)$statementId,
			'$editTags' => $editTags,
			'$isBot' => $isBot,
			'$comment' => $comment,
		] );

		$expectedException = $this->createStub( UseCaseException::class );
		$this->assertItemExists = $this->createMock( AssertItemExists::class );
		$this->assertItemExists->expects( $this->once() )
			->method( 'execute' )
			->with( $itemId )
			->willThrowException( $expectedException );

		try {
			$this->newUseCase()->execute( $request );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseException $e ) {
			$this->assertSame( $expectedException, $e );
		}
	}

	public function testGivenRemoveStatementThrows_rethrows(): void {
		$request = $this->createStub( RemoveItemStatementRequest::class );
		$request->method( 'getItemId' )->willReturn( 'Q123' );
		$request->method( 'getStatementId' )->willReturn( 'Q123$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' );

		$expectedUseCaseError = $this->createStub( UseCaseError::class );
		$this->removeStatement  = $this->createStub( RemoveStatement::class );
		$this->removeStatement->method( 'execute' )->willThrowException( $expectedUseCaseError );
		$this->removeStatement->method( 'execute' )->willThrowException( $expectedUseCaseError );

		try {
			$this->newUseCase()->execute( $request );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedUseCaseError, $e );
		}
	}

	private function newUseCase(): RemoveItemStatement {
		return new RemoveItemStatement(
			$this->assertItemExists,
			$this->removeStatement,
		);
	}

	private function newUseCaseRequest( array $requestData ): RemoveItemStatementRequest {
		return new RemoveItemStatementRequest(
			$requestData['$itemId'],
			$requestData['$statementId'],
			$requestData['$editTags'] ?? [],
			$requestData['$isBot'] ?? false,
			$requestData['$comment'] ?? null,
			$requestData['$username'] ?? null
		);
	}
}