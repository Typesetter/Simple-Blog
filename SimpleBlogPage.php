<?php
defined('is_running') or die('Not an entry point...');



class SimpleBlogPage{

	public $post_id;
	public $post;
	public $comment_saved		= false;
	public $comments_closed;

	public function __construct($post_id){
		$this->post_id			= $post_id;
		$this->post				= SimpleBlogCommon::GetPostContent($this->post_id);
		$this->comments_closed	= SimpleBlogCommon::AStrGet('comments_closed',$this->post_id);
	}


	/**
	 * Display the blog post
	 *
	 */
	public function ShowPost(){

		if( $this->post === false ){
			$this->Error_404();
			return;
		}

		if( !common::LoggedIn() && SimpleBlogCommon::AStrGet('drafts',$this->post_id) ){
			$this->Error_404();
			return;
		}


		$this->PostCommands();
		$this->_ShowPost();
	}

	public function _ShowPost(){
		global $page;

		$page->label = SimpleBlogCommon::Underscores( $this->post['title'] );

		$class = $id = '';
		if( common::LoggedIn() ){
			SimpleBlog::EditLinks($this->post_id, $class, $id);
		}

		echo '<div class="blog_post single_blog_item '.$class.'" '.$id.'>';

		//heading
		$header			= '<h2 id="blog_post_'.$this->post_id.'">';
		if( SimpleBlogCommon::AStrGet('drafts',$this->post_id) ){
			$header		.= '<span style="opacity:0.3;">';
			$header		.= gpOutput::SelectText('Draft');
			$header		.= '</span> ';
		}elseif( $this->post['time'] > time() ){
			$header .= '<span style="opacity:0.3;">';
			$header .= gpOutput::SelectText('Pending');
			$header .= '</span> ';
		}

		$header			.= SimpleBlogCommon::PostLink($this->post_id,$page->label);
		$header			.= '</h2>';
		SimpleBlogCommon::BlogHead($header,$this->post_id,$this->post);


		//content
		echo '<div class="twysiwygr">';
		echo $this->post['content'];
		echo '</div>';

		echo '</div>';

		echo '<br/>';

		echo '<div class="clear"></div>';

		$this->Categories();
		$this->PostLinks();
		$this->Comments();
	}


	/**
	 * Run commands
	 *
	 */
	public function PostCommands(){
		global $page;

		$cmd = common::GetCommand();

		if( empty($cmd) ){
			//redirect to correct url if needed
			SimpleBlogCommon::UrlQuery( $this->post_id, $expected_url, $query );
			$expected_url = str_replace('&amp;','&',$expected_url); //because of htmlspecialchars($cattitle)
			if( $page->requested != $expected_url ){
				$expected_url = common::GetUrl( $expected_url, $query, false );
				common::Redirect($expected_url);
			}
			return;
		}


		switch($cmd){
			case 'Add Comment':
				$this->AddComment();
			break;
		}

	}


	/**
	 * Ouptut blog categories
	 *
	 */
	public function Categories(){

		//blog categories
		if( empty($this->post['categories']) ){
			return;
		}

		$temp = array();
		foreach($this->post['categories'] as $catindex){
			$title = SimpleBlogCommon::AStrGet( 'categories', $catindex );
			if( !$title ){
				continue;
			}
			if( SimpleBlogCommon::AStrGet('categories_hidden',$catindex) ){
				continue;
			}
			$temp[] = SimpleBlogCommon::CategoryLink($catindex, $title, $title);
		}

		if( count($temp) ){
			echo '<div class="category_container">';
			echo '<b>';
			echo gpOutput::GetAddonText('Categories');
			echo ':</b> ';
			echo implode(', ',$temp);
			echo '</div>';
		}
	}


	/**
	 * Output blog comments
	 *
	 */
	public function Comments(){

		//comments
		if( !SimpleBlogCommon::$data['allow_comments'] ){
			return;
		}

		echo '<div class="comment_container">';
		$this->ShowComments();
		$this->CommentForm();
		echo '</div>';

	}


	/**
	 * Show the comments for a single blog post
	 *
	 */
	public function ShowComments(){

		$data = SimpleBlogCommon::GetCommentData($this->post_id);
		if( empty($data) ){
			return;
		}

		echo '<h3>';
		echo gpOutput::GetAddonText('Comments');
		echo '</h3>';

		$this->GetCommentHtml($data);

	}



