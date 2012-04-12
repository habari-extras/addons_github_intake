<?php

class PostReceive extends Plugin
{
	public function action_ajax_update( $handler ) {
		$users = Users::get();
        Post::create( array(
            'title' => "incoming POST",
            'content' => serialize( $handler->handler_vars['payload'] ),
            'content_type' => Post::type( 'entry' ),
            'user_id' => $users[0]->id,
        ));
	}

}
?>
