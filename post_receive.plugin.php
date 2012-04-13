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
/*
		Post::create( array(
		'title' => $xml_object->name,
		'content' => "payload is a " . gettype( $payload ) . ", and decoded, it is a " . gettype( $decoded_payload ) . "\n" . $xml_url . "\n" . $xml_data, 
		'content_type' => Post::type( 'entry' ),
		'user_id' => $users[0]->id,
		));
*/
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

		$post->info->type = $type;
		$post->info->guid = (string) $xml->guid;
		$post->info->url = (string) $xml->url; // or maybe dirname( $github_xml ); // not right but OK for now
		$post->info->author = (string) $xml->author; // @TODO: be ready for more than one
		$post->info->author_url = (string) $xml->author->attributes()->url;
		$post->info->license = Post::get( array( 'title' => "{$xml->license}" ) )->info->shortname;
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
		$this->save_versions( $post, $versions );
	}
}
?>
