<?php

class PostReceive extends Plugin
{
	public function action_ajax_update( $handler ) {
		Utils::debug( $handler->handler_vars );
	}

}
?>
