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
			$owner = ( $decoded_payload->repository->organization != "" ? $decoded_payload->repository->organization : $decoded_payload->repository->owner->name );
			$tree_URL = "https://api.github.com/repos/" . $owner . // what if it's a user?
				"/" . $decoded_payload->repository->name . "/git/trees/$commit_sha";

			$decoded_tree = json_decode( file_get_contents( $tree_URL, 0, null, null ) );
			$xml_urls = array_map( function( $a ) {
					if ( strpos( $a->path, ".plugin.xml" ) !== false ) {
						return  $a->url . $a->path;
					}
				}, $decoded_tree->tree ); // NULL otherwise, need to get rid of those.

	        Post::create( array(
	            'title' => "incoming POST",
	            'content' => "payload is a " . gettype( $payload ) . ", and decoded, it is a " . gettype( $decoded_payload ) . "\n" //. var_export($decoded_payload, true),
. $tree_URL //. var_export( $decoded_tree, true ) 
. var_export( $xml_urls, true ),
	            'content_type' => Post::type( 'entry' ),
	            'user_id' => $users[0]->id,
	        ));
		}
		else {
			// Something has gone wrong with the json_decode. Do nothing, since there is nothing that can really be done.
		}
	}
}
?>
