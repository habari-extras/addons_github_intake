<?php

class PostReceive extends Plugin
{
	public function action_ajax_update( $handler ) {
		$users = Users::get();
		$payload = $handler->handler_vars->raw('payload');
		$decoded_payload = json_decode( $payload );

		if ( isset( $decoded_payload ) ) {
			// Invalid decoded JSON is NULL.
			$commit_sha = $decoded_payload->after;
			$owner = ( isset( $decoded_payload->repository->organization ) ? $decoded_payload->repository->organization : $decoded_payload->repository->owner->name );
			$tree_URL = "https://api.github.com/repos/" . $owner . // what if it's a user?
				"/" . $decoded_payload->repository->name . "/git/trees/$commit_sha";

			$decoded_tree = json_decode( file_get_contents( $tree_URL, 0, null, null ) );
			$xml_urls = array_map( function( $a ) {
					if ( strpos( $a->path, ".plugin.xml" ) !== false ) {
						return$a->url; // path was just the filename, url is the API endpoint for the file itself
					}
				}, $decoded_tree->tree );
			$xml_urls = array_filter( $xml_urls ); // remove NULLs
			if ( count( $xml_urls ) === 1 ) {
				$xml_url = array_pop( $xml_urls );

				$decoded_blob = json_decode( file_get_contents( $xml_url, 0, null, null ) );

				if ( $decoded_blob->encoding === 'base64' ) {
					$xml_data = base64_decode( $decoded_blob->content );
				}
				else if ( $decoded_blob->encoding === 'utf-8' ) {
					// does it need to be decoded?
				}
				else {
					// there's an invalid encoding.
					return;
				}

				$xml_object = simplexml_load_string( $xml_data, 'SimpleXMLElement', LIBXML_NOCDATA );

/* can't hurt to hold onto this. */
				$xml_object->addChild( "xml_string", $xml_object->asXML() );
/* won't always need these */
				$xml_object->addChild( "tree_url", $tree_URL );
				$xml_object->addChild( "blob_url", $xml_url );

/* need to check if there's already a posts with this guid */
				self::make_post_from_XML( $xml_object );
			}
			else {
				// Wrong number of xml files.
			}
		}
		else {
			// Something has gone wrong with the json_decode. Do nothing, since there is nothing that can really be done.
		}
	}

	public static function make_post_from_XML( $xml = null ) {
		$type = (string) $xml->attributes()->type; // 'plugin', 'theme'...
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

/* won't always need these */
		$post->info->blob_url = (string) $xml->blob_url;
		$post->info->tree_url = (string) $xml->tree_url;
		$post->info->xml = (string) $xml->xml_string;

		$post->info->type = $type;
		$post->info->guid = (string) $xml->guid;
		$post->info->url = (string) $xml->url; // or maybe dirname( $github_xml ); // not right but OK for now

		$temporary_array = array();

		foreach( $xml->author as $author ) {
			array_push( $temporary_array, array( 'name' => (string) $author, 'url' => (string) $author->attributes()->url ) );
		}
		$post->info->authors = $temporary_array;

		$temporary_array = array();
		$license_post = Post::get( array( 'all:info' => array( 'url' => (string) $xml->license->attributes()->url ) ) );
		array_push( $temporary_array, $license_post->info->shortname );

		$post->info->licenses = $temporary_array;

		$post->info->commit();

		$versions = array(
			(string) $xml->version => array(
				'version' => (string) $xml->version,
				'description' => (string) $xml->description,
				'info_url' => (string) $xml->url, // dupe of above, not great.
				'url' => (string) $xml->downloadurl,
				'habari_version' => '0.8.x', // hardcode for now
				'severity' => 'feature', // hardcode for now
				'requires' => '',
				'provides' => '',
				'recommends' => '',
				'release' => '',
			),
		);
		PluginDirectoryExtender::save_version( $post, $versions );
	}
}

class PluginDirectoryExtender extends PluginDirectory {

	// This should only be running on a single version - commit should be for a single branch or master.

	private static $vocabulary = "Addon versions";

	public static function save_version( $post = null, $versions = array() ) {

		if( isset( $post ) && count( $versions ) !== 0 ) {

			$vocabulary = Vocabulary::get( self::$vocabulary );

			foreach( $versions as $key => $version ) {
				$term = new Term( array(
					'term_display' => $post->id . " $key",
				) );
				foreach ( $version as $field => $value ) {
					$term->info->$field = $value;
				}
				$vocabulary->add_term( $term );
				$term->associate( 'addon', $post->id );
			}
		}
		else {
			// post didn't work or there was no version.
		}
	}
}
?>
