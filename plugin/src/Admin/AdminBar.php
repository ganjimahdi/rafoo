<?php

namespace Rafoo\Admin;

use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class AdminBar {

	public static function node( $bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || ! State::offline() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'rafoo',
				'title' => esc_html__( 'Rafoo: outage mode', 'rafoo' ),
				'href'  => Page::url(),
				'meta'  => array( 'title' => __( 'The international internet is down. Rafoo is serving local copies.', 'rafoo' ) ),
			)
		);
	}
}
