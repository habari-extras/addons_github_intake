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
// put a hook here to notify that an update was received?
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
		$omit_version = false;

		// check if the XML includes a guid 
		if(!isset($xml_object->guid) || trim($xml_object->guid) == '') {
			$this->file_issue(
				$owner, $decoded_payload->repository->name,
				'Info XML needs a GUID',
				"Habari addons require a GUID to be listed in the Addons Catalog.<br>Please create and add a GUID to your xml file. You can use this one, which is new:<br><b>" . strtoupper( UUID::get() ) . "</b>"
			);
			$xml_is_OK = false;
		}
		else {
			if ( ! preg_match( "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i", strtolower( trim( $xml_object->guid ) ) ) ) {
				$this->file_issue(
					$owner, $decoded_payload->repository->name,
					'Invalid GUID in Info XML',
					"Habari addons require a RFC4122-compliant GUID to be listed in the Addons Catalog.<br>Please update the GUID in your xml file, or you can use this one, which is new:<br><b>" . strtoupper( UUID::get() ) . "</b>"
				);
				$xml_is_OK = false;
			}
		}
		// check for pluggable type
		$type = (string) $xml_object->attributes()->type;
		if( $type !== 'plugin' && $type !== 'theme' ) { // check for 'locale' or 'core' or something else?
			$this->file_issue(
				$owner, $decoded_payload->repository->name,
				'Unknown Pluggable type in XML',
				"Habari addons should be of type <b>plugin</b> or <b>theme</b>, not <b>{SZ$type}</b>."
			);
			$xml_is_OK = false;
		}

		// check for a missing parent theme
		if( $type === 'theme' ) {

			// check isset first? trim?
			$parent = (string) $xml->parent;

			// now, check if the parent is already included. If not, log an issue.
			if ( Post::get( array( 'title' => $parent, 'content_type' => Post::type( 'addon' ), 'status' => Post::status( 'published' ), 'all:info' => array( 'type' => 'theme' ), 'count' => 1 ) === 0 ) ) {
				// @TODO: Check if it is a Habari core theme before filing the issue.
				$this->file_issue(
					$owner, $decoded_payload->repository->name,
					'Unknown parent in XML',
					"The parent theme ($parent) is not found in the Addons Catalog."
				);
			}
			// do nothing with $xml_is_OK, just log the issue.
		}
		$xml_object->addChild( "type", $type );


		// Grab the version, for later.
		$habari_version = "?.?.?";
		$version_version = (string) $xml_object->version; // just use $payload?
		if( strpos( $version_version, "-" ) !== false ) {
			// could replace the following with a preg_match( '%'.self::VERSION_REGEX.'%i'..., but is that altogether necessary?
			list( $habari_version, $version_version ) = explode( "-", $version_version );
		}
		$xml_object->addChild( "habari_version", $habari_version );
		$xml_object->addChild( "version_version", $version_version );


		// If this ping is from a tag, check if the XML version matches the tag. 
		// Handle version in tag, if present.
		$tag_ref = json_decode( $xml_object->ping_contents )->ref;
		
		if( $tag_ref !== "refs/heads/master" ) {
			// only deal with tags in the version-number format. This likely ignores branches.
			if( ! preg_match( '%(refs/tags/)(' . self::VERSION_REGEX . ')%i', $tag_ref, $matches ) ) {
					$this->file_issue(
						$owner, $decoded_payload->repository->name,
						'Unknown tag format',
						// @TODO: Link this to a wiki page with more instructions & suggested format.
						"Your tag ({$tag_ref}) is in an unsupported format. Please re-tag it with the specified format for inclusion in the directory.<br>If you were not tagging it for the directory this issue can be ignored."
					);
					// Do not store a version for this tag in the vocabulary.
					$omit_version = true;
			}
			else {
/*
matches[2] is everything after /ref/tags.
matches[3] would be the Habari version.
matches[4] would be the addon's version.

So if there's no - in the XML version, check against matches[4].
*/
				if( (string) $xml_object->version !== $matches[2] && (string) $xml_object->version !== $matches[4] ) { // 2 is everything after ref/tags
					$this->file_issue(
						$owner, $decoded_payload->repository->name,
						'XML/tag version mismatch',
						"The version number specified in the XML file ({$xml_object->version}) and the one from the tag ({$matches[2]}) should match."
					);

				$xml_is_OK = false;
				}
			}
		}

		if( $xml_is_OK ) {
			EventLog::log( _t('Successful XML import from GitHub for GUID %s', array(trim($xml_object->guid))),'info');

			self::make_post_from_XML( $xml_object );
		}

	}

	public static function file_issue($user, $repo, $title, $body, $tag = 'bug') {
		EventLog::log( _t('Filed issue for %s/%s - %s', array($user, $repo, $title)),'info');
		$gitusername = Options::get('post_receive__bot_username');
		$gitpassword = Options::get('post_receive__bot_password');
		try { 
			$rr = new RemoteRequest("https://{$gitusername}:{$gitpassword}@api.github.com/repos/{$user}/{$repo}/issues", 'POST');
			$rr->set_body('{"title": "' . addslashes($title) . '","body": "' . addslashes($body) . '","labels":["' . addslashes($tag) . '"]}');
			$rr->execute();
		} catch ( Exception $e ) {
			EventLog::log( _t( 'Failed to file issue on %s/%s - %s: %s', array( $user, $repo, $title, $body )), 'err' );
		}
	}

	public static function make_post_from_XML( $xml = null ) { // rename this function!
/* won't always need these */
		$info[ 'blob_url' ] = (string) $xml->blob_url;
		$info[ 'tree_url' ] = (string) $xml->tree_url;
		$info[ 'repo_url' ] = (string) $xml->repo_url;
		$info[ 'type' ] = (string) $xml->type;

		// There are probably many better things to insert here.
		$info[ 'tags' ] = array( 'github', $info[ 'type' ] );

		$info[ 'url' ] = (string) $xml->url; // or maybe dirname( $github_xml ); // not right but OK for now. This seems to be the committer's URL.

		if ( $info[ 'type' ] === "theme" && isset( $xml->parent ) ) {
			// store the name of the parent theme, if there is one.
			$info[ 'parent' ] = (string) $xml->parent;
		}

		// This may be a dangerous assumption, but the first help value should be English and not "Configure".
		$info[ 'help' ] = (string) $xml->help->value;

		foreach( $xml->author as $author ) {
			$info[ 'authors' ][] = array( 'name' => (string) $author, 'url' => (string) $author->attributes()->url );
		}

		foreach( $xml->license as $license ) {
			$info[ 'licenses' ][] = array( 'name' => (string) $license, 'url' => (string) $license->attributes()->url );
		}

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

		$version = array(
			(string) $xml->version => array(
				'version' => $version_version,
				'description' => (string) $xml->description,
				'info_url' => (string) $xml->url, // dupe of above, not great.
				'url' => (string) $xml->repo_url, // this is bad - or at least, github-specific.
				'habari_version' => $habari_version,
				'severity' => 'feature', // hardcode for now
				'requires' => isset( $features['requires'] ) ? $features['requires'] : '',
				'provides' => isset( $features['provides'] ) ? $features['provides'] : '',
				'recommends' => isset( $features['recommends'] ) ? $features['recommends'] : '',
				'conflicts' => isset( $features['conflicts'] ) ? $features['conflicts'] : '',
				'release' => HabariDateTime::date_create(),
			),
		);

		$info[ 'user_id' ] = User::get( 'github_hook' )->id;
		$info[ 'guid' ] = strtoupper( $xml->guid );
		$info[ 'name' ] = (string) $xml->name;
		$info[ 'description' ] = (string) $xml->description;
			
		// This won't change. It's not authoritative; merely the first one to ping in.
		$info[ 'original_repo' ] = (string) $xml->repo_url;

		// Probably don't need to keep these two.
		$info[ 'xml' ] = (string) $xml->xml_string;
		$info[ 'json' ] = (string) $xml->ping_contents;

		// Allow plugins to modify a new addon before it is created.
		Plugins::act( 'handle_addon_before', $info, $version );

		AddonCatalogPlugin::handle_addon( $info, $version );

		// Allow plugins to act after a new addon has been created.
		Plugins::act( 'handle_addon_after', $info, $version );
	}

	public static function configure() {
		$form = new FormUI( 'post_receive' );

		$form->append( 'text', 'bot_username', 'option:post_receive__bot_username', 'Github issue posting username' );
		$form->append( 'password', 'bot_password', 'option:post_receive__bot_password', 'Github issue posting password' );

		$form->append( 'submit', 'save', _t( 'Save' ) );
		return $form;
	}
}
?>
