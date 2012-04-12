<?php

class PostReceive extends Plugin
{
	public function action_ajax_update( $handler ) {
		$users = Users::get();
		$payload = $handler->handler_vars->raw('payload');
		$decoded_payload = json_decode( $payload, true ); // true to return an array instead of an object.

        Post::create( array(
            'title' => "incoming POST",
            'content' => "payload is a " . gettype( $payload ) . ", and decoded, it is a " . gettype( $decoded_payload ) . "\n" . var_export($decoded_payload, true),
            'content_type' => Post::type( 'entry' ),
            'user_id' => $users[0]->id,
        ));
	}

}
?>
