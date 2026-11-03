<?php

namespace Rafoo;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$paragraphs = array(
			__( 'This site uses Rafoo to serve JavaScript libraries, stylesheets and web fonts from its own server instead of third-party networks such as jsDelivr, cdnjs and Google Fonts. Visitors\' browsers therefore do not connect to those networks for these files.', 'rafoo' ),
			__( 'While the international internet is unavailable, Rafoo may show locally generated avatars instead of Gravatar images and replace embedded videos and maps with a link to the original content.', 'rafoo' ),
			__( 'Forms on this site may be protected by Rafoo\'s captcha. It runs a short calculation in the visitor\'s browser and uses no cookies and no outside service.', 'rafoo' ),
			__( 'If an email from this site cannot be sent because the mail server is unreachable, Rafoo keeps a copy of the message, including its recipients and attachments, on this server and sends it when the connection returns. Copies are deleted as soon as the message is sent, and after three days at the latest.', 'rafoo' ),
			__( 'Rafoo does not collect or send any information about visitors. If the site administrator turns on anonymous usage reports, a weekly report is sent to rafoo.ir with a random site identifier, software versions and the names of installed plugins and themes. It never includes the site address or any personal data.', 'rafoo' ),
		);
		$content = '';
		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		wp_add_privacy_policy_content( 'Rafoo', wp_kses_post( $content ) );
	}
}
