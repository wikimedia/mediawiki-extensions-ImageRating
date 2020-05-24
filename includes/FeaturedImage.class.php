<?php

use MediaWiki\MediaWikiServices;

class FeaturedImage {

	/**
	 * Register the <featuredimage> tag with the parser.
	 *
	 * @param Parser &$parser
	 */
	public static function registerHook( &$parser ) {
		$parser->setHook( 'featuredimage', [ 'FeaturedImage', 'renderFeaturedImage' ] );
	}

	/**
	 * Callback for the <featuredimage> tag, which renders the output and returns it.
	 *
	 * @param string $input User-supplied input; if defined, the desired image width will be
	 *  extracted from this
	 * @param array $args Arguments passed to the hook (e.g. <featuredimage width="250" />);
	 *  "width" is the only supported argument and will be used if not supplied as $input
	 *  (but supplying width as the $input instead of in $args is super legacy behavior and
	 *  you shouldn't do that)
	 * @param Parser $parser
	 * @return string HTML
	 */
	public static function renderFeaturedImage( $input, $args, Parser $parser ) {
		// Add CSS & JS -- the JS is needed if allowing voting inline
		if ( $parser->getUser()->isAllowed( 'voteny' ) ) {
			$parser->getOutput()->addModules( 'ext.voteNY.scripts' );
		}
		$parser->getOutput()->addModuleStyles( [ 'ext.imagerating.css', 'ext.voteNY.styles' ] );

		$width = 250;
		// Get width property passed from hook
		if ( preg_match( "/^\s*width\s*=\s*(.*)/mi", $input, $matches ) ) {
			$width = htmlspecialchars( $matches[1], ENT_QUOTES );
		} elseif ( isset( $args['width'] ) && $args['width'] ) {
			$width = htmlspecialchars( $args['width'], ENT_QUOTES );
		}
		$width = intval( $width );

		// Set up cache
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$key = $cache->makeKey( 'image', 'featured', $width );
		$data = $cache->get( $key );
		$cache_expires = ( 60 * 30 );

		// No cache, load from the database
		if ( !$data ) {
			wfDebugLog( 'FeaturedImage', 'Loading featured image data from database' );

			// Only check images that are less than 30 days old
			// @todo This should be configurable, I think. --ashley, 15 January 2017
			$time = wfTimestamp( TS_MW, time() - ( 60 * 60 * 24 * 30 ) );

			$dbr = wfGetDB( DB_MASTER );
			$res_top = $dbr->select(
				[ 'page', 'image', 'Vote' ],
				[
					'page_id', 'page_title', 'img_actor',
					'AVG(vote_value) AS vote_avg',
					"(SELECT COUNT(*) FROM {$dbr->tableName( 'Vote' )} WHERE vote_page_id = page_id) AS vote_count",
				],
				[
					'page_id = vote_page_id',
					'page_namespace' => NS_FILE,
					"img_timestamp > {$time}"
				],
				__METHOD__,
				[
					'ORDER BY' => 'page_id DESC, vote_avg DESC, vote_count DESC',
					'LIMIT' => 1,
					'OFFSET' => 0
				]
			);

			$featured_image = [];

			if ( $dbr->numRows( $res_top ) > 0 ) {
				$row = $dbr->fetchObject( $res_top );

				$image_title = Title::makeTitle( NS_FILE, $row->page_title );
				$render_top_image = $services->getRepoGroup()->findFile( $row->page_title );
				if ( is_object( $render_top_image ) ) {
					$thumb_top_image = $render_top_image->transform( [
						'width' => $width,
						'height' => 0
					] );

					$featured_image['image_name'] = $row->page_title;
					$featured_image['image_url'] = $image_title->getFullURL();
					$featured_image['page_id'] = (int)$row->page_id;
					$featured_image['thumbnail'] = $thumb_top_image->toHtml();
					$featured_image['actor'] = (int)$row->img_actor;
				}
			}

			$cache->set( $key, $featured_image, $cache_expires );
		} else {
			wfDebugLog( 'FeaturedImage', 'Loading featured image data from cache' );
			$featured_image = $data;
		}

		if ( empty( $featured_image ) ) {
			// It can happen...better to return at this point and generate no
			// HTML here rather than to generate a gazillion notices about
			// undefined indexes and output HTML which is meaningless
			return '';
		}
		// @codingStandardsIgnoreStart
		'@phan-var array{image_name:string,image_url:string,page_id:int,thumbnail:string,actor:int} $featured_image';
		// @codingStandardsIgnoreEnd

		$voteClassTop = new VoteStars( $featured_image['page_id'], $parser->getUser() );
		$countTop = $voteClassTop->count();

		$user = User::newFromActorId( $featured_image['actor'] );
		if ( !$user || !$user instanceof User ) {
			return '';
		}

		$avatar = new wAvatar( $user->getId(), 'ml' );

		$safeImageURL = htmlspecialchars( $featured_image['image_url'] );
		$safeUserURL = htmlspecialchars( $user->getUserPage()->getFullURL() );
		$safeUserName = htmlspecialchars( $user->getName() );

		if ( !preg_match( '/<img/i', $featured_image['thumbnail'] ) ) {
			// Probably a MediaTransformError or somesuch, which should be rendered
			// as raw HTML without a link (<a><div>...</div></a> doesn't render that
			// well, you see)
			$img = $featured_image['thumbnail'];
		} else {
			// Normal case, which we should be hitting 99.9% of the time
			$img = "<a href=\"{$safeImageURL}\">{$featured_image['thumbnail']}</a>";
		}

		$output = "<div class=\"featured-image-main\">
				<div class=\"featured-image-container-main\">
					{$img}
				</div>
				<div class=\"featured-image-user-main\">
					<div class=\"featured-image-submitted-main\">
						<p>" . wfMessage( 'imagerating-submitted-by' )->plain() . "</p>
						<p><a href=\"{$safeUserURL}\">{$avatar->getAvatarURL()}
						{$safeUserName}</a></p>
					</div>

					<div class=\"image-rating-bar-main\">" .
						$voteClassTop->displayStars(
							$featured_image['page_id'],
							$voteClassTop->getAverageVote(),
							0
						) .
						"<div class=\"image-rating-score-main\" id=\"rating_{$featured_image['page_id']}\">" .
							wfMessage( 'imagerating-community-score', $voteClassTop->getAverageVote(), $countTop )->parse() .
						'</div>
					</div>
				</div>
				<div class="visualClear"></div>
		</div>';

		return $output;
	}
}
