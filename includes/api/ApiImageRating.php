<?php
/**
 * ImageRating API module
 *
 * @file
 * @ingroup API
 * @see https://www.mediawiki.org/wiki/API:Extensions#ApiSampleApiExtension.php
 */
class ApiImageRating extends ApiBase {

	/**
	 * Main entry point.
	 */
	public function execute() {
		$user = $this->getUser();
		// Ensure that we're allowed to do this
		if ( !$user->isAllowed( 'rateimage' ) ) {
			$this->dieUsageMsg( 'noedit' );
		}

		// Get the request parameters
		$params = $this->extractRequestParams();

		$pageId = $params['pageId'];
		// Ensure that the pageId parameter is present and that it really is numeric
		if ( !$pageId || $pageId === null || !is_numeric( $pageId ) ) {
			$this->dieUsageMsg( 'missingparam' );
		}

		// Need at least one category to add...
		if ( !$params['categories'] || $params['categories'] === null || empty( $params['categories'] ) ) {
			$this->dieUsageMsg( 'missingparam' );
		}

		// Delicious <copypasta> from /includes/api/ApiEditPage.php
		$titleObj = Title::newFromId( $pageId );
		// Now let's check whether we're even allowed to do this
		$errors = $titleObj->getUserPermissionsErrors( 'edit', $user );
		if ( !$titleObj->exists() ) {
			$errors = array_merge( $errors, $titleObj->getUserPermissionsErrors( 'create', $user ) );
		}
		if ( count( $errors ) ) {
			if ( is_array( $errors[0] ) ) {
				switch ( $errors[0][0] ) {
					case 'blockedtext':
						$this->dieUsage(
							'You have been blocked from editing',
							'blocked',
							0,
							[ 'blockinfo' => ApiQueryUserInfo::getBlockInfo( $user->getBlock() ) ]
						);
						break;
					case 'autoblockedtext':
						$this->dieUsage(
							'Your IP address has been blocked automatically, because it was used by a blocked user',
							'autoblocked',
							0,
							[ 'blockinfo' => ApiQueryUserInfo::getBlockInfo( $user->getBlock() ) ]
						);
						break;
					default:
						$this->dieUsageMsg( $errors[0] );
				}
			} else {
				$this->dieUsageMsg( $errors[0] );
			}
		}
		// End delicious </copypasta>

		// Top level
		$this->getResult()->addValue( null, $this->getModuleName(),
			array( 'result' => self::addImageCategory( $pageId, $params['categories'] ) )
		);

		return true;
	}

	/**
	 * Add the given category or categories to a page.
	 *
	 * @param int $pageId Internal identifier of the page that we're editing
	 * @param string $categories URL-encoded categories, each category separated by a comma
	 * @return string 'ok' if everything went well, 'busy' if the article has been edited in the last 2 seconds and we didn't edit it
	 */
	public static function addImageCategory( $pageId, $categories ) {
		global $wgContLang;

		$categories = urldecode( $categories );

		// Construct page title object
		$imagePage = Title::newFromID( $pageId );
		$article = new Article( $imagePage );

		// Check if it's been edited in last 2 seconds: want to delay the edit
		$timeSinceEdited = wfTimestamp( TS_MW, 0 ) - $article->getTimestamp();
		if ( $timeSinceEdited <= 2 ) {
			return 'busy';
		}

		// Get current page text
		// @todo Article#getContent() is deprecated since MW 1.21, should use
		// WikiPage#getContent() instead (because Article#getContentObject() is
		// protected)
		$pageText = $article->getContent();

		// Append new categories
		$categoriesArray = explode( ',', $categories );
		$categoryText = '';
		foreach ( $categoriesArray as $category ) {
			$category = trim( $category );
			$namespace = $wgContLang->getNsText( NS_CATEGORY );
			$ctg = wfMessage( 'imagerating-category', $category )->inContentLanguage()->parse();
			$tag = "[[{$namespace}:{$ctg}]]";
			if ( strpos( $pageText, $tag ) === false ) {
				$categoryText .= "\n{$tag}";
			}
		}
		$newText = $pageText . $categoryText;

		// Make page edit
		$content = ContentHandler::makeContent( $newText, $imagePage );
		$article->doEditContent( $content, wfMessage( 'imagerating-edit-summary' )->inContentLanguage()->text() );

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
		return array(
			'pageId' => array(
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true
			),
			'categories' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			)
		);
	}

	/**
	 * @see ApiBase::getExamplesMessages()
	 */
	protected function getExamplesMessages() {
		return array(
			'action=imagerating&pageId=66&categories=Cute%20cats,Lolcats,Internet%20memes' => 'apihelp-imagerating-example-1'
		);
	}
}
