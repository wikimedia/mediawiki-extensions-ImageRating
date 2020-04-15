<?php

use MediaWiki\MediaWikiServices;

/**
 * ImageRating extension - allows categorizing and rating images via a new
 * special page.
 *
 * Hard dependency: Vote (VoteNY) extension
 *
 * @file
 */
class ImageRating extends SpecialPage {

	/**
	 * Constructor -- set up the new, restricted special page
	 */
	public function __construct() {
		parent::__construct( 'ImageRating', 'rateimage' );
	}

	/**
	 * Group this special page under the correct header in Special:SpecialPages.
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'media';
	}

	/**
	 * Returns the name that goes in the \<h1\> in the special page itself, and
	 * also the name that will be listed in Special:SpecialPages
	 *
	 * @return string
	 */
	public function getDescription() {
		return $this->msg( 'imagerating-ratetitle' )->plain();
	}

	/**
	 * @see https://phabricator.wikimedia.org/T123591
	 * @return bool
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the page or null
	 */
	public function execute( $par ) {
		global $wgMemc;

		$lang = $this->getLanguage();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		// If the user isn't permitted to access this special page, display an error
		if ( !$user->isAllowed( 'rateimage' ) ) {
			throw new PermissionsError( 'rateimage' );
		}

		// Show a message if the database is in read-only mode
		$this->checkReadOnly();

		// Show a message if the user is blocked
		if ( $user->getBlock() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Set the page title etc. stuff
		$this->setHeaders();

		// Add required CSS & JS modules via ResourceLoader
		$out->addModuleStyles( 'ext.imagerating.css' );
		// You'd hope they have the sufficient rights to rate things if and when
		// they're on this page, but by default the voteny user right is given
		// only to 'user', not '*' so it might be that '*' has 'rateimage' right
		// but not 'voteny'. Possible, just unlikely and not ideal.
		$modules = [ 'ext.imagerating.js' ];
		if ( $user->isAllowed( 'voteny' ) ) {
			$modules[] = 'ext.voteNY.scripts';
		}
		$out->addModules( $modules );

		// Page
		$page = $request->getInt( 'page', 1 );
		$type = $request->getVal( 'type', ( $par ?: 'new' ) );
		$category = $request->getVal( 'category' );

		$tables = $where = $options = $joinConds = [];

		// SQL limit based on page
		$perPage = 5;
		$limit = $perPage;

		$offset = 0;
		if ( $page ) {
			$offset = $page * $limit - ( $limit );
		}
		$options['LIMIT'] = $limit;
		$options['OFFSET'] = $offset;

		// Database calls
		$dbr = wfGetDB( DB_REPLICA );

		if ( $category ) {
			$ctgTitle = Title::newFromText( $this->msg( 'imagerating-category', trim( $category ) )->inContentLanguage()->parse() );
			$ctgKey = $lang->uc( $ctgTitle->getDBkey() );
			$tables[] = 'categorylinks';
			$joinConds['categorylinks'] = [ 'INNER JOIN', 'cl_from = page_id' ];
			$where['UPPER(cl_to)'] = $ctgKey;
		}

		switch ( $type ) {
			case 'best':
				$res = $dbr->select(
					array_merge( [ 'page', 'Vote' ], $tables ),
					[
						'page_id', 'page_title',
						'AVG(vote_value) AS vote_avg',
						"(SELECT COUNT(*) FROM {$dbr->tableName( 'Vote' )} WHERE vote_page_id = page_id) AS vote_count",
					],
					[ 'page_namespace' => NS_FILE, 'page_id = vote_page_id' ] + $where,
					__METHOD__,
					[
						'ORDER BY' => 'vote_avg DESC, vote_count DESC'
					] + $options,
					$joinConds
				);
				$res_count = $dbr->select(
					array_merge( [ 'page', 'Vote' ], $tables ),
					[ 'COUNT(*) AS total_ratings' ],
					[ 'page_namespace' => NS_FILE ] + $where,
					__METHOD__,
					$options,
					[ 'Vote' => [ 'INNER JOIN', 'page_id = vote_page_id' ] ] + $joinConds
				);
				$row_count = $dbr->fetchObject( $res_count );
				$total = $row_count->total_ratings;
				if ( isset( $category ) && $category ) {
					$out->setPageTitle( $this->msg( 'imagerating-best-heading-param', $category ) );
				} else {
					$out->setPageTitle( $this->msg( 'imagerating-best-heading' ) );
				}
				break;

			case 'popular':
				$res = $dbr->select(
					array_merge( [ 'page', 'Vote' ], $tables ),
					[
						'page_id', 'page_title',
						'AVG(vote_value) AS vote_avg',
						"(SELECT COUNT(*) FROM {$dbr->tableName( 'Vote' )} WHERE vote_page_id = page_id) AS vote_count",
					],
					[ 'page_namespace' => NS_FILE ] + $where,
					__METHOD__,
					[
						'ORDER BY' => 'page_id DESC, vote_avg DESC, vote_count DESC',
						// the HAVING condition can't be in the WHERE clause
						'HAVING' => 'vote_count > 1'
					] + $options,
					[ 'Vote' => [ 'INNER JOIN', 'page_id = vote_page_id' ] ] + $joinConds
				);
				$res_count = $dbr->select(
					array_merge( [ 'page', 'Vote' ], $tables ),
					[ 'COUNT(*) AS total_ratings' ],
					[ 'page_namespace' => NS_FILE ] + $where,
					__METHOD__,
					$options,
					[
						'Vote' => [ 'INNER JOIN', 'page_id = vote_page_id' ]
					] + $joinConds
				);
				$row_count = $dbr->fetchObject( $res_count );
				$total = $row_count->total_ratings;
				if ( isset( $category ) && $category ) {
					$out->setPageTitle( $this->msg( 'imagerating-popular-heading-param', $category ) );
				} else {
					$out->setPageTitle( $this->msg( 'imagerating-popular-heading' ) );
				}
				break;

			case 'new':
			default:
				$res = $dbr->select(
					array_merge( [ 'page', 'Vote' ], $tables ),
					[ 'page_id', 'page_title', 'COUNT(vote_value)', 'AVG(vote_value) AS vote_avg' ],
					[ 'page_namespace' => NS_FILE, 'vote_page_id = page_id' ] + $where,
					__METHOD__,
					[
						/*
						[19:19:28]	<valhallasw>	ashley: the group by is needed for the avg because you need to tell mysql what the avg is over
						[19:19:37]	<valhallasw>	it's the average(vote) over (page_id, page_title)
						*/
						'GROUP BY' => 'page_id, page_title',
						'ORDER BY' => 'page_id DESC'
					] + $options,
					[
						'Vote' => [ 'LEFT JOIN', 'page_id = vote_page_id' ]
					] + $joinConds
				);
				$total = SiteStats::images();
				if ( isset( $category ) && $category ) {
					$out->setPageTitle( $this->msg( 'imagerating-new-heading-param', $category ) );
				} else {
					$out->setPageTitle( $this->msg( 'imagerating-new-heading' ) );
				}
				break;
		}

		// Variables
		$x = 1;
		$linkRenderer = $this->getLinkRenderer();
		$pageTitle = $this->getPageTitle();

		// Build navigation
		if ( isset( $category ) && $category ) {
			$menu = [
				$this->msg( 'imagerating-new-heading-param', $category )->parse() => 'new',
				$this->msg( 'imagerating-popular-heading-param', $category )->parse() => 'popular',
				$this->msg( 'imagerating-best-heading-param', $category )->parse() => 'best'
			];
		} else {
			$menu = [
				$this->msg( 'imagerating-new-heading' )->parse() => 'new',
				$this->msg( 'imagerating-popular-heading' )->parse() => 'popular',
				$this->msg( 'imagerating-best-heading' )->parse() => 'best'
			];
		}

		$output = '<div class="image-rating-menu">
			<h2>' . $this->msg( 'imagerating-menu-title' )->escaped() . '</h2>';

		foreach ( $menu as $title => $qs ) {
			if ( $type != $qs ) {
				$output .= '<p>' . $linkRenderer->makeLink(
					$pageTitle,
					new HtmlArmor( $title ),
					[],
					[ 'type' => $qs ] + ( ( $category ) ? [ 'category' => $category ] : [] )
				) . '<p>';
			} else {
				$output .= "<p><b>{$title}</b></p>";
			}
		}

		$upload_title = SpecialPage::getTitleFor( 'Upload' );
		$output .= '<p><b>' . $linkRenderer->makeLink(
			$upload_title,
			$this->msg( 'imagerating-upload-images' )->plain()
		) . '</b></p>';

		$output .= '</div>';

		$output .= '<div class="image-ratings">';

		/*
		// set up memcached
		$width = 250;
		$key = $wgMemc->makeKey( 'image', 'featured', "category:{$category}:width:{$width}" );
		$data = $wgMemc->get( $key );
		$cache_expires = ( 60 * 30 );

		// no cache, load from the database
		if ( !$data ) {
			wfDebugLog( 'ImageRating', 'Loading featured image data from database' );

			// Only check images that are less than 30 days old
			$time = wfTimestamp( TS_MW, time() - ( 60 * 60 * 24 * 30 ) );

			$dbr = wfGetDB( DB_REPLICA );
			$res_top = $dbr->select(
				array_merge( [ 'Vote', 'image', 'page' ], $tables ),
				[
					'page_id', 'page_title', 'img_actor',
					'AVG(vote_value) AS vote_avg',
					"(SELECT COUNT(*) FROM {$dbr->tableName( 'Vote' )} WHERE vote_page_id = page_id) AS vote_count",
				],
				[
					'page_id = vote_page_id',
					'img_name = page_title',
					'page_namespace' => NS_FILE
					"img_timestamp > {$time}"
				] + $where,
				__METHOD__,
				[
					'ORDER BY' => 'page_id DESC, vote_avg DESC, vote_count DESC',
					'LIMIT' => 1,
					'OFFSET' => 0
				],
				$joinConds
			);

			if ( $dbr->numRows( $res_top ) > 0 ) {
				$row = $dbr->fetchObject( $res_top );

				$image_title = Title::makeTitle( NS_FILE, $row->page_title );
				if ( method_exists( MediaWikiServices::class, 'getRepoGroup' ) ) {
					// MediaWiki 1.34+
					$render_top_image = MediaWikiServices::getInstance()->getRepoGroup()
						->findFile( $row->page_title );
				} else {
					$render_top_image = wfFindFile( $row->page_title );
				}
				if ( is_object( $render_top_image ) ) {
					$thumb_top_image = $render_top_image->transform( [
						'width' => $width,
						'height' => 0
					] );

					$featured_image['image_name'] = $row->page_title;
					$featured_image['image_url'] = $image_title->getFullURL();
					$featured_image['page_id'] = $row->page_id;
					$featured_image['thumbnail'] = $thumb_top_image->toHtml();
					$featured_image['actor'] = (int)$row->img_actor;
					$featured_image['vote_avg'] = $lang->formatNum( $row->vote_avg );
				}
			}
			$wgMemc->set( $key, $featured_image, $cache_expires );
		} else {
			wfDebugLog( 'ImageRating', 'Loading featured image data from cache' );
			$featured_image = $data;
		}

		if ( $featured_image['page_id'] ) {
			$imageUser = User::newFromActorId( $featured_image['actor'] );
			if ( !$imageUser || !$imageUser instanceof User ) {
				return '';
			}

			$userPageURL = htmlspecialchars( $imageUser->getUserPage()->getFullURL(), ENT_QUOTES );
			$safeUserName = htmlspecialchars( $imageUser->getName(), ENT_QUOTES );
			$avatar = new wAvatar( $imageUser->getId(), 'ml' );

			$voteClassTop = new VoteStars( $featured_image['page_id'], $user );
			$countTop = $voteClassTop->count();

			$output .= '<div class="featured-image">
				<h2>' . $this->msg( 'imagerating-featured-heading' )->plain() . "</h2>
					<div class=\"featured-image-container\">
						<a href=\"{$featured_image['image_url']}\">{$featured_image['thumbnail']}</a>
					</div>
					<div class=\"featured-image-user\">

						<div class=\"featured-image-submitted-main\">
							<p>" . $this->msg( 'imagerating-submitted-by' )->plain() . "</p>
							<p><a href=\"{$userPageURL}\">{$avatar->getAvatarURL()}
							{$safeUserName}</a></p>
						</div>

						<div class=\"image-rating-bar-main\">"
							. $voteClassTop->displayStars( $featured_image['page_id'], $featured_image['vote_avg'], false ) .
							"<div class=\"image-rating-score-main\" id=\"rating_{$featured_image['page_id']}\">" .
								$this->msg( 'imagerating-community-score', $featured_image['vote_avg'], $countTop )->parse() .
								'</div>
						</div>
					</div>
				<div class="visualClear"></div>
			</div>';
		}
		*/

		$output .= '<h2>' . $this->msg( 'imagerating-ratetitle' )->escaped() . '</h2>';

		$key = $wgMemc->makeKey( 'image', 'list', "type:{$type}:category:{$category}:per:{$perPage}", 'v2' );
		$data = $wgMemc->get( $key );
		if ( $data && $page == 0 ) {
			$imageList = $data;
			wfDebugLog( 'ImageRating', 'Cache hit for image rating list' );
		} else {
			wfDebugLog( 'ImageRating', 'Cache miss for image rating list' );
			$imageList = [];
			foreach ( $res as $row ) {
				$imageList[] = [
					'page_id' => $row->page_id,
					'page_title' => $row->page_title,
					'vote_avg' => $row->vote_avg
				];
			}
			// Cache the first page for a minute in memcached
			if ( $page == 1 ) {
				$wgMemc->set( $key, $imageList, 60 );
			}
		}

		if ( empty( $imageList ) ) {
			// Nothing to do? This is totally possible if no images have the vote
			// tag on them, such as when we're dealing with a brand new or an inactive
			// wiki...display a call to action message in such cases.
			$output .= $this->msg( 'imagerating-empty' )->parse();
			$renderPagination = false;
		} else {
			if ( method_exists( MediaWikiServices::class, 'getRepoGroup' ) ) {
				// MediaWiki 1.34+
				$repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
			} else {
				$repoGroup = RepoGroup::singleton();
			}
			$renderPagination = true;
			foreach ( $imageList as $image ) {
				$image_path = $image['page_title'];
				$image_id = $image['page_id'];
				$vote_avg = $image['vote_avg'];

				$render_image = $repoGroup->findFile( $image_path );
				$thumb_image = false;
				if ( is_object( $render_image ) ) {
					$thumb_image = $render_image->transform( [
						'width' => 120,
						'height' => 120
					] );
				}
				$thumbnail = '';
				if ( is_object( $thumb_image ) ) {
					$thumbnail = $thumb_image->toHtml();
				}

				$voteClass = new VoteStars( $image_id, $user );
				$count = $voteClass->count();

				if ( $x !== $perPage ) {
					$output .= '<div class="image-rating-row">';
				} else {
					$output .= '<div class="image-rating-row-bottom">';
				}

				$output .= '<div class="image-rating-container">
					<div class="image-for-rating">
						<a href="' . htmlspecialchars( Title::makeTitle( NS_FILE, $image_path )->getFullURL(), ENT_QUOTES ) . '">' .
							$thumbnail .
						'</a>
					</div>

					<div class="image-rating-bar">' .
						$voteClass->displayStars( $image_id, (int)$vote_avg, 0 ) .
						"<div class=\"image-rating-score\" id=\"rating_{$image_id}\">" .
							$this->msg( 'imagerating-community-score', $lang->formatNum( $vote_avg ), $count )->parse() .
						'</div>
					</div>
				</div>';

				$res_category = $dbr->select(
					'categorylinks',
					[ 'cl_to', 'cl_sortkey', 'cl_from' ],
					[ 'cl_from' => $image_id ],
					__METHOD__
				);
				$category_total = $dbr->numRows( $res_category );
				$output .= "<div id=\"image-categories-container-{$image_id}\" class=\"image-categories-container\">
					<h2>" . $this->msg( 'imagerating-categorytitle' )->escaped() . '</h2>';

				$per_row = 3;
				$category_x = 1;

				foreach ( $res_category as $row_category ) {
					$image_category = str_replace( '_', ' ', $row_category->cl_to );
					$category_id = "category-button-{$image_id}-{$category_x}";

					$catURL = htmlspecialchars(
						Title::makeTitle( NS_CATEGORY, $row_category->cl_to )->getFullURL(),
						ENT_QUOTES
					);
					$output .= "<div class=\"category-button\" id=\"{$category_id}\" onclick=\"window.location='" . $catURL . "'\">
						{$image_category}
					</div>";

					if (
						$category_x == $category_total ||
						$category_x != 1 && $category_x % $per_row == 0
					) {
						$output .= '<div class="visualClear"></div>';
					}

					$category_x++;
				}

				$output .= "<div class=\"visualClear\" id=\"image-categories-container-end-{$image_id}\"></div>
					<div class=\"image-categories-add\">" .
						$this->msg( 'imagerating-add-categories-title' )->escaped() . "<br />
						<input type=\"text\" size=\"22\" id=\"category-{$image_id}\" />
						<input type=\"button\" value=\"" . $this->msg( 'imagerating-add-button' )->escaped() . '" class="site-button" />
					</div>
				</div>';

				$output .= '<div class="visualClear"></div>
			</div>';

				$x++;
			}
		}

		$output .= '</div>
		<div class="visualClear"></div>';

		// Build the pagination links
		if ( $renderPagination ) {
			$numOfPages = $total / $perPage;
			$prevLink = [
				'page' => ( $page - 1 ),
				'type' => $type
			] + ( ( $category ) ? [ 'category' => $category ] : [] );
			$nextLink = [
				'page' => ( $page + 1 ),
				'type' => $type
			] + ( ( $category ) ? [ 'category' => $category ] : [] );

			if ( $numOfPages > 1 ) {
				$output .= '<div class="rate-image-navigation">';
				if ( $page > 1 ) {
					$output .= $linkRenderer->makeLink(
						$pageTitle,
						$this->msg( 'imagerating-prev-link' )->plain(),
						[],
						$prevLink
					) . ' ';
				}

				if ( ( $total % $perPage ) != 0 ) {
					$numOfPages++;
				}
				if ( $numOfPages >= 9 && $page < $total ) {
					$numOfPages = 9 + $page;
				}
				if ( $numOfPages >= ( $total / $perPage ) ) {
					$numOfPages = ( $total / $perPage ) + 1;
				}

				for ( $i = 1; $i <= $numOfPages; $i++ ) {
					if ( $i == $page ) {
						$output .= ( $i . ' ' );
					} else {
						$output .= $linkRenderer->makeLink(
							$pageTitle,
							"$i",
							[],
							[
								'page' => $i,
								'type' => $type
							] + ( ( $category ) ? [ 'category' => $category ] : [] )
						) . ' ';
					}
				}

				if ( ( $total - ( $perPage * $page ) ) > 0 ) {
					$output .= ' ' . $linkRenderer->makeLink(
						$pageTitle,
						$this->msg( 'imagerating-next-link' )->plain(),
						[],
						$nextLink
					);
				}
				$output .= '</div>';
			}
		}

		$out->addHTML( $output );
	}
}
