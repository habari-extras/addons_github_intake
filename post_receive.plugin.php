<?php

class PostReceive extends Plugin
{
	// this will be used with the /i option
	const VERSION_REGEX = '((?:\d+|alpha|beta|a|b|rc|p|pl)(?:\.(?:\d+|alpha|beta|a|b|rc|p|pl))*(?:\.(?:\d+|alpha|beta|a|b|rc|p|pl|x)))-((?:\d+|alpha|beta|a|b|rc|p|pl)(?:\.(?:\d+|alpha|beta|a|b|rc|p|pl))*(?:\.(?:(?:\d+|alpha|beta|a|b|rc|p|pl|x)))(?:-\w+)?)';

	public function action_plugin_activation( $file ) {
		if( ! User::get_by_name( 'github_hook' ) ) {
			User::create( array(
				'username' => 'github_hook',
				'email' => 'addons@habariproject.org',
				'password' => sha1( rand( 0, pow( 2,32 ))),
			));
		}
	}

	public function action_plugin_deactivation( $file ) {
	}

	public function filter_rewrite_rules($rules) {
		$rules['git_post_receive'] = RewriteRule::create_url_rule('"update"', 'PluginHandler', 'addon_update');
		return $rules;
	}

	public function action_plugin_act_addon_update($handler) {
		$this->action_ajax_update($handler);
	}

	public function action_ajax_update( $handler ) {
		$users = Users::get();
		$payload = $handler->handler_vars->raw('payload');
		$decoded_payload = json_decode( $payload );

		if ( !isset( $decoded_payload ) ) {
			// Something has gone wrong with the json_decode. Do nothing, since there is nothing that can really be done.
			return;
		}

		// Invalid decoded JSON is NULL.
		$commit_sha = $decoded_payload->after;
		$owner = ( isset( $decoded_payload->repository->organization ) ? $decoded_payload->repository->organization : $decoded_payload->repository->owner->name );
		$repo_URL = $decoded_payload->repository->url;

		$tree_URL = "https://api.github.com/repos/" . $owner . // what if it's a user?
			"/" . $decoded_payload->repository->name . "/git/trees/$commit_sha";

		$decoded_tree = json_decode( file_get_contents( $tree_URL, 0, null, null ) );
		$xml_urls = array_map(
			function( $a ) {
				if ( strpos( $a->path, ".plugin.xml" ) !== false || $a->path === 'theme.xml' ) {
					return$a->url; // path was just the filename, url is the API endpoint for the file itself
				}
			}, $decoded_tree->tree );
		$xml_urls = array_filter( $xml_urls ); // remove NULLs

		if ( count( $xml_urls ) !== 1 ) {
			// Wrong number of XML files.
			$this->file_issue(
				$owner, $decoded_payload->repository->name,
				'Too many XML files',
				"Habari addons should have a single XML file containing addon information.<br>"
			);

			// Cannot proceed without knowing which XML file to parse, so stop.
			// This is separate from the other checks below which can (and should be able to) create multiple issues.
			return;
		}

		$xml_URL = array_pop( $xml_urls );

		$decoded_blob = json_decode( file_get_contents( $xml_URL, 0, null, null ) );

		if ( $decoded_blob->encoding === 'base64' ) {
			$xml_data = base64_decode( $decoded_blob->content );
		}
		else if ( $decoded_blob->encoding === 'utf-8' ) {
			// does it need to be decoded?
			// so far this hasn't happened in testing. $xml_data should be set to something, though, in case this logic is followed.
			$xml_data = $decoded_blob->content;
		}
		else {
			// there's an invalid encoding.
			return;
		}

/* validate the xml string against the [current?] XSD */
		$doc = new DomDocument;

		// Surpress errors outright - maybe better to handle them in some bulk fashion later.
		set_error_handler( create_function( '$errno, $errstr, $errfile, $errline, $errcontext', '/* do nothing */' ) );

		$doc->loadXML( $xml_data );

		if( isset( $doc ) ) {

			// @TODO: Get this more intelligently. URL in an Option, maybe? Symlink to schema.hp.o in the filesystem?
			if ( ! $doc->schemaValidate( dirname( __FILE__) . '/Pluggable-0.9.xsd' ) ) {
				$this->file_issue(
					$owner, $decoded_payload->repository->name,
					'Invalid XML',
					"Habari addons require a valid XML file.<br>Please fix yours. You can check it using the Habari Pluggable schema validator. http://schemas.habariproject.org/pluggable_validator.php"
				);
				// This is separate from the other checks below which can (and should be able to) create multiple issues.
				return;
			}
		}
		restore_error_handler();

		$xml_object = simplexml_load_string( $xml_data, 'SimpleXMLElement' );

/* can't hurt to hold onto these */
		$xml_object->addChild( "xml_string", $xml_object->asXML() );
/* won't always need these */
		$xml_object->addChild( "tree_url", $tree_URL );
		$xml_object->addChild( "blob_url", $xml_URL );
		$xml_object->addChild( "ping_contents", $payload );

/* might need this. Or should it go in downloadurl? */
		$xml_object->addChild( "repo_url", $repo_URL );


/* check XML problems */
		$xml_is_OK = true;



		// check if the XML includes a guid 
		if(!isset($xml_object->guid) || trim($xml_object->guid) == '') {
			$this->file_issue(
				$owner, $decoded_payload->repository->name,
				'Info XML needs a GUID',
				"Habari addons require a GUID to be listed in the Addons Directory.<br>Please create and add a GUID to your xml file. You can use this one, which is new:<br><b>" . UUID::get() . "</b>"
			);
			$xml_is_OK = false;
		}


/* need to check if there's already a posts with this guid */

		if( $xml_is_OK ) {
			EventLog::log( _t('Making new post for GUID %s', array(trim($xml_object->guid))),'info');
			self::make_post_from_XML( $xml_object );
		}

	}

