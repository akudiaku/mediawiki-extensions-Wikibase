<?php declare( strict_types=1 );

namespace Wikibase\Repo\RestApi\Application\UseCases\AddPropertyStatement;

use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\RestApi\Application\UseCases\AssertPropertyExists;
use Wikibase\Repo\RestApi\Application\UseCases\AssertUserIsAuthorized;
use Wikibase\Repo\RestApi\Domain\Model\EditMetadata;
use Wikibase\Repo\RestApi\Domain\Model\StatementEditSummary;
use Wikibase\Repo\RestApi\Domain\Services\PropertyRetriever;
use Wikibase\Repo\RestApi\Domain\Services\PropertyUpdater;

/**
 * @license GPL-2.0-or-later
 */
class AddPropertyStatement {

	private AddPropertyStatementValidator $validator;
	private AssertPropertyExists $assertPropertyExists;
	private PropertyRetriever $propertyRetriever;
	private PropertyUpdater $propertyUpdater;
	private GuidGenerator $guidGenerator;
	private AssertUserIsAuthorized $assertUserIsAuthorized;

	public function __construct(
		AddPropertyStatementValidator $validator,
		AssertPropertyExists $assertPropertyExists,
		PropertyRetriever $propertyRetriever,
		GuidGenerator $guidGenerator,
		PropertyUpdater $propertyUpdater,
		AssertUserIsAuthorized $assertUserIsAuthorized
	) {
		$this->validator = $validator;
		$this->assertPropertyExists = $assertPropertyExists;
		$this->propertyRetriever = $propertyRetriever;
		$this->guidGenerator = $guidGenerator;
		$this->propertyUpdater = $propertyUpdater;
		$this->assertUserIsAuthorized = $assertUserIsAuthorized;
	}

	public function execute( AddPropertyStatementRequest $request ): AddPropertyStatementResponse {
		$this->validator->assertValidRequest( $request );

		$propertyId = new NumericPropertyId( $request->getPropertyId() );

		$this->assertPropertyExists->execute( $propertyId );
		$this->assertUserIsAuthorized->execute( $propertyId, $request->getUsername() );

		$property = $this->propertyRetriever->getProperty( $propertyId );
		$statement = $this->validator->getValidatedStatement();

		$newStatementGuid = $this->guidGenerator->newStatementId( $propertyId );
		$statement->setGuid( (string)$newStatementGuid );

		$property->getStatements()->addStatement( $statement );

		$revision = $this->propertyUpdater->update(
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$property,
			new EditMetadata(
				$request->getEditTags(),
				$request->isBot(),
				StatementEditSummary::newAddSummary( $request->getComment(), $statement )
			)
		);

		return new AddPropertyStatementResponse(
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$revision->getProperty()->getStatements()->getStatementById( $newStatementGuid ),
			$revision->getLastModified(),
			$revision->getRevisionId()
		);
	}

}
