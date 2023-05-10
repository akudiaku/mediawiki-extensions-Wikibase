<?php declare( strict_types=1 );

namespace Wikibase\Repo\RestApi\Infrastructure;

use Wikibase\DataModel\Entity\Item;
use Wikibase\Repo\RestApi\Application\Validation\ItemLabelTextValidator;
use Wikibase\Repo\RestApi\Application\Validation\ValidationError;
use Wikibase\Repo\Validators\TermValidatorFactory;

/**
 * @license GPL-2.0-or-later
 */
class TermValidatorFactoryLabelTextValidator implements ItemLabelTextValidator {

	private TermValidatorFactory $termValidatorFactory;

	public function __construct( TermValidatorFactory $termValidatorFactory ) {
		$this->termValidatorFactory = $termValidatorFactory;
	}

	public function validate( string $labelText ): ?ValidationError {
		$result = $this->termValidatorFactory
			->getLabelValidator( Item::ENTITY_TYPE )
			->validate( $labelText );
		if ( !$result->isValid() ) {
			$error = $result->getErrors()[0];
			switch ( $error->getCode() ) {
				case 'label-too-short':
					return new ValidationError( self::CODE_EMPTY );
				case 'label-too-long':
					return new ValidationError(
						self::CODE_TOO_LONG,
						[
							self::CONTEXT_VALUE => $labelText,
							self::CONTEXT_LIMIT => $error->getParameters()[0],
						]
					);
				default:
					return new ValidationError(
						self::CODE_INVALID,
						[ self::CONTEXT_VALUE => $labelText ]
					);
			}
		}

		return null;
	}

}