	public static function file_issue($user, $repo, $title, $body, $tag = 'bug') {
		EventLog::log( _t('Filed issue for %s/%s - %s', array($user, $repo, $title)),'info');
		$gitusername = Options::get('post_receive__bot_username');
		$gitpassword = Options::get('post_receive__bot_password');
		$rr = new RemoteRequest("https://{$gitusername}:{$gitpassword}@api.github.com/repos/{$user}/{$repo}/issues", 'POST');
		$rr->set_body('{"title": "' . addslashes($title) . '","body": "' . addslashes($body) . '","labels":["' . addslashes($tag) . '"]}');
		$rr->execute();
	}

	public static function create_addon_post( $info = array() ) {

	}

	public static function update_addon_post( $post = null, $info = array() ) {

	}

	public static function make_post_from_XML( $xml = null ) {
		$guid = strtoupper($xml->guid);

		$post = Post::get( array( 'status'=>Post::status('published'), 'all:info'=>array( 'guid'=>$guid ) ) );

		$type = (string) $xml->attributes()->type; // 'plugin', 'theme'...

		if ( $post !== false && $post->info->guid === $guid ) { // the latter test has not stopped posts from being overwritten
			$post = Post::get( 'id=' . $post->id );
			EventLog::log( _t('Editing post #%s - %s', array($post->id, $post->title)),'info');
			$post->modify( array(
				'title' => $xml->name,
				'content' => $xml->description, //file_get_contents( dirname( $github_xml ) . '/README.md' ),
				'pubdate' => HabariDateTime::date_create(),
				'slug' => Utils::slugify( $xml->name ),
			) );
			$post->update();
			// Update the post instead of creating it.
		}
		else {
			EventLog::log( _t('Creating a new post for %s', array((string)$xml->name)),'info');
			// Post::get returned no post.
			unset( $post );
			$post = Post::create( array(
				'content_type' => Post::type( 'addon' ),
				'title' => $xml->name,
				'content' => $xml->description, //file_get_contents( dirname( $github_xml ) . '/README.md' ),
				'status' => Post::status( 'published' ),
				'tags' => array( $type ),
				'pubdate' => HabariDateTime::date_create(),
				'user_id' => User::get( 'github_hook' )->id,
				'slug' => Utils::slugify( $xml->name ),
			) );
			// This won't change. It's not authoritative; merely the first one to ping in.
			$post->info->original_repo = (string) $xml->repo_url;
		}

/* won't always need these */
		$post->info->blob_url = (string) $xml->blob_url;
		$post->info->tree_url = (string) $xml->tree_url;
		$post->info->repo_url = (string) $xml->repo_url;

		$post->info->xml = (string) $xml->xml_string;
		$post->info->json = (string) $xml->ping_contents;

		$post->info->type = $type;
		$post->info->guid = strtoupper($xml->guid);
		$post->info->url = (string) $xml->url; // or maybe dirname( $github_xml ); // not right but OK for now

		if ( $type === "theme" && isset( $xml->parent ) ) {
			// store the name of the parent theme, if there is one.
			$parent = (string) $xml->parent;
			$post->info->parent_theme = $parent;

			// now, check if the parent is already included. If not, log an issue.
			if ( Post::get( array( 'title' => $parent, 'count' => 1 ) === 0 ) ) {
				// @TODO: Check if it is a Habari core theme before filing the issue.
				$this->file_issue(
					$owner, $decoded_payload->repository->name,
					'Unknown parent',
					"The parent theme ($parent) is not found."
				);
			}
		}

		// This may be a dangerous assumption, but the first help value should be English and not "Configure".
		$post->info->help = (string) $xml->help->value;

		$temporary_array = array();

		foreach( $xml->author as $author ) {
			array_push( $temporary_array, array( 'name' => (string) $author, 'url' => (string) $author->attributes()->url ) );
		}
		$post->info->authors = $temporary_array;

		$temporary_array = array();

		foreach( $xml->license as $license ) {
			array_push( $temporary_array, array( 'name' => (string) $license, 'url' => (string) $license->attributes()->url ) );
		}

		$post->info->licenses = $temporary_array;

		$post->info->commit();

		$features = array();
		foreach( array( "conflicts", "provides", "recommends", "requires" ) as $feature ) {
			if ( isset( $xml->$feature ) ) {
				$features[ $feature ] = array();
				foreach( $xml->$feature as $one_feature ) {
					array_push( $features[ $feature ], (string) $one_feature->feature );
				}
			}
		}

		$habari_version = "?.?.?";
		$version_version = (string) $xml->version;
		if( strpos( $version_version, "-" ) !== false ) {
			// could replace the following with a preg_match( '%'.self::VERSION_REGEX.'%i'..., but is that altogether necessary?
			list( $habari_version, $version_version ) = explode( "-", $version_version );
		}

/* For one thing, $this-> can't be used when not in object context.
		// Handle version in tag, if present.
		$tag_ref = json_decode( $xml->ping_contents )->ref;
		if( $tag_ref !== "refs/head/master" ) {
			// only deal with tags in the version-number format. This likely ignores branches.
			if( preg_match( '%(ref/tags/)(' . self::VERSION_REGEX . ')%i', $tag_ref, $matches ) ) {
//				if( $version_version !== $matches[2] ) { // 2 is everything after ref/tags
					$this->file_issue(
						$owner, $decoded_payload->repository->name,
						'XML/tag version mismatch',
						"The version number specified in the XML file ({$xml->version}) and the one from the tag ({$matches[2]}) should match."
					);
//				}

				// Now, do something with the version from the tag. The following lines replace any version set from the XML <version>
				$habari_version = $matches[3];
				$version_version = $matches[4];
			}
		}
*/
		$versions = array(
			(string) $xml->version => array(
				'version' => $version_version,
				'description' => (string) $xml->description,
				'info_url' => (string) $xml->url, // dupe of above, not great.
				'url' => "{$xml->repo_url}/downloads" , // this is bad - or at least, github-specific.
				'habari_version' => $habari_version,
				'severity' => 'feature', // hardcode for now
				'requires' => isset( $features['requires'] ) ? $features['requires'] : '',
				'provides' => isset( $features['provides'] ) ? $features['provides'] : '',
				'recommends' => isset( $features['recommends'] ) ? $features['recommends'] : '',
				'conflicts' => isset( $features['conflicts'] ) ? $features['conflicts'] : '',
				'release' => '',
			),
		);
		PluginDirectoryExtender::save_version( $post, $versions );
	}

