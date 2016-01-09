<?php
defined('is_running') or die('Not an entry point...');

class StaticGenerator{


	/**
	 * Regenerate all of the static content: gadget and feed
	 *
	 */
	static function Generate(){
		self::GenFeed();
		self::GenGadget();
		self::GenCategoryGadget();
		self::GenArchiveGadget();
	}


	/**
	 * Regenerate the atom.feed file
	 *
	 */
	static function GenFeed(){
		global $config, $addonFolderName, $dirPrefix;
		ob_start();

		$atomFormat = 'Y-m-d\TH:i:s\Z';
		$show_posts = SimpleBlogCommon::WhichPosts(0,SimpleBlogCommon::$data['feed_entries']);


		if( isset($_SERVER['HTTP_HOST']) ){
			$server = 'http://'.$_SERVER['HTTP_HOST'];
		}else{
			$server = 'http://'.$_SERVER['SERVER_NAME'];
		}
		$serverWithDir = $server.$dirPrefix;


		echo '<?xml version="1.0" encoding="utf-8"?>'."\n";
		echo '<feed xmlns="http://www.w3.org/2005/Atom">'."\n";
		echo '<title>'.$config['title'].'</title>'."\n";
		echo '<link href="'.$serverWithDir.'/data/_addondata/'.str_replace(' ', '%20',$addonFolderName).'/feed.atom" rel="self" />'."\n";
		echo '<link href="'.$server.common::GetUrl('Special_Blog').'" />'."\n";
		echo '<id>urn:uuid:'.self::uuid($serverWithDir).'</id>'."\n";
		echo '<updated>'.date($atomFormat, time()).'</updated>'."\n";
		echo '<author><name>'.$config['title'].'</name></author>'."\n";


		foreach($show_posts as $post_index){

			$post = SimpleBlogCommon::GetPostContent($post_index);

			if( !$post ){
				continue;
			}

			echo '<entry>'."\n";
			echo '<title>'.SimpleBlogCommon::Underscores( $post['title'] ).'</title>'."\n";
			echo '<link href="'.$server.SimpleBlogCommon::PostUrl($post_index).'"></link>'."\n";
			echo '<id>urn:uuid:'.self::uuid($post_index).'</id>'."\n";
			echo '<updated>'.date($atomFormat, $post['time']).'</updated>'."\n";

			$content =& $post['content'];
			if( (SimpleBlogCommon::$data['feed_abbrev']> 0) && (mb_strlen($content) > SimpleBlogCommon::$data['feed_abbrev']) ){
				$content = mb_substr($content,0,SimpleBlogCommon::$data['feed_abbrev']).' ... ';
				$label = gpOutput::SelectText('Read More');
				$content .= '<a href="'.$server.SimpleBlogCommon::PostUrl($post_index,$label).'">'.$label.'</a>';
			}

			//old images
			$replacement = $server.'/';
			$content = str_replace('src="/', 'src="'.$replacement,$content);

			//new images
			$content = str_replace('src="../', 'src="'.$serverWithDir,$content);

			//images without /index.php/
			$content = str_replace('src="./', 'src="'.$serverWithDir,$content);


			//href
			self::FixLinks($content,$server,0);

			echo '<summary type="html"><![CDATA['.$content.']]></summary>'."\n";
			echo '</entry>'."\n";

		}
		echo '</feed>'."\n";

		$feed = ob_get_clean();
		$feedFile = SimpleBlogCommon::$data_dir.'/feed.atom';
		gpFiles::Save($feedFile,$feed);
	}




	/**
	 * Regenerate the static content used to display the gadget
	 *
	 */
	static function GenGadget(){
		global $langmessage;

		$posts = array();
		$show_posts = SimpleBlogCommon::WhichPosts(0,SimpleBlogCommon::$data['gadget_entries']);


		ob_start();
		$label = gpOutput::SelectText('Blog');
		if( !empty($label) ){
			echo '<h3>';
			echo common::Link('Special_Blog',$label);
			echo '</h3>';
		}

		foreach($show_posts as $post_index){

			$post		= SimpleBlogCommon::GetPostContent($post_index);

			if( !$post ){
				continue;
			}

			$header		= '<b class="simple_blog_title">';
			$label		= SimpleBlogCommon::Underscores( $post['title'] );
			$header		.= SimpleBlogCommon::PostLink($post_index,$label);
			$header		.= '</b>';

			SimpleBlogCommon::BlogHead($header,$post_index,$post,true);


			$content = strip_tags($post['content']);

			if( SimpleBlogCommon::$data['gadget_abbrev'] > 6 && (mb_strlen($content) > SimpleBlogCommon::$data['gadget_abbrev']) ){

				$cut = SimpleBlogCommon::$data['gadget_abbrev'];

				$pos = mb_strpos($content,' ',$cut-5);
				if( ($pos > 0) && ($cut+20 > $pos) ){
					$cut = $pos;
				}
				$content = mb_substr($content,0,$cut).' ... ';

				$label = gpOutput::SelectText('Read More');
				$content .= SimpleBlogCommon::PostLink($post_index,$label);
			}

			echo '<p class="simple_blog_abbrev">';
			echo $content;
			echo '</p>';

		}

		if( SimpleBlogCommon::$data['post_count'] > 3 ){

			$label = gpOutput::SelectText('More Blog Entries');
			echo common::Link('Special_Blog',$label);
		}

		$gadget = ob_get_clean();
		$gadgetFile = SimpleBlogCommon::$data_dir.'/gadget.php';
		gpFiles::Save($gadgetFile,$gadget);
	}


