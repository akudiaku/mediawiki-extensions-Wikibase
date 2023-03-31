<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\UseCases\PatchItemStatement;

use Exception;
use Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\ItemIdParser;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Lookup\InMemoryDataTypeLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Statement\StatementGuidParser;
use Wikibase\DataModel\Statement\StatementGuid;
use Wikibase\DataModel\Tests\NewItem;
use Wikibase\DataModel\Tests\NewStatement;
use Wikibase\Repo\RestApi\Domain\Model\EditSummary;
use Wikibase\Repo\RestApi\Domain\Model\LatestItemRevisionMetadataResult;
use Wikibase\Repo\RestApi\Domain\Model\User;
use Wikibase\Repo\RestApi\Domain\ReadModel\Item as ReadModelItem;
use Wikibase\Repo\RestApi\Domain\ReadModel\ItemRevision;
use Wikibase\Repo\RestApi\Domain\ReadModel\StatementList;
use Wikibase\Repo\RestApi\Domain\Services\ItemRetriever;
use Wikibase\Repo\RestApi\Domain\Services\ItemRevisionMetadataRetriever;
use Wikibase\Repo\RestApi\Domain\Services\ItemStatementRetriever;
use Wikibase\Repo\RestApi\Domain\Services\ItemUpdater;
use Wikibase\Repo\RestApi\Domain\Services\PermissionChecker;
use Wikibase\Repo\RestApi\Infrastructure\DataAccess\WikibaseEntityPermissionChecker;
use Wikibase\Repo\RestApi\Infrastructure\JsonDiffJsonPatcher;
use Wikibase\Repo\RestApi\Serialization\PropertyValuePairSerializer;
use Wikibase\Repo\RestApi\Serialization\ReferenceSerializer;
use Wikibase\Repo\RestApi\Serialization\StatementSerializer;
use Wikibase\Repo\RestApi\UseCases\PatchItemStatement\PatchedStatementValidator;
use Wikibase\Repo\RestApi\UseCases\PatchItemStatement\PatchItemStatement;
use Wikibase\Repo\RestApi\UseCases\PatchItemStatement\PatchItemStatementRequest;
use Wikibase\Repo\RestApi\UseCases\PatchItemStatement\PatchItemStatementResponse;
use Wikibase\Repo\RestApi\UseCases\PatchItemStatement\PatchItemStatementValidator;
use Wikibase\Repo\RestApi\UseCases\UseCaseError;
use Wikibase\Repo\Tests\RestApi\Domain\Model\EditMetadataHelper;
use Wikibase\Repo\Tests\RestApi\Domain\ReadModel\NewStatementReadModel;

