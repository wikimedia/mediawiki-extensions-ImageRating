<?php

class FeaturedImage {

	public static function registerHook( &$parser ) {
		$parser->setHook( 'featuredimage', [ 'FeaturedImage', 'renderFeaturedImage' ] );
		return true;
	}

	public static function renderFeaturedImage( $input, $args, Parser $parser ) {
		global $wgUser, $wgMemc;

		// Add CSS & JS -- the JS is needed if allowing voting inline
		if ( $wgUser->isAllowed( 'voteny' ) ) {
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

		// Set up memcached
		$key = $wgMemc->makeKey( 'image', 'featured', $width );
		$data = $wgMemc->get( $key );
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
					'page_id', 'page_title', 'img_user', 'img_user_text',
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
				$render_top_image = wfFindFile( $row->page_title );
				if ( is_object( $render_top_image ) ) {
					$thumb_top_image = $render_top_image->transform( [
						'width' => $width,
						'height' => 0
					] );

					$featured_image['image_name'] = $row->page_title;
					$featured_image['image_url'] = $image_title->getFullURL();
					$featured_image['page_id'] = $row->page_id;
					$featured_image['thumbnail'] = $thumb_top_image->toHtml();
					$featured_image['user_id'] = $row->img_user;
					$featured_image['user_name'] = $row->img_user_text;
				}
			}

			$wgMemc->set( $key, $featured_image, $cache_expires );
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

		$voteClassTop = new VoteStars( $featured_image['page_id'] );
		$countTop = $voteClassTop->count();

		$user_title = Title::makeTitle( NS_USER, $featured_image['user_name'] );
		$avatar = new wAvatar( $featured_image['user_id'], 'ml' );

		$safeImageURL = htmlspecialchars( $featured_image['image_url'] );
		$safeUserURL = htmlspecialchars( $user_title->getFullURL() );
		$safeUserName = htmlspecialchars( $featured_image['user_name'] );
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
							false
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