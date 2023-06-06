<?php

namespace Wikibase\DataModel\Tests\Entity;

use InvalidArgumentException;
use ReflectionClass;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Entity\SerializableEntityId;

/**
 * @coversNothing
 * @uses \Wikibase\DataModel\Entity\ItemId
 * @uses \Wikibase\DataModel\Entity\NumericPropertyId
 *
 * @group Wikibase
 * @group WikibaseDataModel
 *
 * @license GPL-2.0-or-later
 * @author Jeroen De Dauw < jeroendedauw@gmail.com >
 * @author John Erling Blad < jeblad@gmail.com >
 */
class EntityIdTest extends \PHPUnit\Framework\TestCase {

	public static function instanceProvider() {
		$ids = [];

		$ids[] = [ new ItemId( 'Q1' ), '' ];
		$ids[] = [ new ItemId( 'Q42' ), '' ];
		$ids[] = [ new ItemId( 'Q31337' ), '' ];
		$ids[] = [ new ItemId( 'Q2147483647' ), '' ];
		$ids[] = [ new NumericPropertyId( 'P101010' ), '' ];

		return $ids;
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testEqualsSimple( EntityId $id ) {
		$this->assertTrue( $id->equals( $id ) );
		$this->assertTrue( $id->equals( unserialize( serialize( $id ) ) ) );
		$this->assertFalse( $id->equals( $id->getSerialization() ) );
		$this->assertFalse( $id->equals( $id->getEntityType() ) );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testSerializationRoundtrip( EntityId $id ) {
		$this->assertEquals( $id, unserialize( serialize( $id ) ) );
	}

	public static function deserializationCompatibilityProvider(): array {
		return [
			'v05serialization' => [
				new ItemId( 'q123' ),
				'C:32:"Wikibase\DataModel\Entity\ItemId":15:{["item","Q123"]}',
			],
			'v07serialization' => [
				new ItemId( 'q123' ),
				'C:32:"Wikibase\DataModel\Entity\ItemId":4:{Q123}',
			],
			'2022-03 PHP 7.4+' => [
				new ItemId( 'q123' ),
				'O:32:"Wikibase\DataModel\Entity\ItemId":1:{s:13:"serialization";s:4:"Q123";}',
			],
		];
	}

	/**
	 * @dataProvider deserializationCompatibilityProvider
	 */
	public function testDeserializationCompatibility( $expected, $serialization ) {
		$this->assertEquals(
			$expected,
			unserialize( $serialization )
		);
	}

	/**
	 * This test will change when the serialization format changes.
	 * If it is being changed intentionally, the test should be updated.
	 * It is just here to catch unintentional changes.
	 */
	public function testSerializationStability() {
		$serialization = 'O:32:"Wikibase\DataModel\Entity\ItemId":1:{s:13:"serialization";s:4:"Q123";}';
		$id = new ItemId( 'q123' );

		$this->assertSame(
			serialize( $id ),
			$serialization
		);
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testReturnTypeOfToString( EntityId $id ) {
		$this->assertIsString( $id->__toString() );
	}

	public function testIsForeign() {
		$this->assertFalse( ( new ItemId( 'Q42' ) )->isForeign() );
		$this->assertFalse( ( new ItemId( ':Q42' ) )->isForeign() );
		$this->assertFalse( ( new NumericPropertyId( ':P42' ) )->isForeign() );
	}

	/**
	 * @dataProvider instanceProvider
	 */
	public function testGetRepositoryName( EntityId $id, $repoName ) {
		$this->assertSame( $repoName, $id->getRepositoryName() );
	}

	public static function serializationSplitProvider() {
		return [
			[ 'Q42', [ '', '', 'Q42' ] ],
		];
	}

	/**
	 * @dataProvider serializationSplitProvider
	 */
	public function testSplitSerialization( $serialization, $split ) {
		$this->assertSame( $split, SerializableEntityId::splitSerialization( $serialization ) );
	}

	/**
	 * @dataProvider invalidSerializationProvider
	 */
	public function testSplitSerializationFails_GivenInvalidSerialization( $serialization ) {
		$this->expectException( InvalidArgumentException::class );
		SerializableEntityId::splitSerialization( $serialization );
	}

	/**
	 * @dataProvider serializationSplitProvider
	 */
	public function testJoinSerialization( $serialization, $split ) {
		$this->assertSame( $serialization, SerializableEntityId::joinSerialization( $split ) );
	}

	/**
	 * @dataProvider invalidJoinSerializationDataProvider
	 */
	public function testJoinSerializationFails_GivenEmptyId( $parts ) {
		$this->expectException( InvalidArgumentException::class );
		SerializableEntityId::joinSerialization( $parts );
	}

	public static function invalidJoinSerializationDataProvider() {
		return [
			[ [ 'Q42', '', '' ] ],
			[ [ '', 'Q42', '' ] ],
			[ [ 'foo', 'Q42', '' ] ],
		];
	}

	public function testGivenNotNormalizedSerialization_splitSerializationReturnsNormalizedParts() {
		$this->assertSame( [ '', '', 'Q42' ], SerializableEntityId::splitSerialization( ':Q42' ) );
	}

	public static function localPartDataProvider() {
		return [
			[ 'Q42', 'Q42' ],
			[ ':Q42', 'Q42' ],
		];
	}

	/**
	 * @dataProvider localPartDataProvider
	 */
	public function testGetLocalPart( $serialization, $localPart ) {
		$id = new ItemId( $serialization );
		$this->assertSame( $localPart, $id->getLocalPart() );
	}

	public static function invalidSerializationProvider() {
		return [
			[ 's p a c e s:Q42' ],
			[ '::Q42' ],
			[ '' ],
			[ ':' ],
			[ 42 ],
			[ null ],
		];
	}

	/**
	 * @dataProvider invalidSerializationProvider
	 */
	public function testConstructor( $serialization ) {
		$mock = $this->getMockBuilder( SerializableEntityId::class )
			->disableOriginalConstructor()
			->getMockForAbstractClass();

		$constructor = ( new ReflectionClass( SerializableEntityId::class ) )->getConstructor();

		$this->expectException( InvalidArgumentException::class );
		$constructor->invoke( $mock, $serialization );
	}

}
