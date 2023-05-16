<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCases\PatchItemLabels;

use Generator;
use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\Item as DataModelItem;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Tests\NewItem;
use Wikibase\Repo\RestApi\Application\Serialization\LabelsDeserializer;
use Wikibase\Repo\RestApi\Application\Serialization\LabelsSerializer;
use Wikibase\Repo\RestApi\Application\UseCases\PatchItemLabels\PatchedLabelsValidator;
use Wikibase\Repo\RestApi\Application\UseCases\PatchItemLabels\PatchItemLabels;
use Wikibase\Repo\RestApi\Application\UseCases\PatchItemLabels\PatchItemLabelsRequest;
use Wikibase\Repo\RestApi\Application\UseCases\PatchItemLabels\PatchItemLabelsValidator;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseException;
use Wikibase\Repo\RestApi\Application\Validation\ItemLabelTextValidator;
use Wikibase\Repo\RestApi\Application\Validation\LanguageCodeValidator;
use Wikibase\Repo\RestApi\Domain\Model\EditSummary;
use Wikibase\Repo\RestApi\Domain\Model\User;
use Wikibase\Repo\RestApi\Domain\ReadModel\Descriptions;
use Wikibase\Repo\RestApi\Domain\ReadModel\Item;
use Wikibase\Repo\RestApi\Domain\ReadModel\ItemRevision;
use Wikibase\Repo\RestApi\Domain\ReadModel\Label;
use Wikibase\Repo\RestApi\Domain\ReadModel\Labels;
use Wikibase\Repo\RestApi\Domain\ReadModel\LatestItemRevisionMetadataResult;
use Wikibase\Repo\RestApi\Domain\ReadModel\StatementList;
use Wikibase\Repo\RestApi\Domain\Services\ItemLabelsRetriever;
use Wikibase\Repo\RestApi\Domain\Services\ItemRetriever;
use Wikibase\Repo\RestApi\Domain\Services\ItemRevisionMetadataRetriever;
use Wikibase\Repo\RestApi\Domain\Services\ItemUpdater;
use Wikibase\Repo\RestApi\Domain\Services\JsonPatcher;
use Wikibase\Repo\RestApi\Domain\Services\PermissionChecker;
use Wikibase\Repo\RestApi\Infrastructure\DataAccess\WikibaseEntityPermissionChecker;
use Wikibase\Repo\RestApi\Infrastructure\JsonDiffJsonPatcher;
use Wikibase\Repo\Tests\RestApi\Domain\Model\EditMetadataHelper;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCases\PatchItemLabels\PatchItemLabels
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class PatchItemLabelsTest extends TestCase {

	use EditMetadataHelper;

	private ItemLabelsRetriever $labelsRetriever;
	private LabelsSerializer $labelsSerializer;
	private JsonPatcher $patcher;
	private PatchedLabelsValidator $patchedLabelsValidator;
	private ItemRetriever $itemRetriever;
	private ItemUpdater $itemUpdater;
	private ItemRevisionMetadataRetriever $metadataRetriever;
	private PermissionChecker $permissionChecker;
	private PatchItemLabelsValidator $validator;

	protected function setUp(): void {
		parent::setUp();

		$this->labelsRetriever = $this->createStub( ItemLabelsRetriever::class );
		$this->labelsSerializer = new LabelsSerializer();
		$this->patcher = new JsonDiffJsonPatcher();
		$this->patchedLabelsValidator = new PatchedLabelsValidator(
			new LabelsDeserializer(),
			$this->createStub( ItemLabelTextValidator::class ),
			$this->createStub( LanguageCodeValidator::class )
		);
		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemUpdater = $this->createStub( ItemUpdater::class );
		$this->metadataRetriever = $this->createStub( ItemRevisionMetadataRetriever::class );
		$this->metadataRetriever->method( 'getLatestRevisionMetadata' )
			->willReturn( LatestItemRevisionMetadataResult::concreteRevision( 321, '20201111070707' ) );
		$this->permissionChecker = $this->createStub( PermissionChecker::class );
		$this->permissionChecker->method( 'canEdit' )->willReturn( true );
		$this->validator = $this->createStub( PatchItemLabelsValidator::class );
	}

	public function testHappyPath(): void {
		$itemId = new ItemId( 'Q42' );
		$item = NewItem::withId( $itemId )->build();

		$newLabelText = 'pomme de terre';
		$newLabelLanguage = 'fr';

		$this->labelsRetriever = $this->createStub( ItemLabelsRetriever::class );
		$this->labelsRetriever->method( 'getLabels' )->willReturn( new Labels() );

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn( $item );

		$revisionId = 657;
		$lastModified = '20221212040506';
		$editTags = [ 'some', 'tags' ];
		$isBot = false;
		$comment = 'labels replaced by ' . __method__;

		$updatedItem = new Item(
			new Labels( new Label( $newLabelLanguage, $newLabelText ) ),
			new Descriptions(),
			new StatementList()
		);
		$this->itemUpdater = $this->createMock( ItemUpdater::class );
		$this->itemUpdater->expects( $this->once() )
			->method( 'update' )
			->with(
				$this->callback(
					fn( DataModelItem $item ) => $item->getLabels()->getByLanguage( $newLabelLanguage )->getText() === $newLabelText
				),
				$this->expectEquivalentMetadata( $editTags, $isBot, $comment, EditSummary::PATCH_ACTION )
			)
			->willReturn( new ItemRevision( $updatedItem, $lastModified, $revisionId ) );

		$response = $this->newUseCase()->execute(
			new PatchItemLabelsRequest(
				"$itemId",
				[
					[
						'op' => 'add',
						'path' => "/$newLabelLanguage",
						'value' => $newLabelText,
					],
				],
				$editTags,
				$isBot,
				$comment,
				null
			)
		);

		$this->assertSame( $response->getLabels(), $updatedItem->getLabels() );
		$this->assertSame( $lastModified, $response->getLastModified() );
		$this->assertSame( $revisionId, $response->getRevisionId() );
	}

	public function testInvalidRequest_throwsException(): void {
		$expectedException = new UseCaseException( 'invalid-label-patch-test' );
		$this->validator = $this->createStub( PatchItemLabelsValidator::class );
		$this->validator->method( 'assertValidRequest' )->willThrowException( $expectedException );
		try {
			$this->newUseCase()->execute( $this->createStub( PatchItemLabelsRequest::class ) );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseException $e ) {
			$this->assertSame( $expectedException, $e );
		}
	}

	public function testGivenItemNotFound_throwsUseCaseError(): void {
		$itemId = 'Q789';
		$this->metadataRetriever = $this->createStub( ItemRevisionMetadataRetriever::class );
		$this->metadataRetriever->method( 'getLatestRevisionMetadata' )
			->willReturn( LatestItemRevisionMetadataResult::itemNotFound() );

		try {
			$this->newUseCase()->execute(
				new PatchItemLabelsRequest(
					$itemId,
					[
						[
							'op' => 'add',
							'path' => '/ar',
							'value' => 'new arabic label',
						],
					],
					[],
					false,
					null,
					null
				)
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::ITEM_NOT_FOUND, $e->getErrorCode() );
			$this->assertStringContainsString( $itemId, $e->getErrorMessage() );
		}
	}

	public function testGivenItemRedirect_throwsUseCaseError(): void {
		$redirectSource = 'Q321';
		$redirectTarget = 'Q123';

		$this->metadataRetriever = $this->createStub( ItemRevisionMetadataRetriever::class );
		$this->metadataRetriever->method( 'getLatestRevisionMetadata' )
			->willReturn( LatestItemRevisionMetadataResult::redirect( new ItemId( $redirectTarget ) ) );

		try {
			$this->newUseCase()->execute(
				new PatchItemLabelsRequest(
					$redirectSource,
					[
						[
							'op' => 'add',
							'path' => '/ar',
							'value' => 'new arabic label',
						],
					],
					[],
					false,
					null,
					null
				)
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::ITEM_REDIRECTED, $e->getErrorCode() );
			$this->assertStringContainsString( $redirectTarget, $e->getErrorMessage() );
		}
	}

	public function testGivenEditIsUnauthorized_throwsUseCaseError(): void {
		$itemId = new ItemId( 'Q123' );

		$this->permissionChecker = $this->createMock( WikibaseEntityPermissionChecker::class );
		$this->permissionChecker->expects( $this->once() )
			->method( 'canEdit' )
			->with( User::newAnonymous(), $itemId )
			->willReturn( false );

		try {
			$this->newUseCase()->execute(
				new PatchItemLabelsRequest(
					"$itemId",
					[
						[
							'op' => 'remove',
							'path' => '/en',
						],
					],
					[],
					false,
					null,
					null
				)
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame(
				UseCaseError::PERMISSION_DENIED,
				$e->getErrorCode()
			);
		}
	}

	/**
	 * @dataProvider provideInapplicablePatch
	 */
	public function testGivenValidInapplicablePatch_throwsUseCaseError(
		array $patch,
		string $expectedErrorCode,
		array $expectedContext
	): void {
		$this->labelsRetriever = $this->createStub( ItemLabelsRetriever::class );
		$this->labelsRetriever->method( 'getLabels' )->willReturn( new Labels( new Label( 'en', 'English Label' ) ) );

		try {
			$this->newUseCase()->execute( $this->newUseCaseRequest( 'Q123', $patch ) );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedErrorCode, $e->getErrorCode() );
			$this->assertEquals( $expectedContext, $e->getErrorContext() );
		}
	}

	public function provideInapplicablePatch(): Generator {
		$patchOperation = [ 'op' => 'remove', 'path' => '/path/does/not/exist' ];
		yield 'non-existent path' => [
			[ $patchOperation ],
			UseCaseError::PATCH_TARGET_NOT_FOUND,
			[ 'operation' => $patchOperation, 'field' => 'path' ],
		];

		$patchOperation = [ 'op' => 'copy', 'from' => '/path/does/not/exist', 'path' => '/en' ];
		yield 'non-existent from' => [
			[ $patchOperation ],
			UseCaseError::PATCH_TARGET_NOT_FOUND,
			[ 'operation' => $patchOperation, 'field' => 'from' ],
		];

		$patchOperation = [ 'op' => 'test', 'path' => '/en', 'value' => 'incorrect value' ];
		yield 'patch test operation failed' => [
			[ $patchOperation ],
			UseCaseError::PATCH_TEST_FAILED,
			[ 'operation' => $patchOperation, 'actual-value' => 'English Label' ],
		];
	}

	public function testGivenPatchedLabelsInvalid_throwsUseCaseError(): void {
		$item = NewItem::withId( 'Q123' )->build();
		$patchResult = [ 'ar' => '' ];

		$this->itemRetriever = $this->createStub( ItemRetriever::class );
		$this->itemRetriever->method( 'getItem' )->willReturn( $item );

		$this->labelsRetriever = $this->createStub( ItemLabelsRetriever::class );
		$this->labelsRetriever->method( 'getLabels' )->willReturn( new Labels() );

		$expectedUseCaseError = $this->createStub( UseCaseError::class );
		$this->patchedLabelsValidator = $this->createMock( PatchedLabelsValidator::class );
		$this->patchedLabelsValidator->expects( $this->once() )
			->method( 'validateAndDeserialize' )
			->with( $patchResult )
			->willThrowException( $expectedUseCaseError );

		try {
			$this->newUseCase()->execute(
				new PatchItemLabelsRequest(
					$item->getId()->getSerialization(),
					[
						[
							'op' => 'add',
							'path' => '/ar',
							'value' => '',
						],
					],
					[],
					false,
					null,
					null
				)
			);
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedUseCaseError, $e );
		}
	}

	private function newUseCase(): PatchItemLabels {
		return new PatchItemLabels(
			$this->labelsRetriever,
			$this->labelsSerializer,
			$this->patcher,
			$this->patchedLabelsValidator,
			$this->itemRetriever,
			$this->itemUpdater,
			$this->metadataRetriever,
			$this->permissionChecker,
			$this->validator
		);
	}

	private function newUseCaseRequest( string $itemId, array $patch ): PatchItemLabelsRequest {
		return new PatchItemLabelsRequest( $itemId, $patch, [], false, '', null );
	}

}