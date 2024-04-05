<?php declare( strict_types=1 );

namespace Wikibase\Repo\RestApi\Application\UseCases\PatchProperty;

use Wikibase\Repo\RestApi\Application\Serialization\PropertyDeserializer;
use Wikibase\Repo\RestApi\Application\Serialization\PropertySerializer;
use Wikibase\Repo\RestApi\Application\UseCases\AssertPropertyExists;
use Wikibase\Repo\RestApi\Application\UseCases\AssertUserIsAuthorized;
use Wikibase\Repo\RestApi\Application\UseCases\ConvertArrayObjectsToArray;
use Wikibase\Repo\RestApi\Application\UseCases\PatchJson;
use Wikibase\Repo\RestApi\Application\UseCases\UseCaseError;
use Wikibase\Repo\RestApi\Domain\Model\EditMetadata;
use Wikibase\Repo\RestApi\Domain\Model\PropertyEditSummary;
use Wikibase\Repo\RestApi\Domain\Services\PropertyRetriever;
use Wikibase\Repo\RestApi\Domain\Services\PropertyUpdater;
use Wikibase\Repo\RestApi\Domain\Services\PropertyWriteModelRetriever;

/**
 * @license GPL-2.0-or-later
 */
class PatchProperty {

	private PatchPropertyValidator $validator;
	private AssertPropertyExists $assertPropertyExists;
	private AssertUserIsAuthorized $assertUserIsAuthorized;
	private PropertyRetriever $propertyRetriever;
	private PropertySerializer $propertySerializer;
	private PatchJson $patchJson;
	private PropertyDeserializer $propertyDeserializer;
	private PropertyUpdater $propertyUpdater;
	private PropertyWriteModelRetriever $propertyRetrieverWriteModel;

	public function __construct(
		PatchPropertyValidator $validator,
		AssertPropertyExists $assertPropertyExists,
		AssertUserIsAuthorized $assertUserIsAuthorized,
		PropertyRetriever $propertyRetriever,
		PropertySerializer $propertySerializer,
		PatchJson $patchJson,
		PropertyDeserializer $propertyDeserializer,
		PropertyUpdater $propertyUpdater,
		PropertyWriteModelRetriever $propertyRetrieverWriteModel
	) {
		$this->validator = $validator;
		$this->assertPropertyExists = $assertPropertyExists;
		$this->assertUserIsAuthorized = $assertUserIsAuthorized;
		$this->propertyRetriever = $propertyRetriever;
		$this->propertySerializer = $propertySerializer;
		$this->patchJson = $patchJson;
		$this->propertyDeserializer = $propertyDeserializer;
		$this->propertyUpdater = $propertyUpdater;
		$this->propertyRetrieverWriteModel = $propertyRetrieverWriteModel;
	}

	/**
	 * @throws UseCaseError
	 */
	public function execute( PatchPropertyRequest $request ): PatchPropertyResponse {
		$deserializedRequest = $this->validator->validateAndDeserialize( $request );
		$propertyId = $deserializedRequest->getPropertyId();
		$providedMetadata = $deserializedRequest->getEditMetadata();

		$this->assertPropertyExists->execute( $propertyId );

		$this->assertUserIsAuthorized->checkEditPermissions(
			$deserializedRequest->getPropertyId(),
			$providedMetadata->getUser()
		);

		$patchedProperty = $this->propertyDeserializer->deserialize(
			$this->patchJson->execute(
				ConvertArrayObjectsToArray::execute(
					$this->propertySerializer->serialize(
						// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
						$this->propertyRetriever->getProperty( $propertyId )
					)
				),
				$deserializedRequest->getPatch()
			),
		);
		$originalProperty = $this->propertyRetrieverWriteModel->getPropertyWriteModel( $propertyId );

		$propertyRevision = $this->propertyUpdater->update(
			$patchedProperty,
			new EditMetadata(
				$providedMetadata->getTags(),
				$providedMetadata->isBot(),
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
				PropertyEditSummary::newPatchSummary( $providedMetadata->getComment(), $originalProperty, $patchedProperty )
			)
		);

		return new PatchPropertyResponse(
			$propertyRevision->getProperty(),
			$propertyRevision->getLastModified(),
			$propertyRevision->getRevisionId()
		);
	}

}
