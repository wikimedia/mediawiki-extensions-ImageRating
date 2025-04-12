<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * ImageRating API module
 *
 * @file
 * @ingroup API
 * @see https://www.mediawiki.org/wiki/API:Extensions#ApiSampleApiExtension.php
 */
class ApiImageRating extends MediaWiki\Api\ApiBase {

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$user = $this->getUser();
		// Ensure that we're allowed to do this
		if ( !$user->isAllowed( 'rateimage' ) ) {
			$this->dieWithError( 'apierror-noedit', 'noedit' );
		}

		// Get the request parameters
		$params = $this->extractRequestParams();

		$pageId = $params['pageId'];
		// Ensure that the pageId parameter is present and that it really is numeric
		if ( !$pageId || !is_numeric( $pageId ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'pageId' ], 'missingparam' );
		}

		// Need at least one category to add...
		if ( !$params['categories'] || $params['categories'] === null || empty( $params['categories'] ) ) {
			$this->dieWithError( [ 'apierror-missingparam', 'categories' ], 'missingparam' );
		}

		// Delicious <copypasta> from /includes/api/ApiEditPage.php
		$titleObj = Title::newFromId( $pageId );
		// Now let's check whether we're even allowed to do this
		$this->checkTitleUserPermissions(
			$titleObj,
			$titleObj->exists() ? 'edit' : [ 'edit', 'create' ]
		);
		// End delicious </copypasta>

		// Top level
		$this->getResult()->addValue( null, $this->getModuleName(),
			[ 'result' => $this->addImageCategory( $pageId, $params['categories'] ) ]
		);

		return true;
	}

	/**
	 * Add the given category or categories to a page.
	 *
	 * @param int $pageId Internal identifier of the page that we're editing
	 * @param string $categories URL-encoded categories, each category separated by a comma
	 * @return string 'ok' if everything went well, 'busy' if the article has been edited in the last 2 seconds and
	 *   we didn't edit it; 'error' if getting the page content text failed for whatever reason(s)
	 */
	public function addImageCategory( $pageId, $categories ) {
		$categories = urldecode( $categories );

		// Construct page title object
		$imagePage = Title::newFromID( $pageId );
		$services = MediaWikiServices::getInstance();
		$wp = $services->getWikiPageFactory()->newFromTitle( $imagePage );

		// Check if it's been edited in last 2 seconds: want to delay the edit
		$timeSinceEdited = (int)wfTimestamp( TS_MW, 0 ) - (int)$wp->getTimestamp();
		if ( $timeSinceEdited <= 2 ) {
			return 'busy';
		}

		// Get current page text
		$currentContent = $wp->getContent();
		if ( $currentContent !== null && $currentContent instanceof TextContent ) {
			$pageText = $currentContent->getText();
		} else {
			return 'error';
		}

		// Append new categories
		$categoriesArray = explode( ',', $categories );
		$categoryText = '';
		$linkedCategoriesForEditSummary = [];
		$contLang = $services->getContentLanguage();
		foreach ( $categoriesArray as $category ) {
			$category = trim( $category );
			$namespace = $contLang->getNsText( NS_CATEGORY );
			$ctg = $this->msg( 'imagerating-category', $category )->inContentLanguage()->parse();
			$tag = "[[{$namespace}:{$ctg}]]";
			if ( strpos( $pageText, $tag ) === false ) {
				$categoryText .= "\n{$tag}";
				$linkedCategoriesForEditSummary[] = $tag;
			}
		}
		$newText = $pageText . $categoryText;

		// Make page edit
		$content = ContentHandler::makeContent( $newText, $imagePage );
		$summary = $this->msg(
			'imagerating-edit-summary',
			count( $categoriesArray ),
			$contLang->commaList( $linkedCategoriesForEditSummary )
		)->inContentLanguage()->text();

		$wp->doUserEditContent( $content, $this->getUser(), $summary );

		return 'ok';
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	/**
	 * @return array
	 */
	public function getAllowedParams() {
		return [
			'pageId' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
			],
			'categories' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true
			]
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=imagerating&pageId=66&categories=Cute%20cats,Lolcats,Internet%20memes' => 'apihelp-imagerating-example-1'
		];
	}
}