	/**
	 * Regenerate the static content used to display the category gadget
	 *
	 */
	static function GenCategoryGadget(){
		global $addonPathData;

		$categories = SimpleBlogCommon::AStrToArray( 'categories' );

		ob_start();
		echo '<ul>';
		foreach($categories as $catindex => $catname){

			//skip hidden categories
			if( SimpleBlogCommon::AStrGet('categories_hidden',$catindex) ){
				continue;
			}

			$posts = SimpleBlogCommon::AStrToArray('category_posts_'.$catindex);
			$sum = count($posts);
			if( !$sum ){
				continue;
			}

			echo '<li>';
			echo '<a class="blog_gadget_link">'.$catname.' ('.$sum.')</a>';
			echo '<ul class="nodisplay">';
			foreach($posts as $post_id){
				$post_title = SimpleBlogCommon::AStrGet('titles',$post_id);
				echo '<li>';
				echo SimpleBlogCommon::PostLink( $post_id, $post_title );
				echo '</li>';
			}
			echo '</ul></li>';
		}
		echo '</ul>';

		$content = ob_get_clean();


		$gadgetFile = $addonPathData.'/gadget_categories.php';
		gpFiles::Save( $gadgetFile, $content );
	}


	/**
	 * Regenerate the static content used to display the archive gadget
	 *
	 */
	static function GenArchiveGadget(){
		global $addonPathData;

		//get list of posts and times
		$list = SimpleBlogCommon::AStrToArray( 'post_times' );
		if( !count($list) ) return;

		//get year counts
		$archive = array();
		foreach($list as $post_id => $time){
			$ym = date('Y-m',$time); //year&month
			$archive[$ym][] = $post_id;
		}


		ob_start();

		$prev_year = false;
		echo '<ul>';
		foreach( $archive as $ym => $posts ){
			$y = substr($ym,0,4);
			$m = substr($ym,-2);
			if( $y != $prev_year ){
				if( $prev_year !== false ){
					echo '</li>';
				}
				echo '<li><div class="simple_blog_gadget_year">'.$y.'</div>';
				$prev_year = $y;
			}
			$sum = count($posts);
			if( !$sum ){
				continue;
			}


			echo '<ul>';
			echo '<li><a class="blog_gadget_link">';
			$time = strtotime($ym.'-01');
			echo strftime('%B',$time);
			echo ' ('.$sum.')</a>';
			echo '<ul class="simple_blog_category_posts nodisplay">';
			foreach($posts as $post_id ){
				$post_title = SimpleBlogCommon::AStrGet('titles',$post_id);
				echo '<li>';
				echo SimpleBlogCommon::PostLink($post_id, $post_title );
				echo '</li>';
			}
			echo '</ul>';
			echo '</li>';
			echo '</ul>';
		}

		echo '</li></ul>';

		$content = ob_get_clean();

		$gadgetFile = $addonPathData.'/gadget_archive.php';
		gpFiles::Save( $gadgetFile, $content );
	}

	static function uuid($str){
		$chars = md5($str);
		return mb_substr($chars,0,8)
				.'-'. mb_substr($chars,8,4)
				.'-'. mb_substr($chars,12,4)
				.'-'. mb_substr($chars,16,4)
				.'-'. mb_substr($chars,20,12);
		return $uuid;
	}



	/**
	 * Change relative links to absolute links
	 *
	 * @param string $server
	 * @param integer $offset
	 */
	public static function FixLinks(&$content,$server,$offset){

		preg_match_all('#href="([^<>"]+)"#i',$content,$matches,PREG_SET_ORDER);
		foreach($matches as $match){
			self::FixLink($content,$server,$match);
		}

	}


	/**
	 * Change relative link to absolute link
	 *
	 */
	public static function FixLink(&$content,$server,$match){

		if( strpos($match[1],'mailto:') !== false ){
			return;
		}
		if( strpos($match[1],'://') !== false ){
			return;
		}

		if( mb_strpos($match[1],'/') === 0 ){
			$replacement = $server.$match[1];
		}else{
			$replacement = $server.common::GetUrl($match[1]);
		}

		$replacement = str_replace($match[1],$replacement,$match[0]);

		$content = str_replace($match[0],$replacement,$content);
	}


}
