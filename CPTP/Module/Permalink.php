<?php


/**
 *
 * CPTP_Permalink
 *
 * Override Permalinks
 * @package Custom_Post_Type_Permalinks
 * @since 0.9.4
 *
 * */


class CPTP_Module_Permalink extends CPTP_Module {


	public function add_hook() {
		add_filter( 'post_type_link', array( $this,'post_type_link'), 10, 4 );
		add_filter( 'attachment_link', array( $this, 'attachment_link'), 20 , 2);
	}

	/**
	 *
	 * Fix permalinks output.
	 *
	 * @param String $post_link
	 * @param Object $post 投稿情報
	 * @param String $leavename 記事編集画面でのみ渡される
	 *
	 * @version 2.0
	 *
	 */
	public function post_type_link( $post_link, $post, $leavename ) {

		global $wp_rewrite;

		$draft_or_pending = isset( $post->post_status ) && in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ) );
		if( $draft_or_pending and !$leavename )
			return $post_link;


		$post_type = $post->post_type;
		$permalink = $wp_rewrite->get_extra_permastruct( $post_type );


		//親ページが有るとき。
		$parentsDirs = "";
		if( !$leavename ){
			$postId = $post->ID;
			while ($parent = get_post($postId)->post_parent) {
				$parentsDirs = get_post($parent)->post_name."/".$parentsDirs;
				$postId = $parent;
			}
		}

		$permalink = str_replace( '%'.$post_type.'%', $parentsDirs.'%'.$post_type.'%', $permalink );

		if( !$leavename ){
			$permalink = str_replace( '%'.$post_type.'%', $post->post_name, $permalink );
		}

		//%post_id%/attachment/%attachement_name%;
		//画像の編集ページでのリンク
		if( isset($_GET["post"]) && $_GET["post"] != $post->ID ) {
			$parent_structure = trim(get_option( $post->post_type.'_structure' ), "/");
			$parent_dirs = explode( "/", $parent_structure );
			if(is_array($parent_dirs)) {
				$last_dir = array_pop( $parent_dirs );
			}
			else {
				$last_dir = $parent_dirs;
			}

			if( "%post_id%" == $parent_structure or "%post_id%" == $last_dir ) {
				$permalink = $permalink."/attachment/";
			};
		}

		$search = array();
		$replace = array();

		$replace_tag = $this->create_taxonomy_replace_tag( $post->ID , $permalink );
		$search = $search + $replace_tag["search"];
		$replace = $replace + $replace_tag["replace"];


		//from get_permalink.
		$category = '';
		if ( strpos($permalink, '%category%') !== false ) {
			$cats = get_the_category($post->ID);
			if ( $cats ) {
				usort($cats, '_usort_terms_by_ID'); // order by ID
				$category_object = apply_filters( 'post_link_category', $cats[0], $cats, $post );
				$category_object = get_term( $category_object, 'category' );
				$category = $category_object->slug;
				if ( $parent = $category_object->parent )
					$category = get_category_parents($parent, false, '/', true) . $category;
			}
			// show default category in permalinks, without
			// having to assign it explicitly
			if ( empty($category) ) {
				$default_category = get_term( get_option( 'default_category' ), 'category' );
				$category = is_wp_error( $default_category ) ? '' : $default_category->slug;
			}
		}

		$author = '';
		if ( strpos($permalink, '%author%') !== false ) {
			$authordata = get_userdata($post->post_author);
			$author = $authordata->user_nicename;
		}
		//end from get_permalink.




		$post_date = strtotime( $post->post_date );
		$permalink = str_replace(array(
			'%post_id%',
			'%'.$post_type.'_slug%',
			"%year%",
			"%monthnum%",
			"%day%",
			"%hour%",
			"%minute%",
			"%second%",
			'%category%',
			'%author%'
		), array(
			$post->ID,
			get_post_type_object( $post_type )->rewrite['slug'],
			date("Y",$post_date),
			date("m",$post_date),
			date("d",$post_date),
			date("H",$post_date),
			date("i",$post_date),
			date("s",$post_date),
			$category,
			$author
		), $permalink );
		$permalink = str_replace($search, $replace, $permalink);
		$permalink = rtrim( home_url(),"/")."/".ltrim( $permalink ,"/" );

		return $permalink;
	}



	/**
	 *
	 * create %tax% -> term
	 *
	 * */
	private function create_taxonomy_replace_tag( $post_id , $permalink ) {
		$search = array();
		$replace = array();

		$taxonomies = CPTP_Util::get_taxonomies( true );

		//%taxnomomy% -> parent/child
		//運用でケアすべきかも。

		foreach ( $taxonomies as $taxonomy => $objects ) {
			$term = null;
			if ( strpos($permalink, "%$taxonomy%") !== false ) {
				$terms = wp_get_post_terms( $post_id, $taxonomy, array('orderby' => 'term_id'));

				if ( $terms and count($terms) > 1 ) {
					//親子カテゴリー両方にチェックが入っているとき。
					if(reset($terms)->parent == 0){

						$keys = array_keys($terms);
						$term = $terms[$keys[1]]->slug;
						if ( $terms[$keys[0]]->term_id == $terms[$keys[1]]->parent ) {
							$term = CPTP_Util::get_taxonomy_parents( $terms[$keys[1]]->parent,$taxonomy, false, '/', true ) . $term;
						}
					}else{
						$keys = array_keys($terms);
						$term = $terms[$keys[0]]->slug;
						if ( $terms[$keys[1]]->term_id == $terms[$keys[0]]->parent ) {
							$term = CPTP_Util::get_taxonomy_parents( $terms[$keys[0]]->parent,$taxonomy, false, '/', true ) . $term;
						}
					}
				}else if( $terms ){

					$term_obj = array_shift($terms);
					$term = $term_obj->slug;

					if(isset($term_obj->parent) and $term_obj->parent != 0) {
						$term = CPTP_Util::get_taxonomy_parents( $term_obj->parent,$taxonomy, false, '/', true ) . $term;
					}
				}

				if( isset($term) ) {
					$search[] = "%$taxonomy%";
					$replace[] = $term;
				}

			}
		}
		return array("search" => $search, "replace" => $replace );
	}



	/**
	 *
	 * fix attachment output
	 *
	 * @version 1.0
	 * @since 0.8.2
	 *
	 */

	public function attachment_link( $link , $postID ) {
		$post = get_post( $postID );
		if (!$post->post_parent){
			return $link;
		}
		$post_parent = get_post( $post->post_parent );
		$permalink = get_option( $post_parent->post_type.'_structure' );
		$post_type = get_post_type_object( $post_parent->post_type );

		if( $post_type->_builtin == false ) {
			if(strpos( $permalink, "%postname%" ) < strrpos( $permalink, "%post_id%" ) && strrpos( $permalink, "attachment/" ) === FALSE ){
				$link = str_replace($post->post_name , "attachment/".$post->post_name, $link);
			}
		}

		return $link;
	}


}
