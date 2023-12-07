<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCases\AddPropertyAliasesInLanguage;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Term\AliasGroup;
use Wikibase\DataModel\Term\AliasGroupList;
use Wikibase\DataModel\Term\Fingerprint;
use Wikibase\Repo\RestApi\Application\UseCases\AddPropertyAliasesInLanguage\AddPropertyAliasesInLanguage;
use Wikibase\Repo\RestApi\Application\UseCases\AddPropertyAliasesInLanguage\AddPropertyAliasesInLanguageRequest;
use Wikibase\Repo\RestApi\Application\UseCases\AssertPropertyExists;
use Wikibase\Repo\RestApi\Application\UseCases\AssertUserIsAuthorized;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Domain\Model\AliasesInLanguageEditSummary;
use Wikibase\Repo\RestApi\Domain\Model\EditMetadata;
use Wikibase\Repo\RestApi\Domain\Model\EditSummary;
use Wikibase\Repo\RestApi\Domain\ReadModel\Aliases;
use Wikibase\Repo\RestApi\Domain\ReadModel\AliasesInLanguage;
use Wikibase\Repo\RestApi\Domain\ReadModel\Descriptions;
use Wikibase\Repo\RestApi\Domain\ReadModel\Labels;
use Wikibase\Repo\RestApi\Domain\ReadModel\Property as ReadModelProperty;
use Wikibase\Repo\RestApi\Domain\ReadModel\PropertyRevision;
use Wikibase\Repo\RestApi\Domain\ReadModel\StatementList;
use Wikibase\Repo\RestApi\Domain\Services\PropertyRetriever;
use Wikibase\Repo\RestApi\Domain\Services\PropertyUpdater;
use Wikibase\Repo\Tests\RestApi\Application\UseCaseRequestValidation\TestValidatingRequestDeserializer;
use Wikibase\Repo\Tests\RestApi\Domain\Model\EditMetadataHelper;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCases\AddPropertyAliasesInLanguage\AddPropertyAliasesInLanguage
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class AddPropertyAliasesInLanguageTest extends TestCase {

	use EditMetadataHelper;

	private AssertPropertyExists $assertPropertyExists;
	private AssertUserIsAuthorized $assertUserIsAuthorized;
	private PropertyRetriever $propertyRetriever;
	private PropertyUpdater $propertyUpdater;

	protected function setUp(): void {
		parent::setUp();

		$this->assertPropertyExists = $this->createStub( AssertPropertyExists::class );
		$this->assertUserIsAuthorized = $this->createStub( AssertUserIsAuthorized::class );
		$this->propertyRetriever = $this->createStub( PropertyRetriever::class );
		$this->propertyUpdater = $this->createStub( PropertyUpdater::class );
	}

	public function testCreateAliases(): void {
		$languageCode = 'en';
		$property = new Property( new NumericPropertyId( 'P123' ), null, 'string' );
		$aliasesToCreate = [ 'alias 1', 'alias 2' ];
		$postModificationRevisionId = 322;
		$modificationTimestamp = '20221111070707';
		$editTags = [ TestValidatingRequestDeserializer::ALLOWED_TAGS[0] ];
		$isBot = false;
		$comment = 'potato';

		$request = $this->newRequest(
			$property->getId()->getSerialization(),
			$languageCode,
			$aliasesToCreate,
			$editTags,
			$isBot,
			$comment,
			null
		);

		$this->propertyRetriever = $this->createStub( PropertyRetriever::class );
		$this->propertyRetriever->method( 'getProperty' )->willReturn( $property );

		$updatedProperty = new ReadModelProperty(
			new Labels(),
			new Descriptions(),
			new Aliases( new AliasesInLanguage( $languageCode, $aliasesToCreate ) ),
			new StatementList()
		);
		$this->propertyUpdater = $this->createMock( PropertyUpdater::class );
		$this->propertyUpdater->method( 'update' )
			->with(
				$this->callback(
					fn( Property $property ) => $property->getAliasGroups()
						->getByLanguage( $languageCode )
						->equals( new AliasGroup( $languageCode, $aliasesToCreate ) )
				),
				$this->expectEquivalentMetadata( $editTags, $isBot, $comment, EditSummary::ADD_ACTION )
			)
			->willReturn( new PropertyRevision( $updatedProperty, $modificationTimestamp, $postModificationRevisionId ) );

		$response = $this->newUseCase()->execute( $request );

		$this->assertEquals( new AliasesInLanguage( $languageCode, $aliasesToCreate ), $response->getAliases() );
		$this->assertFalse( $response->wasAddedToExistingAliasGroup() );
		$this->assertSame( $postModificationRevisionId, $response->getRevisionId() );
		$this->assertSame( $modificationTimestamp, $response->getLastModified() );
	}

	public function testAddToExistingAliases(): void {
		$languageCode = 'en';
		$existingAliases = [ 'alias 1', 'alias 2' ];
		$property = new Property(
			new NumericPropertyId( 'P123' ),
			new Fingerprint( null, null, new AliasGroupList( [ new AliasGroup( $languageCode, $existingAliases ) ] ) ),
			'string'
		);
		$aliasesToAdd = [ 'alias 3', 'alias 4' ];
		$request = $this->newRequest( "{$property->getId()}", $languageCode, $aliasesToAdd );

		$this->propertyRetriever = $this->createStub( PropertyRetriever::class );
		$this->propertyRetriever->method( 'getProperty' )->willReturn( $property );

		$updatedAliases = array_merge( $existingAliases, $aliasesToAdd );
		$updatedProperty = new ReadModelProperty(
			new Labels(),
			new Descriptions(),
			new Aliases( new AliasesInLanguage( $languageCode, $updatedAliases ) ),
			new StatementList()
		);
		$this->propertyUpdater = $this->createMock( PropertyUpdater::class );
		$this->propertyUpdater->method( 'update' )
			->with(
				$this->callback(
					fn( Property $property ) => $property->getAliasGroups()
						->getByLanguage( $languageCode )
						->equals( new AliasGroup( $languageCode, $updatedAliases ) ),
				),
				new EditMetadata(
					[],
					false,
					AliasesInLanguageEditSummary::newAddSummary( null, new AliasGroup( $languageCode, $aliasesToAdd ) )
				)
			)
			->willReturn( new PropertyRevision( $updatedProperty, '20221111070707', 322 ) );

		$response = $this->newUseCase()->execute( $request );

		$this->assertEquals( new AliasesInLanguage( $languageCode, $updatedAliases ), $response->getAliases() );
		$this->assertTrue( $response->wasAddedToExistingAliasGroup() );
	}

	public function testValidationError_throwsUseCaseError(): void {
		try {
			$this->newUseCase()->execute( $this->newRequest( 'P123', 'en', [ '' ] ) );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( UseCaseError::ALIAS_EMPTY, $e->getErrorCode() );
		}
	}

	public function testGivenPropertyNotFound_throws(): void {
		$expectedError = $this->createStub( UseCaseError::class );
		$this->assertPropertyExists->method( 'execute' )
			->willThrowException( $expectedError );
		try {
			$this->newUseCase()->execute( $this->newRequest( 'P999', 'en', [ 'new alias' ] ) );
			$this->fail( 'this should not be reached' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedError, $e );
		}
	}

	public function testGivenUserUnauthorized_throws(): void {
		$expectedException = $this->createStub( UseCaseError::class );
		$this->assertUserIsAuthorized = $this->createStub( AssertUserIsAuthorized::class );
		$this->assertUserIsAuthorized->method( 'execute' )->willThrowException( $expectedException );

		try {
			$this->newUseCase()->execute( $this->newRequest( 'P1', 'en', [ 'new alias' ] ) );
			$this->fail( 'expected exception not thrown' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedException, $e );
		}
	}

	private function newUseCase(): AddPropertyAliasesInLanguage {
		return new AddPropertyAliasesInLanguage(
			new TestValidatingRequestDeserializer(),
			$this->assertPropertyExists,
			$this->assertUserIsAuthorized,
			$this->propertyRetriever,
			$this->propertyUpdater
		);
	}

	private function newRequest(
		string $propertyId,
		string $languageCode,
		array $aliases,
		array $tags = [],
		bool $isBot = false,
		string $comment = null,
		string $username = null
	): AddPropertyAliasesInLanguageRequest {
		return new AddPropertyAliasesInLanguageRequest( $propertyId, $languageCode, $aliases, $tags, $isBot, $comment, $username );
	}

}
