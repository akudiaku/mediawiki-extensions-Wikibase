<?php

/**
 * Definition of entity types for use with Wikibase.
 * The array returned by the code below is supposed to be merged into $wgWBRepoEntityTypes.
 * It defines the views used by the repo to display entities of different types.
 *
 * @note: Keep in sync with lib/WikibaseLib.entitytypes.php
 *
 * @note: This is bootstrap code, it is executed for EVERY request. Avoid instantiating
 * objects or loading classes here!
 *
 * @see docs/entiytypes.wiki
 *
 * @licence GNU GPL v2+
 * @author Bene* < benestar.wikimedia@gmail.com >
 */

use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookup;
use Wikibase\LanguageFallbackChain;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\View\EditSectionGenerator;

return array(
	'item' => array(
		'view-factory-callback' => function(
			$languageCode,
			LabelDescriptionLookup $labelDescriptionLookup,
			LanguageFallbackChain $fallbackChain,
			EditSectionGenerator $editSectionGenerator
		) {
			$viewFactory = WikibaseRepo::getDefaultInstance()->getViewFactory();
			return $viewFactory->newItemView(
				$languageCode,
				$labelDescriptionLookup,
				$fallbackChain,
				$editSectionGenerator
			);
		}
	),
	'property' => array(
		'view-factory-callback' => function(
			$languageCode,
			LabelDescriptionLookup $labelDescriptionLookup,
			LanguageFallbackChain $fallbackChain,
			EditSectionGenerator $editSectionGenerator
		) {
			$viewFactory = WikibaseRepo::getDefaultInstance()->getViewFactory();
			return $viewFactory->newPropertyView(
				$languageCode,
				$labelDescriptionLookup,
				$fallbackChain,
				$editSectionGenerator
			);
		}
	)
);
