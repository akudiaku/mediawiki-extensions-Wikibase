<?php declare( strict_types=1 );

namespace Wikibase\Repo\RestApi\Application\UseCases\PatchPropertyLabels;

use LogicException;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Term\Term;
use Wikibase\DataModel\Term\TermList;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Application\Validation\LabelsSyntaxValidator;
use Wikibase\Repo\RestApi\Application\Validation\LanguageCodeValidator;
use Wikibase\Repo\RestApi\Application\Validation\PropertyLabelsContentsValidator;
use Wikibase\Repo\RestApi\Application\Validation\PropertyLabelValidator;
use Wikibase\Repo\RestApi\Application\Validation\ValidationError;

/**
 * @license GPL-2.0-or-later
 */
class PatchedLabelsValidator {

	private LabelsSyntaxValidator $syntaxValidator;
	private PropertyLabelsContentsValidator $contentsValidator;

	public function __construct( LabelsSyntaxValidator $syntaxValidator, PropertyLabelsContentsValidator $contentsValidator ) {
		$this->syntaxValidator = $syntaxValidator;
		$this->contentsValidator = $contentsValidator;
	}

	/**
	 * @throws UseCaseError
	 */
	public function validateAndDeserialize( PropertyId $propertyId, TermList $originalLabels, array $labelsSerialization ): TermList {
		$error = $this->syntaxValidator->validate( $labelsSerialization ) ?:
			$this->contentsValidator->validate(
				$this->syntaxValidator->getPartiallyValidatedLabels(),
				$propertyId,
				$this->getModifiedLanguages( $originalLabels, $this->syntaxValidator->getPartiallyValidatedLabels() )
			);

		if ( $error ) {
			$this->throwUseCaseError( $error );
		}

		return $this->contentsValidator->getValidatedLabels();
	}

	private function getModifiedLanguages( TermList $original, TermList $modified ): array {
		return array_keys( array_filter(
			iterator_to_array( $modified ),
			fn( Term $label ) => !$original->hasTermForLanguage( $label->getLanguageCode() ) ||
				!$original->getByLanguage( $label->getLanguageCode() )->equals( $label )
		) );
	}

	/**
	 * @return never
	 */
	private function throwUseCaseError( ValidationError $validationError ): void {
		$context = $validationError->getContext();
		switch ( $validationError->getCode() ) {
			case LanguageCodeValidator::CODE_INVALID_LANGUAGE_CODE:
				$languageCode = $validationError->getContext()[LanguageCodeValidator::CONTEXT_LANGUAGE_CODE];
				throw new UseCaseError(
					UseCaseError::PATCHED_LABEL_INVALID_LANGUAGE_CODE,
					"Not a valid language code '$languageCode' in changed labels",
					[ UseCaseError::CONTEXT_LANGUAGE => $languageCode ]
				);
			case LabelsSyntaxValidator::CODE_EMPTY_LABEL:
				$languageCode = $validationError->getContext()[LabelsSyntaxValidator::CONTEXT_FIELD_LANGUAGE];
				throw new UseCaseError(
					UseCaseError::PATCHED_LABEL_EMPTY,
					"Changed label for '$languageCode' cannot be empty",
					[ UseCaseError::CONTEXT_LANGUAGE => $languageCode ]
				);
			case LabelsSyntaxValidator::CODE_INVALID_LABEL_TYPE:
			case PropertyLabelValidator::CODE_INVALID:
				$language = $context[PropertyLabelValidator::CONTEXT_LANGUAGE];
				$value = $context[PropertyLabelValidator::CONTEXT_LABEL];
				$stringValue = is_string( $value ) ? $value : json_encode( $value );
				throw new UseCaseError(
					UseCaseError::PATCHED_LABEL_INVALID,
					"Changed label for '{$language}' is invalid: {$stringValue}",
					[ UseCaseError::CONTEXT_LANGUAGE => $language, UseCaseError::CONTEXT_VALUE => $stringValue ]
				);
			case PropertyLabelValidator::CODE_TOO_LONG:
				$maxLabelLength = $context[PropertyLabelValidator::CONTEXT_LIMIT];
				$language = $context[PropertyLabelValidator::CONTEXT_LANGUAGE];
				throw new UseCaseError(
					UseCaseError::PATCHED_LABEL_TOO_LONG,
					"Changed label for '{$language}' must not be more than $maxLabelLength characters long",
					[
						UseCaseError::CONTEXT_LANGUAGE => $context[PropertyLabelValidator::CONTEXT_LANGUAGE],
						UseCaseError::CONTEXT_VALUE => $context[PropertyLabelValidator::CONTEXT_LABEL],
						UseCaseError::CONTEXT_CHARACTER_LIMIT => $context[PropertyLabelValidator::CONTEXT_LIMIT],
					]
				);
			case PropertyLabelValidator::CODE_LABEL_DUPLICATE:
				$language = $context[PropertyLabelValidator::CONTEXT_LANGUAGE];
				$label = $context[PropertyLabelValidator::CONTEXT_LABEL];
				$matchingPropertyId = $context[PropertyLabelValidator::CONTEXT_MATCHING_PROPERTY_ID];
				throw new UseCaseError(
					UseCaseError::PATCHED_PROPERTY_LABEL_DUPLICATE,
					"Property $matchingPropertyId already has label '$label' associated with " .
					"language code '$language'",
					[
						UseCaseError::CONTEXT_LANGUAGE => $language,
						UseCaseError::CONTEXT_LABEL => $label,
						UseCaseError::CONTEXT_MATCHING_PROPERTY_ID => $matchingPropertyId,
					]
				);
			case PropertyLabelValidator::CODE_LABEL_DESCRIPTION_EQUAL:
				$language = $context[PropertyLabelValidator::CONTEXT_LANGUAGE];
				throw new UseCaseError(
					UseCaseError::PATCHED_PROPERTY_LABEL_DESCRIPTION_SAME_VALUE,
					"Label and description for language code {$language} can not have the same value.",
					[ UseCaseError::CONTEXT_LANGUAGE => $context[PropertyLabelValidator::CONTEXT_LANGUAGE] ]
				);
			default:
				throw new LogicException( "Unknown validation error: {$validationError->getCode()}" );
		}
	}
}
