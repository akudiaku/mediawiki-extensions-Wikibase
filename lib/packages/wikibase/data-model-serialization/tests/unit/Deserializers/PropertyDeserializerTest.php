<?php

namespace Tests\Wikibase\DataModel\Deserializers;

use Wikibase\DataModel\Claim\Claim;
use Wikibase\DataModel\Claim\Claims;
use Wikibase\DataModel\Deserializers\PropertyDeserializer;
use Wikibase\DataModel\Entity\Property;
use Wikibase\DataModel\Snak\PropertyNoValueSnak;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Term\Fingerprint;

/**
 * @covers Wikibase\DataModel\Deserializers\PropertyDeserializer
 *
 * @licence GNU GPL v2+
 * @author Thomas Pellissier Tanon
 */
class PropertyDeserializerTest extends DeserializerBaseTest {

	public function buildDeserializer() {
		$entityIdDeserializerMock = $this->getMock( '\Deserializers\Deserializer' );

		$fingerprintDeserializerMock = $this->getMock( '\Deserializers\Deserializer' );
		$fingerprintDeserializerMock->expects( $this->any() )
			->method( 'deserialize' )
			->will( $this->returnValue( new Fingerprint() ) );

		$claim = new Statement( new Claim( new PropertyNoValueSnak( 42 ) ) );
		$claim->setGuid( 'test' );

		$claimsDeserializerMock = $this->getMock( '\Deserializers\Deserializer' );
		$claimsDeserializerMock->expects( $this->any() )
			->method( 'deserialize' )
			->with( $this->equalTo( array(
				'P42' => array(
					array(
						'mainsnak' => array(
							'snaktype' => 'novalue',
							'property' => 'P42'
						),
						'type' => 'statement',
						'rank' => 'normal'
					)
				)
			) ) )
			->will( $this->returnValue( new Claims( array( $claim ) ) ) );


		return new PropertyDeserializer( $entityIdDeserializerMock, $fingerprintDeserializerMock, $claimsDeserializerMock );
	}

	public function deserializableProvider() {
		return array(
			array(
				array(
					'type' => 'property'
				)
			),
		);
	}

	public function nonDeserializableProvider() {
		return array(
			array(
				5
			),
			array(
				array()
			),
			array(
				array(
					'type' => 'item'
				)
			),
		);
	}

	public function deserializationProvider() {
		$property = Property::newFromType( 'string' );

		$provider = array(
			array(
				$property,
				array(
					'type' => 'property',
					'datatype' => 'string'
				)
			),
		);

		$property = Property::newFromType( '' );
		$property->getStatements()->addNewStatement( new PropertyNoValueSnak( 42 ), null, null, 'test' );
		$provider[] = array(
			$property,
			array(
				'type' => 'property',
				'datatype' => '',
				'claims' => array(
					'P42' => array(
						array(
							'mainsnak' => array(
								'snaktype' => 'novalue',
								'property' => 'P42'
							),
							'type' => 'statement',
							'rank' => 'normal'
						)
					)
				)
			)
		);

		return $provider;
	}

}