	public static function configure() {
		$form = new FormUI( 'post_receive' );

		$form->append( 'text', 'bot_username', 'option:post_receive__bot_username', 'Github issue posting username' );
		$form->append( 'text', 'bot_password', 'option:post_receive__bot_password', 'Github issue posting password' );

		$form->append( 'submit', 'save', _t( 'Save' ) );
		return $form;
	}
}

class PluginDirectoryExtender extends AddonsDirectory {

	// This should only be running on a single version - commit should be for a single branch or master.

	private static $vocabulary = "Addon versions";

	public static function save_version( $post = null, $versions = array() ) {

		if( isset( $post ) && count( $versions ) !== 0 ) {

			$vocabulary = Vocabulary::get( self::$vocabulary );
			$extant_terms = $vocabulary->get_associations($post->id, 'addon');

			foreach( $versions as $key => $version ) {

				$term_display = "{$post->id} {$key} {$post->info->repo_url}";

				$found = false;
				foreach($extant_terms as $eterm) {
					if($eterm->term_display == $term_display) {  // This is super-cheesy!
						$found = true;
						$term = $eterm;
						break;
					}
				}
				if(!$found) {
					$term = new Term( array(
						'term_display' => $post->id . " $key",
					) );
				}
				foreach ( $version as $field => $value ) {
					$term->info->$field = $value;
				}
				if($found) {
					$term->update();
				}
				else {
					$vocabulary->add_term( $term );
					$term->associate( 'addon', $post->id );
				}
			}
		}
		else {
			// post didn't work or there was no version.
		}
	}
}
?>
