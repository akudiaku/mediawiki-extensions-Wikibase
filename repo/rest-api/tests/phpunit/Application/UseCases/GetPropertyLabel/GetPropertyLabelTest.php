<?php declare( strict_types = 1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCases\GetPropertyLabel;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\RestApi\Application\UseCases\GetLatestPropertyRevisionMetadata;
use Wikibase\Repo\RestApi\Application\UseCases\GetPropertyLabel\GetPropertyLabel;
use Wikibase\Repo\RestApi\Application\UseCases\GetPropertyLabel\GetPropertyLabelRequest;
use Wikibase\Repo\RestApi\Application\UseCases\GetPropertyLabel\GetPropertyLabelResponse;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Domain\ReadModel\Label;
use Wikibase\Repo\RestApi\Domain\Services\PropertyLabelRetriever;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCases\GetPropertyLabel\GetPropertyLabel
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class GetPropertyLabelTest extends TestCase {

	private PropertyLabelRetriever $labelRetriever;
	private GetLatestPropertyRevisionMetadata $getRevisionMetadata;

	protected function setUp(): void {
		parent::setUp();

		$this->getRevisionMetadata = $this->createStub( GetLatestPropertyRevisionMetadata::class );
		$this->labelRetriever = $this->createStub( PropertyLabelRetriever::class );
	}

	public function testSuccess(): void {
		$label = new Label( 'en', 'instance of' );
		$propertyId = new NumericPropertyId( 'P31' );
		$lastModified = '20230922070707';
		$revisionId = 432;

		$this->labelRetriever = $this->createMock( PropertyLabelRetriever::class );
		$this->labelRetriever->expects( $this->once() )
			->method( 'getLabel' )
			->with( $propertyId, 'en' )
			->willReturn( $label );

		$this->getRevisionMetadata = $this->createStub( GetLatestPropertyRevisionMetadata::class );
		$this->getRevisionMetadata->method( 'execute' )->willReturn( [ $revisionId, $lastModified ] );

		$response = $this->newUseCase()->execute(
			new GetPropertyLabelRequest( "$propertyId", 'en' )
		);

		$this->assertEquals(
			new GetPropertyLabelResponse( $label, $lastModified, $revisionId ),
			$response
		);
	}

	public function testGivenPropertyDoesNotExist_throws(): void {
		$expectedException = $this->createStub( UseCaseError::class );
		$this->getRevisionMetadata = $this->createStub( GetLatestPropertyRevisionMetadata::class );
		$this->getRevisionMetadata->method( 'execute' )->willThrowException( $expectedException );

		try {
			$this->newUseCase()->execute( new GetPropertyLabelRequest( 'P999999', 'en' ) );
			$this->fail( 'expected exception was not thrown' );
		} catch ( UseCaseError $e ) {
			$this->assertSame( $expectedException, $e );
		}
	}

	private function newUseCase(): GetPropertyLabel {
		return new GetPropertyLabel( $this->getRevisionMetadata, $this->labelRetriever );
	}

}
