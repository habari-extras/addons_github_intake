<?php
namespace Habari;

class AddonsGithubIntake extends Plugin
{
	// this will be used with the /i option
	const VERSION_REGEX = '((?:\d+|alpha|beta|a|b|rc|p|pl)(?:\.(?:\d+|alpha|beta|a|b|rc|p|pl))*(?:\.(?:\d+|alpha|beta|a|b|rc|p|pl|x)))-((?:\d+|alpha|beta|a|b|rc|p|pl)(?:\.(?:\d+|alpha|beta|a|b|rc|p|pl))*(?:\.(?:(?:\d+|alpha|beta|a|b|rc|p|pl|x)))(?:-\w+)?)';
	
	const HOSTER = "GitHub";

	const SCREENSHOT_DIR = "screenshots";

	public function action_plugin_activation( $file ) {
		$user = User::get_by_name( 'github_hook' );
		if( ! $user ) {
			$user = User::create( array(
				'username' => 'github_hook',
				'email' => 'addons@habariproject.org',
				'password' => sha1( rand( 0, pow( 2,32 ))),
			));
		}

		$group = UserGroup::get_by_name( 'github_users' );
		if( ! $group ) {
			$group = UserGroup::create( array( 'name' => 'github_users' ) );
		}
		
		$group->grant( 'post_addon', 'read' );

		$user->add_to_group($group);
	}

	public function action_plugin_deactivation( $file ) {
	}

	public function filter_rewrite_rules($rules) {
		$rules['git_post_receive'] = RewriteRule::create_url_rule('"update"', 'PluginHandler', 'addon_update');
		return $rules;
	}

	public function action_plugin_act_addon_update($handler) {
		$payload = $handler->handler_vars->raw('payload');
echo "Start process_update.\n\n";
		$this->process_update($payload);
echo "Update processed.\n\n";
	}

