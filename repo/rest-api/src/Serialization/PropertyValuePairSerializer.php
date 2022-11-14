<?php declare( strict_types=1 );

namespace Wikibase\Repo\RestApi\Serialization;

use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\Snak;

/**
 * @license GPL-2.0-or-later
 */
class PropertyValuePairSerializer {

	private PropertyDataTypeLookup $dataTypeLookup;

	public function __construct( PropertyDataTypeLookup $dataTypeLookup ) {
		$this->dataTypeLookup = $dataTypeLookup;
	}

	public function serialize( Snak $snak ): array {
		$propertyId = $snak->getPropertyId();
		$propertyValuePair = [
			'property' => [
				'id' => $propertyId->getSerialization(),
				'data-type' => $this->dataTypeLookup->getDataTypeIdForProperty( $propertyId )
			],
			'value' => [
				'type' => $snak->getType()
			]
		];

		if ( $snak instanceof PropertyValueSnak ) {
			$propertyValuePair['value']['content'] = $snak->getDataValue()->getArrayValue();
		}

		return $propertyValuePair;
	}

}