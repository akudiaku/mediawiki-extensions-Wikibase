<?php declare( strict_types=1 );

namespace Wikibase\Repo\Tests\RestApi\Application\UseCases\GetProperty;

use PHPUnit\Framework\TestCase;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\RestApi\Application\UseCases\GetProperty\GetProperty;
use Wikibase\Repo\RestApi\Application\UseCases\GetProperty\GetPropertyRequest;
use Wikibase\Repo\RestApi\Domain\ReadModel\PropertyData;
use Wikibase\Repo\RestApi\Domain\Services\PropertyDataRetriever;

/**
 * @covers \Wikibase\Repo\RestApi\Application\UseCases\GetProperty\GetProperty
 *
 * @group Wikibase
 *
 * @license GPL-2.0-or-later
 */
class GetPropertyTest extends TestCase {

	public function testHappyPath(): void {
		$propertyId = new NumericPropertyId( 'P123' );
		$expectedPropertyData = $this->createStub( PropertyData::class );

		$propertyDataRetriever = $this->createMock( PropertyDataRetriever::class );
		$propertyDataRetriever->expects( $this->once() )
			->method( 'getPropertyData' )
			->with( $propertyId )
			->willReturn( $expectedPropertyData );

		$response = ( new GetProperty( $propertyDataRetriever ) )->execute(
			new GetPropertyRequest( "$propertyId" )
		);

		$this->assertSame( $expectedPropertyData, $response->getPropertyData() );
	}

}