/**
 * @covers \Wikibase\Repo\RestApi\UseCases\PatchItemStatement\PatchItemStatement
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class PatchItemStatementTest extends TestCase {

	use EditMetadataHelper;

	private const STRING_PROPERTY = 'P123';

	/**
	 * @var MockObject|PatchItemStatementValidator
	 */
	private $useCaseValidator;

	/**
	 * @var MockObject|PatchedStatementValidator
	 */
	private $patchedStatementValidator;

	private StatementSerializer $statementSerializer;

	/**
	 * @var MockObject|ItemStatementRetriever
	 */
	private $statementRetriever;

	/**
	 * @var MockObject|ItemRetriever
	 */
	private $itemRetriever;

	/**
	 * @var MockObject|ItemUpdater
	 */
	private $itemUpdater;

	/**
	 * @var MockObject|ItemRevisionMetadataRetriever
	 */
	private $revisionMetadataRetriever;

	/**
	 * @var MockObject|PermissionChecker
	 */
	private $permissionChecker;

	protected function setUp(): void {
		parent::setUp();

		$this->useCaseValidator = $this->createStub( PatchItemStatementValidator::class );
		$this->patchedStatementValidator = $this->createStub( PatchedStatementValidator::class );
		$this->statementRetriever = $this->createStub( ItemStatementRetriever::class );
		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemUpdater = $this->createStub( ItemUpdater::class );
		$this->revisionMetadataRetriever = $this->newRevisionMetadataRetrieverWithSomeConcreteRevision();
		$this->permissionChecker = $this->createStub( PermissionChecker::class );
		$this->permissionChecker->method( 'canEdit' )->willReturn( true );

		$this->statementSerializer = $this->newStatementSerializer();
	}

	public function testPatchItemStatement_success(): void {
		$itemId = 'Q123';
		$statementId = new StatementGuid( new ItemId( $itemId ), 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' );
		$oldStatementValue = 'old statement value';
		$newStatementValue = 'new statement value';
		[ $statementToPatch, $originalStatementWriteModel ] = NewStatementReadModel::forProperty( self::STRING_PROPERTY )
			->withGuid( $statementId )
			->withValue( $oldStatementValue )
			->buildReadAndWriteModel();
		$itemToUpdate = NewItem::withId( $itemId )
			->andStatement( $originalStatementWriteModel )
			->build();
		$postModificationRevisionId = 567;
		$modificationTimestamp = '20221111070707';
		$editTags = [ 'some', 'tags' ];
		$isBot = false;
		$comment = 'statement replaced by ' . __method__;

		$patch = $this->getValidValueReplacingPatch( $newStatementValue );

		$patchedStatement = NewStatement::forProperty( self::STRING_PROPERTY )
			->withGuid( $statementId )
			->withValue( $newStatementValue )
			->build();

		$requestData = [
			'$statementId' => (string)$statementId,
			'$patch' => $patch,
			'$editTags' => $editTags,
			'$isBot' => $isBot,
			'$comment' => $comment,
			'$username' => null,
			'$itemId' => $itemId,
		];

		$request = $this->newUseCaseRequest( $requestData );

		$this->statementRetriever = $this->createStub( ItemStatementRetriever::class );
		$this->statementRetriever->method( 'getStatement' )->willReturn( $statementToPatch );

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever
			->method( 'getItem' )
			->with( $itemId )
			->willReturn( $itemToUpdate );

		$this->patchedStatementValidator = $this->createStub( PatchedStatementValidator::class );
		$this->patchedStatementValidator->method( 'validateAndDeserializeStatement' )->willReturn( $patchedStatement );

		$updatedItem = new ReadModelItem( new StatementList(
			NewStatementReadModel::forProperty( 'P123' )->withGuid( $statementId )->withValue( $newStatementValue )->build()
		) );
		$this->itemUpdater = $this->createStub( ItemUpdater::class );
		$this->itemUpdater->expects( $this->once() )
			->method( 'update' )
			->with(
				$this->callback(
					fn( Item $item ) => $item->getStatements()
							->getFirstStatementWithGuid( (string)$statementId )
							->getMainSnak()
							->getDataValue()
							->getValue() === $newStatementValue
				),
				$this->expectEquivalentMetadata( $editTags, $isBot, $comment, EditSummary::PATCH_ACTION )
			)
			->willReturn( new ItemRevision( $updatedItem, $modificationTimestamp, $postModificationRevisionId ) );

		$this->revisionMetadataRetriever = $this->createStub( ItemRevisionMetadataRetriever::class );
		$this->revisionMetadataRetriever->method( 'getLatestRevisionMetadata' )->willReturn(
			LatestItemRevisionMetadataResult::concreteRevision( 456, '20221111070607' )
		);

		$response = $this->newUseCase()->execute( $request );

		$this->assertInstanceOf( PatchItemStatementResponse::class, $response );
		$this->assertSame(
			$updatedItem->getStatements()->getStatementById( $statementId ),
			$response->getStatement()
		);
		$this->assertSame( $modificationTimestamp, $response->getLastModified() );
		$this->assertSame( $postModificationRevisionId, $response->getRevisionId() );
	}

	public function testRequestedItemNotFound_throwsUseCaseError(): void {
		$this->revisionMetadataRetriever = $this->newItemRevisionMetadataRetriever( LatestItemRevisionMetadataResult::itemNotFound() );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$itemId' => 'Q42',
					'$statementId' => 'Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
					'$patch' => $this->getValidValueReplacingPatch(),
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::ITEM_NOT_FOUND, $e->getErrorCode() );
			$this->assertSame( 'Could not find an item with the ID: Q42', $e->getErrorMessage() );
		}
	}

	public function testItemForStatementNotFound_throwsUseCaseError(): void {
		$this->revisionMetadataRetriever = $this->newItemRevisionMetadataRetriever( LatestItemRevisionMetadataResult::itemNotFound() );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => 'Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
					'$patch' => $this->getValidValueReplacingPatch(),
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::STATEMENT_NOT_FOUND, $e->getErrorCode() );
			$this->assertSame(
				'Could not find a statement with the ID: Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
				$e->getErrorMessage()
			);
		}
	}

	public function testItemForStatementIsRedirect_throwsUseCaseError(): void {
		$this->revisionMetadataRetriever = $this->newItemRevisionMetadataRetriever(
			LatestItemRevisionMetadataResult::redirect( new ItemId( 'Q321' ) )
		);

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => 'Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
					'$patch' => $this->getValidValueReplacingPatch(),
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::STATEMENT_NOT_FOUND, $e->getErrorCode() );
			$this->assertSame(
				'Could not find a statement with the ID: Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
				$e->getErrorMessage()
			);
		}
	}

	public function testStatementIdMismatchingItemId_throwsUseCaseError(): void {
		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$itemId' => 'Q666',
					'$statementId' => 'Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
					'$patch' => $this->getValidValueReplacingPatch(),
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::STATEMENT_NOT_FOUND, $e->getErrorCode() );
			$this->assertSame(
				'Could not find a statement with the ID: Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
				$e->getErrorMessage()
			);
		}
	}

	public function testStatementNotFoundOnItem_throwsUseCaseError(): void {
		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn( NewItem::withId( 'Q42' )->build() );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => 'Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
					'$patch' => $this->getValidValueReplacingPatch(),
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::STATEMENT_NOT_FOUND, $e->getErrorCode() );
			$this->assertSame(
				'Could not find a statement with the ID: Q42$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
				$e->getErrorMessage()
			);
		}
	}

	public function testRejectsPropertyIdChange(): void {
		$itemId = 'Q123';
		$guid = $itemId . '$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
		[ $statementToPatch, $originalStatementWriteModel ] = NewStatementReadModel::noValueFor( self::STRING_PROPERTY )
			->withGuid( $guid )
			->buildReadAndWriteModel();
		$item = NewItem::withId( $itemId )->andStatement( $originalStatementWriteModel )->build();
		$patchedStatement = NewStatement::noValueFor( 'P321' )->withGuid( $guid )->build();

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn( $item );

		$this->statementRetriever = $this->createStub( ItemStatementRetriever::class );
		$this->statementRetriever->method( 'getStatement' )->willReturn( $statementToPatch );

		$this->patchedStatementValidator = $this->createStub( PatchedStatementValidator::class );
		$this->patchedStatementValidator->method( 'validateAndDeserializeStatement' )->willReturn( $patchedStatement );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => $guid,
					'$patch' => [ [ 'op' => 'replace', 'path' => '/property/id', 'value' => 'P321' ] ],
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::INVALID_OPERATION_CHANGED_PROPERTY, $e->getErrorCode() );
			$this->assertSame(
				'Cannot change the property of the existing statement',
				$e->getErrorMessage()
			);
		}
	}

	public function testRejectsStatementIdChange(): void {
		$itemId = 'Q123';
		$originalGuid = $itemId . '$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
		$newGuid = $itemId . '$FFFFFFFF-BBBB-CCCC-DDDD-EEEEEEEEEEEE';
		[ $statementToPatch, $originalStatementWriteModel ] = NewStatementReadModel::noValueFor( self::STRING_PROPERTY )
			->withGuid( $originalGuid )
			->buildReadAndWriteModel();
		$patchedStatement = NewStatement::noValueFor( self::STRING_PROPERTY )->withGuid( $newGuid )->build();
		$item = NewItem::withId( $itemId )->andStatement( $originalStatementWriteModel )->build();

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn( $item );

		$this->statementRetriever = $this->createStub( ItemStatementRetriever::class );
		$this->statementRetriever->method( 'getStatement' )->willReturn( $statementToPatch );

		$this->patchedStatementValidator = $this->createStub( PatchedStatementValidator::class );
		$this->patchedStatementValidator->method( 'validateAndDeserializeStatement' )->willReturn( $patchedStatement );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => $originalGuid,
					'$patch' => [ [ 'op' => 'replace', 'path' => '/id', 'value' => $newGuid ] ],
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::INVALID_OPERATION_CHANGED_STATEMENT_ID, $e->getErrorCode() );
			$this->assertSame(
				'Cannot change the ID of the existing statement',
				$e->getErrorMessage()
			);
		}
	}

	public function testGivenProtectedItem_throwsUseCaseError(): void {
		$itemId = new ItemId( 'Q123' );
		$statementId = "$itemId\$AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE";
		[ $statementReadModel, $statementWriteModel ] = NewStatementReadModel::forProperty( self::STRING_PROPERTY )
			->withGuid( $statementId )
			->withValue( 'abc' )
			->buildReadAndWriteModel();

		$this->permissionChecker = $this->createMock( WikibaseEntityPermissionChecker::class );
		$this->permissionChecker->expects( $this->once() )
			->method( 'canEdit' )
			->with( User::newAnonymous(), $itemId )
			->willReturn( false );

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn(
			NewItem::withId( $itemId )->andStatement( $statementWriteModel )->build()
		);

		$this->statementRetriever = $this->createStub( ItemStatementRetriever::class );
		$this->statementRetriever->method( 'getStatement' )->willReturn( $statementReadModel );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => $statementId,
					'$patch' => $this->getValidValueReplacingPatch(),
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::PERMISSION_DENIED, $e->getErrorCode() );
			$this->assertSame( 'You have no permission to edit this item.', $e->getErrorMessage() );
		}
	}

	/**
	 * @dataProvider inapplicablePatchProvider
	 */
	public function testGivenValidInapplicablePatch_throwsUseCaseError( array $patch, string $expectedErrorCode ): void {
		$statementId = new StatementGuid( new ItemId( 'Q123' ), 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' );
		$this->setRetrieversForItemWithStringStatement( $statementId );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => "$statementId",
					'$patch' => $patch,
				] )
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedErrorCode, $e->getErrorCode() );
		}
	}

	public function inapplicablePatchProvider(): Generator {
		yield 'patch test operation failed' => [
			[
				[
					'op' => 'test',
					'path' => '/value/content',
					'value' => 'these are not the droids you are looking for',
				],
			],
			UseCaseError::PATCH_TEST_FAILED,
		];

		yield 'non-existent path' => [
			[
				[
					'op' => 'remove',
					'path' => '/this/path/does/not/exist',
				],
			],
			UseCaseError::PATCH_TARGET_NOT_FOUND,
		];
	}

	public function testGivenPatchedStatementInvalid_throwsUseCaseError(): void {
		$patch = [
			[
				'op' => 'remove',
				'path' => '/property',
			],
		];

		$statementId = new StatementGuid( new ItemId( 'Q123' ), 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE' );
		$this->setRetrieversForItemWithStringStatement( $statementId );

		$expectedException = new UseCaseError( 'fail', 'message' );
		$this->patchedStatementValidator = $this->createStub( PatchedStatementValidator::class );
		$this->patchedStatementValidator
			->method( 'validateAndDeserializeStatement' )
			->willThrowException( $expectedException );

		try {
			$this->newUseCase()->execute(
				$this->newUseCaseRequest( [
					'$statementId' => "$statementId",
					'$patch' => $patch,
				] )
			);
		} catch ( Exception $e ) {
			$this->assertSame( $expectedException, $e );
		}
	}

	private function newUseCase(): PatchItemStatement {
		return new PatchItemStatement(
			$this->useCaseValidator,
			$this->patchedStatementValidator,
			new JsonDiffJsonPatcher(),
			$this->statementSerializer,
			new StatementGuidParser( new ItemIdParser() ),
			$this->statementRetriever,
			$this->itemRetriever,
			$this->itemUpdater,
			$this->revisionMetadataRetriever,
			$this->permissionChecker
		);
	}

	private function newUseCaseRequest( array $requestData ): PatchItemStatementRequest {
		return new PatchItemStatementRequest(
			$requestData['$statementId'],
			$requestData['$patch'],
			$requestData['$editTags'] ?? [],
			$requestData['$isBot'] ?? false,
			$requestData['$comment'] ?? null,
			$requestData['$username'] ?? null,
			$requestData['$itemId'] ?? null
		);
	}

	private function newItemRevisionMetadataRetriever( LatestItemRevisionMetadataResult $result ): ItemRevisionMetadataRetriever {
		$metadataRetriever = $this->createStub( ItemRevisionMetadataRetriever::class );
		$metadataRetriever->method( 'getLatestRevisionMetadata' )->willReturn( $result );

		return $metadataRetriever;
	}

	private function setRetrieversForItemWithStringStatement( StatementGuid $statementId ): void {
		[ $statementReadModel, $statementWriteModel ] = NewStatementReadModel::forProperty( self::STRING_PROPERTY )
			->withGuid( $statementId )
			->withValue( 'abc' )
			->buildReadAndWriteModel();

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn(
			NewItem::withId( $statementId->getEntityId() )
				->andStatement( $statementWriteModel )->build()
		);

		$this->statementRetriever = $this->createStub( ItemStatementRetriever::class );
		$this->statementRetriever->method( 'getStatement' )->willReturn( $statementReadModel );
	}

	private function getValidValueReplacingPatch( string $newStatementValue = '' ): array {
		return [
			[
				'op' => 'replace',
				'path' => '/value/content',
				'value' => $newStatementValue,
			],
		];
	}

	private function newRevisionMetadataRetrieverWithSomeConcreteRevision(): ItemRevisionMetadataRetriever {
		return $this->newItemRevisionMetadataRetriever(
			LatestItemRevisionMetadataResult::concreteRevision( 123, '20220708030405' )
		);
	}

	private function newStatementSerializer(): StatementSerializer {
		$propertyValuePairSerializer = new PropertyValuePairSerializer( $this->newDataTypeLookup() );

		return new StatementSerializer(
			$propertyValuePairSerializer,
			new ReferenceSerializer( $propertyValuePairSerializer )
		);
	}

	private function newDataTypeLookup(): PropertyDataTypeLookup {
		$dataTypeLookup = new InMemoryDataTypeLookup();
		$dataTypeLookup->setDataTypeForProperty( new NumericPropertyId( self::STRING_PROPERTY ), 'string' );

		return $dataTypeLookup;
	}

}