	public function process_update($payload) {
		$decoded_payload = json_decode( $payload );
//echo "Payload:\n$payload\n\n";
		if ( !isset( $decoded_payload ) ) {
			// Something has gone wrong with the json_decode. Do nothing, since there is nothing that can really be done.
			return;
		}

		// Invalid decoded JSON is NULL.
		$commit_sha = $decoded_payload->after;
		$owner = ( isset( $decoded_payload->repository->organization ) ? $decoded_payload->repository->organization : $decoded_payload->repository->owner->name );
		$owner_mail = ( isset( $decoded_payload->repository->owner->email ) ) ? $decoded_payload->repository->owner->email : ""; // Users have an email, what about organizations?

		// store the hash 'after' - it should be the latest commit among all the ones in this ping.
		$commit_hash = $decoded_payload->after;

		$repo_URL = $decoded_payload->repository->url;

		$tree_URL = "https://api.github.com/repos/" . $owner . // what if it's a user?
			"/" . $decoded_payload->repository->name . "/git/trees/$commit_sha";
echo "Tree URL: $tree_URL\n\n";

		$tree_data = self::github_request("/repos/{$owner}/{$decoded_payload->repository->name}/git/trees/{$commit_sha}", '', 'GET');

		$decoded_tree = json_decode( $tree_data );
echo "Decoded Tree: " . var_export($decoded_tree,1) . "\n\n";


		$xml_urls = array_map(
			function( $a ) {
				if ( strpos( $a->path, ".plugin.xml" ) !== false || $a->path === 'theme.xml' ) {
					return$a->url; // path was just the filename, url is the API endpoint for the file itself
				}
			}, $decoded_tree->tree );
		$xml_urls = array_filter( $xml_urls ); // remove NULLs
echo "XML URLs: " . var_export($xml_urls,1) . "\n\n";

		if ( count( $xml_urls ) > 1 ) {
			$this->file_issue(
				$owner, $decoded_payload->repository->name,
				'Too many XML files',
				"Habari addons should have a single XML file containing addon information.<br>"
			);
			EventLog::log('XML not found/too many', 'info', 'intake', null, $payload);

			// Cannot proceed without knowing which XML file to parse, so stop.
			// This is separate from the other checks below which can (and should be able to) create multiple issues.
			return;
		}
		elseif(count($xml_urls) == 0) {
			$this->file_issue(
				$owner, $decoded_payload->repository->name,
				'No XML file found',
				"Habari addons should have a single XML file containing addon information.<br>"
			);
			EventLog::log('XML not found/too many', 'info', 'intake', null, $payload);

			// Cannot proceed without knowing which XML file to parse, so stop.
			// This is separate from the other checks below which can (and should be able to) create multiple issues.
			return;
		}

		$xml_URL = array_pop( $xml_urls );

		// let's grab a screenshot, if there is one.

		$screenshot_urls = array_map(
			function( $a ) {
				if ( strpos( $a->path, "screenshot." ) !== false ) {
					return$a->url; // path was just the filename, url is the API endpoint for the file itself
				}
			}, $decoded_tree->tree );
		$screenshot_urls = array_filter( $screenshot_urls ); // remove NULLs
		$screenshot_URL = array_pop( $screenshot_urls );

		// there must be a better way to check if the file exists remotely. Really, this would just be checking if the JSON is there...
		if ( @fopen( $screenshot_URL, "r" ) != true ) {
			$screenshot_URL = null;
		}
		else {
			$screenshot_URL = self::screenshot( $screenshot_URL, Utils::slugify( $decoded_payload->repository->name ) );
		}

		$decoded_blob = json_decode( RemoteRequest::get_contents($xml_URL) );

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
		$doc = new \DomDocument;

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

		/* must pass this along. */
		$xml_object->addChild( "hash", $commit_hash );
		$xml_object->addChild( "screenshot_url", $screenshot_URL );

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
				"Habari addons require a GUID to be listed in the Addons Catalog.<br>Please create and add a GUID to your xml file. You can use this one, which is new:<br><b>" . strtoupper( UUID::get() ) . "</b>"
			);
			$xml_is_OK = false;
		}
		else {
			if ( ! preg_match( "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i", strtolower( trim( $xml_object->guid ) ) ) ) {
				$this->file_issue(
					$owner, $decoded_payload->repository->name,
					'Invalid GUID in Info XML',
					"Habari addons require a RFC4122-compliant GUID to be listed in the Addons Catalog.<br>The GUID found in the XML, {$xml_object->guid}, is not a valid GUID according to the RFC.  Please update the GUID in your xml file, or you can use this one, which is new:<br><b>" . strtoupper( UUID::get() ) . "</b>"
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

			// see if the screenshot is missing?
			if ( is_null( $screenshot_URL ) ) {
				$this->file_issue(
					$owner, $decoded_payload->repository->name,
					'Missing screenshot',
					"Your theme needs to have a screenshot."
				);
			}
			// do nothing with $xml_is_OK, just log the issue.

			// check isset first? trim?
			$parent = (string) $xml_object->parent ?: false;

			// now, check if the parent is already included. If not, log an issue.
			if ( $parent && ! Post::get( array( 'title' => $parent, 'content_type' => Post::type( 'addon' ), 'status' => Post::status( 'published' ), 'all:info' => array( 'type' => 'theme' ) ) ) ) {
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
echo "Version version: {$version_version}\n\n";
		if( strpos( $version_version, "-" ) !== false ) {
			// could replace the following with a preg_match( '%'.self::VERSION_REGEX.'%i'..., but is that altogether necessary?
			list( $habari_version, $version_version ) = explode( "-", $version_version );
		}
echo "Habari version: {$habari_version}\n\n";
echo "Version version, reduced: {$version_version}\n\n";

		// If this ping is from a tag, check if the XML version matches the tag. 
		// Handle version in tag, if present.
		$tag_ref = json_decode( $xml_object->ping_contents )->ref;
echo "Tag ref: {$tag_ref}\n\n";
		
		if( $tag_ref !== "refs/heads/master" ) {
			if( strpos( $tag_ref, "refs/tags/" ) === 0 ) {
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
echo "Refs matches:" . var_export($matches,1) . "\n\n";
					$habari_version = $matches[3];
					$version_version = $matches[4];

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
			else {
				// it's probably a branch. We'll ignore it.
			}
		}

		$xml_object->addChild( "habari_version", $habari_version );
		$xml_object->addChild( "version_version", $version_version );


		/* store the owner */
		$xml_object->addChild( "owner", json_decode( $xml_object->ping_contents )->repository->owner->name );


echo "Final versions: Habari {$habari_version}, Pluggable {$version_version}\n\n";
//echo $xml_object

		if( $xml_is_OK ) {
			EventLog::log( _t('Successful XML import from GitHub for GUID %s', array(trim($xml_object->guid))),'info');

			// Create Habari user for the repo owner
			$owner_habari_user = self::make_user_from_git( $owner, $owner_mail );
			$xml_object->addChild( "GitHub_user_id", $owner_habari_user->info->servicelink_GitHub );
			self::make_post_from_XML( $xml_object );
		}

	}

	public static function github_request($url, $data = null, $verb = null) {
		$gitusername = Options::get('post_receive__bot_username');
		$gitpassword = Options::get('post_receive__bot_password');
		if(is_object($data)) {
			$data = json_encode($data);
		}
		try { 
			$full_url = "https://{$gitusername}:{$gitpassword}@api.github.com{$url}";
//echo "RR URL: {$full_url}\n\n";
			$rr = new RemoteRequest($full_url, $verb);
			if(is_null($verb)) {
				if(is_null($data)) {
					$verb = 'GET';
				}
				else {
					$verb = 'POST';
				}
			}
			if($verb != 'GET') {
				$rr->set_body($data);
			}
			$rr->execute();
echo "github_request: {$url} \n\n";
echo "  response headers: " . var_export($rr->get_response_headers(),1) . "\n\n";
			return $rr->get_response_body();
		} catch ( Exception $e ) {
			EventLog::log( _t( 'Failed to make github api request to %s', array( $url )), 'err' );
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
	
	public static function make_user_from_git( $name, $mail ) {
		// Get Github user id
/*
		$request = new RemoteRequest("https://api.github.com/users/$name");
		$request->execute();
		if( ! $request->executed() ) {
			throw new XMLRPCException( 16 );
		}
		$json_response = $request->get_response_body();
*/
		$json_response = self::github_request("/users/{$name}");
		$jsondata = json_decode($json_response);
		$id = $jsondata->id;
		
		// Check if there is already an account linked to that id
		$users = Users::get( array( 'info' => array( 'servicelink_GitHub' => $id ) ) );
		if( count( $users ) == 0 ) {
			// Check if there is already an account with that name
			$users = Users::get(array('username' => $name));
			// Append stuff until the name is unique. Let's hope there are not too many users named John Doe
			while( count( $users ) != 0 ) {
				$name .= "_1";
				$users = Users::get(array('username' => $name));
			}
			$user = User::create( array( 'username' => $name, 'email' => $mail) );
			if( $user ) {
				$user->info->servicelink_GitHub = $id;
				$user->update();
				$user->add_to_group( 'github_users' );
				Eventlog::log( "Created user $name and linked to GitHub id $id" );
				return $user;
			}
			else {
				Eventlog::log( 'Creation of GitHub user $name failed', 'err' );
				return false;
			}
		}
		else {
			return $users[0];
		}
	}

	public static function make_post_from_XML( $xml = null ) { // rename this function!

		$info[ 'screenshot_url' ] = (string) $xml->screenshot_url;
		/* won't always need these */
		$info[ 'blob_url' ] = (string) $xml->blob_url;
		$info[ 'tree_url' ] = (string) $xml->tree_url;
		$info[ 'repo_url' ] = (string) $xml->repo_url;
		$info[ 'type' ] = (string) $xml->type;

		$tags = array( 'github', $info[ 'type' ] );

		if( isset($xml->tag) ) {
			foreach( $xml->tag as $tag ) {
				$tags[] = $tag;
			}
		}
				
		// There are probably many better things to insert here.
		$info[ 'tags' ] = $tags;

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

		$whole_version = $xml->habari_version . '-' . $xml->version_version;


		EventLog::log(_t('Creating Addon "%s" Version %s-%s as "%s"', array($xml->name, $xml->habari_version, $xml->version_version, $whole_version)));

		$version = array(
			(string) $whole_version => array(
				'source' => self::HOSTER,
				'hash' => (string) $xml->hash,
				'version' => (string) $xml->version_version,
				'description' => (string) $xml->description,
				'info_url' => (string) $xml->url, // dupe of above, not great.
				'url' => (string) $xml->repo_url, // this is bad - or at least, github-specific.
				'habari_version' => (string) $xml->habari_version,
				'severity' => 'feature', // hardcode for now
				'requires' => isset( $features['requires'] ) ? $features['requires'] : '',
				'provides' => isset( $features['provides'] ) ? $features['provides'] : '',
				'recommends' => isset( $features['recommends'] ) ? $features['recommends'] : '',
				'conflicts' => isset( $features['conflicts'] ) ? $features['conflicts'] : '',
				'release' => DateTime::create(),
				'GitHub_user_id' => (string) $xml->GitHub_user_id,
				'owner' => (string) $xml->owner,
				'xml' => (string) $xml->xml_string,
				'json' => (string) $xml->ping_contents,
			),
		);

		$info[ 'user_id' ] = User::get( 'github_hook' )->id;
		$info[ 'guid' ] = strtoupper( $xml->guid );
		$info[ 'name' ] = (string) $xml->name;
		$info[ 'description' ] = (string) $xml->description;
		$info[ 'hoster' ] = self::HOSTER;
			
		// This won't change. It's not authoritative; merely the first one to ping in.
		$info[ 'original_repo' ] = (string) $xml->repo_url;

//echo "Info passed to handle_addon:\n\n";
//var_dump($info);
//var_dump($version);

		AddonCatalogPlugin::handle_addon( $info, $version );

	}
	
	/**
	 * Put the plugin files for the indicated version into a specific directory, if this plugin handles it
	 */
	public function action_addon_download( $source, $addon, $term, $tmp_dir ) {
		if( $source == self::HOSTER ) {
			$url = $term->info->url;
			$command = "git clone {$url} {$tmp_dir}";
			exec($command);

			// Checkout a tag
			$habari_version = $term->info->habari_version;
			$version = $term->info->version;
			$rev = 'master';
			if($habari_version != '?.?.?') {
				//exec('cd ' . $tmp_dir);
				chdir($tmp_dir);
				$rev = $habari_version . '-' . $version;
				exec('git checkout ' . escapeshellarg($rev));
			}

			$site = Site::get_url('site');
			$date = DateTime::create()->format('Y-m-d @ H:i:s');

			$package = <<< PACKAGE_REV
This download was packaged by {$site} on {$date} from {$url} revision {$rev}.
PACKAGE_REV;

			file_put_contents(Utils::end_in_slash($tmp_dir) . '__package.rev', $package);
		}
	}

	public static function configure() {
		$form = new FormUI( 'post_receive' );

		$form->append( FormControlText::create('bot_username', 'option:post_receive__bot_username')->label('Github issue posting username') );
		$form->append( FormControlPassword::create('bot_password', 'option:post_receive__bot_password')->label( 'Github issue posting password' ) );

		$form->append( FormControlSubmit::create('save')->set_caption( _t( 'Save' ) ) );
		return $form;
	}

	public static function screenshot( $json_url, $name ) {
		// Starting with the JSON containing the encoding and the file, decode it and store it in the filesystem

		$screenshot_path = HABARI_PATH . '/' . Site::get_path( 'user', true ) . "files/" . self::SCREENSHOT_DIR . '/';
		$screenshot_url = Site::get_url( 'user', true ) . 'files/' . self::SCREENSHOT_DIR . '/';

		// check if the directory exists (should this be moved to activation?)
		if( ! is_dir( $screenshot_path ) ) {
			// if not, create it (or log an error and return null)
			if( ! mkdir( $screenshot_path ) ) {
				EventLog::log( "Screenshot directory cannot be created.", 'err' );
				return null;
			}
		}

		$filename = $name . ".png";

		$encoded_image = json_decode( RemoteRequest::get_contents($json_url) );
		$decoded_image = base64_decode( chunk_split( $encoded_image->content ) );
		$fh = fopen( "{$screenshot_path}/{$filename}" , "wb" );
		fwrite( $fh, $decoded_image );
		fclose( $fh );

		$screenshot_url .= $filename;

		EventLog::log( "Screenshot stored $screenshot_url", 'info' );

// there should be some sort of checking to make sure it worked, but what?
		if ( true ) {
			return $screenshot_url;
		}

		// return null if it didn't work
		return null;
	}

}
?>