	/**
	 * Add a comment to the comment data for a post
	 *
	 */
	public function AddComment(){
		global $langmessage;

		if( $this->comments_closed ){
			return;
		}

		//need a captcha?
		if( SimpleBlogCommon::$data['comment_captcha'] && gp_recaptcha::isActive() ){

			if( !isset($_POST['anti_spam_submitted']) ){
				return false;

			}elseif( !gp_recaptcha::Check() ){
				return false;

			}
		}

		$comment = $this->GetPostedComment();
		if( $comment === false ){
			return false;
		}

		$data		= SimpleBlogCommon::GetCommentData($this->post_id);
		$data[]		= $comment;

		if( !SimpleBlogCommon::SaveCommentData($this->post_id,$data) ){
			message($langmessage['OOPS']);
			return false;
		}

		message($langmessage['SAVED']);

		$this->EmailComment($comment);
		$this->comment_saved = true;
		return true;
	}

	/**
	 * Get Posted Comment
	 *
	 */
	protected function GetPostedComment(){
		global $langmessage;

		if( empty($_POST['name']) ){
			$field = gpOutput::SelectText('Name');
			message($langmessage['OOPS_REQUIRED'],$field);
			return false;
		}

		if( empty($_POST['comment']) ){
			$field = gpOutput::SelectText('Comment');
			message($langmessage['OOPS_REQUIRED'],$field);
			return false;
		}


		$comment				= array();
		$comment['name']		= htmlspecialchars($_POST['name']);
		$comment['comment']		= nl2br(strip_tags($_POST['comment']));
		$comment['time']		= time();

		if( !empty($_POST['website']) && ($_POST['website'] !== 'http://') ){
			$website = $_POST['website'];
			if( mb_strpos($website,'://') === false ){
				$website = false;
			}
			if( $website ){
				$comment['website'] = $website;
			}
		}

		return $comment;
	}


	/**
	 * Email new comments
	 *
	 */
	protected function EmailComment($comment){
		global $gp_mailer;

		if( empty(SimpleBlogCommon::$data['email_comments']) ){
			return;
		}

		includeFile('tool/email_mailer.php');


		$body		= '';
		if( !empty($comment['name']) ){
			$body .= '<p>From: '.$comment['name'].'</p>';
		}
		if( !empty($comment['website']) ){
			$body .= '<p>Website: '.$comment['name'].'</p>';
		}
		$body .= '<p>'.$comment['comment'].'</p>';

		$gp_mailer->SendEmail(SimpleBlogCommon::$data['email_comments'], 'New Comment', $body);

	}


	/**
	 * Output the html for a blog post's comments
	 *
	 */
	public function GetCommentHtml( $data ){

		if( !is_array($data) ){
			return;
		}

		foreach($data as $key => $comment){
			$this->OutputComment($key,$comment);
		}
	}

	/**
	 * Output single comment
	 *
	 */
	private function OutputComment($key,$comment){
		global $langmessage;

		echo '<div class="comment_area">';
		echo '<p class="name">';
		if( (SimpleBlogCommon::$data['commenter_website'] == 'nofollow') && !empty($comment['website']) ){
			echo '<b><a href="'.$comment['website'].'" rel="nofollow">'.$comment['name'].'</a></b>';
		}elseif( (SimpleBlogCommon::$data['commenter_website'] == 'link') && !empty($comment['website']) ){
			echo '<b><a href="'.$comment['website'].'">'.$comment['name'].'</a></b>';
		}else{
			echo '<b>'.$comment['name'].'</b>';
		}
		echo ' &nbsp; ';
		echo '<span>';
		echo strftime(SimpleBlogCommon::$data['strftime_format'],$comment['time']);
		echo '</span>';


		if( common::LoggedIn() ){
			echo ' &nbsp; ';
			$attr = 'class="delete gpconfirm" title="'.$langmessage['delete_confirm'].'" name="postlink" data-nonce= "'.common::new_nonce('post',true).'"';
			echo SimpleBlogCommon::PostLink($this->post_id,$langmessage['delete'],'cmd=delete_comment&comment_index='.$key,$attr);
		}


		echo '</p>';
		echo '<p class="comment">';
		echo $comment['comment'];
		echo '</p>';
		echo '</div>';
	}


	/**
	 * Display the visitor form for adding comments
	 *
	 */
	public function CommentForm(){

		if( $this->comments_closed ){
			echo '<div class="comments_closed">';
			echo gpOutput::GetAddonText('Comments have been closed.');
			echo '</div>';
			return;
		}

		if( $this->comment_saved ){
			return;
		}


		$_POST += array('name'=>'','website'=>'http://','comment'=>'');

		echo '<h3>';
		echo gpOutput::GetAddonText('Leave Comment');
		echo '</h3>';


		echo '<form method="post" action="'.SimpleBlogCommon::PostUrl($this->post_id).'">';
		echo '<ul>';

		//name
		echo '<li>';
		echo '<label>';
		echo gpOutput::GetAddonText('Name');
		echo '</label><br/>';
		echo '<input type="text" name="name" class="text" value="'.htmlspecialchars($_POST['name']).'" />';
		echo '</li>';

		//website
		if( !empty(SimpleBlogCommon::$data['commenter_website']) ){
			echo '<li>';
			echo '<label>';
			echo gpOutput::GetAddonText('Website');
			echo '</label><br/>';
			echo '<input type="text" name="website" class="text" value="'.htmlspecialchars($_POST['website']).'" />';
			echo '</li>';
		}


		//comment
		echo '<li>';
		echo '<label>';
		echo gpOutput::ReturnText('Comment');
		echo '</label><br/>';
		echo '<textarea name="comment" cols="30" rows="7" >';
		echo htmlspecialchars($_POST['comment']);
		echo '</textarea>';
		echo '</li>';


		//recaptcha
		if( SimpleBlogCommon::$data['comment_captcha'] && gp_recaptcha::isActive() ){
			echo '<input type="hidden" name="anti_spam_submitted" value="anti_spam_submitted" />';
			echo '<li>';
			echo '<label>';
			echo gpOutput::ReturnText('captcha');
			echo '</label><br/>';
			gp_recaptcha::Form();
			echo '</li>';
		}

		//submit button
		echo '<li>';
		echo '<input type="hidden" name="cmd" value="Add Comment" />';
		$html = '<input type="submit" name="" class="submit" value="%s" />';
		echo gpOutput::GetAddonText('Add Comment',$html);
		echo '</li>';

		echo '</ul>';
		echo '</form>';

	}


	/**
	 * todo: better 404 page
	 *
	 */
	public function Error_404(){
		global $langmessage;

		message($langmessage['OOPS']);
	}


	/**
	 * Display the links at the bottom of a post
	 *
	 */
	public function PostLinks(){

		$post_key = SimpleBlogCommon::AStrKey('str_index',$this->post_id);

		echo '<p class="blog_nav_links">';


		//blog home
		$html = common::Link('Special_Blog','%s','','class="blog_home"');
		echo gpOutput::GetAddonText('Blog Home',$html);
		echo '&nbsp;';


		// check for newer posts and if post is draft
		$isDraft = false;
		if( $post_key > 0 ){

			$i = 0;
			do {
				$i++;
				$next_index = SimpleBlogCommon::AStrGet('str_index',$post_key-$i);
				if( !common::loggedIn() ){
					$isDraft = SimpleBlogCommon::AStrGet('drafts',$next_index);
				}
			}while( $isDraft );

			if( !$isDraft ){
				$html = SimpleBlogCommon::PostLink($next_index,'%s','','class="blog_newer"');
				echo gpOutput::GetAddonText('Newer Entry',$html);
				echo '&nbsp;';
			}
		}


		//check for older posts and if older post is draft
		$i = 0;
		$isDraft = false;
		do{
			$i++;
			$prev_index = SimpleBlogCommon::AStrGet('str_index',$post_key+$i);

			if( $prev_index === false ){
				break;
			}

			if( !common::loggedIn() ){
				$isDraft = SimpleBlogCommon::AStrGet('drafts',$prev_index);
			}

			if( !$isDraft ){
				$html = SimpleBlogCommon::PostLink($prev_index,'%s','','class="blog_older"');
				echo gpOutput::GetAddonText('Older Entry',$html);
			}

		}while( $isDraft );


		if( common::LoggedIn() ){
			echo '&nbsp;';
			echo common::Link('Admin_Blog','New Post','cmd=new_form','class="blog_post_new"');
		}

		echo '</p>';
	}

}

