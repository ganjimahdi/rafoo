<?php

namespace Rafoo\Mail;

use Rafoo\Assets\Store;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class Queue {

	const OPTION = 'rafoo_mail_queue';

	const HOOK = 'rafoo_mail';

	const DOWN = 'rafoo_mail_down';

	const LOCK = 'rafoo_mail_lock';

	const FOLDER = 'mail';

	const MAX_ITEMS = 200;

	const MAX_MESSAGE = 1048576;

	const MAX_ATTACHMENTS = 10485760;

	const KEEP = 259200;

	const BUDGET = 20;

	const DELAYS = array( 300, 900, 1800, 3600, 7200, 14400, 28800, 43200 );

	const NETWORK_ERRORS = array( 'smtp connect', 'could not connect', 'connection', 'timed out', 'timeout', 'getaddrinfo', 'network is unreachable', 'stream_socket_client', 'failed to connect', 'could not resolve', 'curl error', 'rafoo_offline', 'rafoo_unreachable', 'international internet' );

	private static $current = null;

	private static $retrying = false;

	private static $error = '';

	private static $forced = false;

	public static function enabled() {
		return (bool) Settings::get( 'mail_queue' );
	}

	public static function remember( $atts ) {
		if ( ! self::$retrying && is_array( $atts ) ) {
			self::$current = $atts;
		}
		return $atts;
	}

	public static function pre( $result, $atts ) {
		if ( null !== $result || self::$retrying || ! self::enabled() || ! State::offline() || ! get_transient( self::DOWN ) || Relay::configured() ) {
			return $result;
		}
		return self::add( is_array( $atts ) ? $atts : array(), __( 'The mail server could not be reached during the outage.', 'rafoo' ) ) ? true : $result;
	}

	public static function mailer( $phpmailer ) {
		if ( ! is_object( $phpmailer ) ) {
			return;
		}
		if ( self::$forced || ( State::offline() && Relay::wanted( $phpmailer ) ) ) {
			Relay::apply( $phpmailer );
			return;
		}
		if ( State::offline() && isset( $phpmailer->Mailer ) && 'smtp' === $phpmailer->Mailer ) {
			$timeout            = (int) $phpmailer->Timeout;
			$phpmailer->Timeout = $timeout > 0 && $timeout < 15 ? $timeout : 15;
		}
	}

	public static function failed( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : '';
		if ( self::$retrying ) {
			self::$error = $message;
			return;
		}
		$atts          = self::$current;
		self::$current = null;
		if ( ! self::enabled() || ! is_array( $atts ) || ! ( self::network_error( $message ) || ( State::offline() && ! self::rejected( $message ) ) ) ) {
			return;
		}
		if ( State::offline() ) {
			set_transient( self::DOWN, 1, 10 * MINUTE_IN_SECONDS );
		}
		self::add( $atts, $message );
	}

	public static function sent() {
		if ( ! self::$retrying ) {
			self::$current = null;
		}
	}

	public static function network_error( $message ) {
		$message = strtolower( (string) $message );
		foreach ( self::NETWORK_ERRORS as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function rejected( $message ) {
		$message = strtolower( (string) $message );
		foreach ( array( 'invalid address', 'at least one recipient', 'empty message body' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public static function add( array $atts, $error = '' ) {
		$items = self::items();
		if ( count( $items ) >= self::MAX_ITEMS || empty( $atts['to'] ) ) {
			return false;
		}
		$message = isset( $atts['message'] ) ? (string) $atts['message'] : '';
		if ( strlen( $message ) > self::MAX_MESSAGE ) {
			return false;
		}
		$id    = strtolower( wp_generate_password( 20, false ) );
		$files = self::keep_attachments( $id, isset( $atts['attachments'] ) ? $atts['attachments'] : array() );
		if ( false === $files ) {
			return false;
		}
		$now          = time();
		$items[ $id ] = array(
			'id'          => $id,
			'to'          => $atts['to'],
			'subject'     => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'message'     => $message,
			'headers'     => isset( $atts['headers'] ) ? $atts['headers'] : array(),
			'attachments' => $files,
			'created'     => $now,
			'attempts'    => 0,
			'next'        => $now + self::DELAYS[0],
			'error'       => substr( wp_strip_all_tags( (string) $error ), 0, 300 ),
		);
		self::store( $items );
		self::schedule( $now + self::DELAYS[0] );
		return true;
	}

	public static function run( $force = false ) {
		if ( get_transient( self::LOCK ) ) {
			return array(
				'sent'    => 0,
				'waiting' => count( self::items() ),
			);
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		if ( $force ) {
			delete_transient( self::DOWN );
		}
		$started = time();
		$sent    = 0;
		$dropped = 0;
		$items   = self::items();
		$blocked = State::offline() && get_transient( self::DOWN ) && ! Relay::configured();
		foreach ( $items as $id => $item ) {
			if ( time() - $started > self::BUDGET ) {
				break;
			}
			if ( $started - (int) $item['created'] > self::KEEP ) {
				self::forget_attachments( $id );
				unset( $items[ $id ] );
				++$dropped;
				continue;
			}
			if ( ! $force && (int) $item['next'] > $started ) {
				continue;
			}
			if ( $blocked ) {
				$items[ $id ] = self::postpone( $item, $started );
				continue;
			}
			if ( self::deliver( $item ) ) {
				self::forget_attachments( $id );
				unset( $items[ $id ] );
				++$sent;
				continue;
			}
			$items[ $id ] = self::postpone( $item, $started, self::$error );
			if ( self::network_error( self::$error ) || State::offline() ) {
				$blocked = true;
				if ( State::offline() ) {
					set_transient( self::DOWN, 1, 10 * MINUTE_IN_SECONDS );
				}
			}
		}
		self::store( $items );
		delete_transient( self::LOCK );
		if ( $sent > 0 ) {
			State::event( 'mail_sent', array( 'count' => $sent ) );
		}
		if ( $dropped > 0 ) {
			State::event( 'mail_dropped', array( 'count' => $dropped ) );
		}
		self::reschedule( $items );
		return array(
			'sent'    => $sent,
			'waiting' => count( $items ),
		);
	}

	public static function test( $to ) {
		self::$forced   = true;
		self::$retrying = true;
		self::$error    = '';
		$ok             = wp_mail(
			$to,
			__( 'Rafoo test message', 'rafoo' ),
			__( 'This message was sent through the mail server you set in Rafoo for outages.', 'rafoo' )
		);
		self::$forced   = false;
		self::$retrying = false;
		return $ok ? true : new \WP_Error( 'rafoo_mail_test', '' !== self::$error ? self::$error : __( 'The message could not be sent.', 'rafoo' ) );
	}

	public static function delete( $id ) {
		$items = self::items();
		if ( ! isset( $items[ $id ] ) ) {
			return false;
		}
		self::forget_attachments( $id );
		unset( $items[ $id ] );
		self::store( $items );
		self::reschedule( $items );
		return true;
	}

	public static function items() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function view() {
		$out = array();
		foreach ( self::items() as $item ) {
			$to    = is_array( $item['to'] ) ? implode( ', ', $item['to'] ) : (string) $item['to'];
			$out[] = array(
				'id'       => (string) $item['id'],
				'to'       => $to,
				'subject'  => (string) $item['subject'],
				'attempts' => (int) $item['attempts'],
				'created'  => (int) $item['created'],
				'next'     => (int) $item['next'],
				'error'    => (string) $item['error'],
			);
		}
		return $out;
	}

	public static function down() {
		return (bool) get_transient( self::DOWN );
	}

	public static function soon() {
		if ( self::items() ) {
			delete_transient( self::DOWN );
			self::schedule( time() + 30 );
		}
	}

	private static function deliver( array $item ) {
		self::$retrying = true;
		self::$error    = '';
		$attachments    = array();
		foreach ( (array) $item['attachments'] as $name => $relative ) {
			$path = Store::dir( self::FOLDER . '/' . $relative );
			if ( is_file( $path ) ) {
				$attachments[ $name ] = $path;
			}
		}
		$ok             = wp_mail( $item['to'], $item['subject'], $item['message'], $item['headers'], $attachments );
		self::$retrying = false;
		return $ok;
	}

	private static function postpone( array $item, $now, $error = null ) {
		$attempt          = (int) $item['attempts'] + ( null === $error ? 0 : 1 );
		$delay            = self::DELAYS[ min( $attempt, count( self::DELAYS ) - 1 ) ];
		$item['attempts'] = $attempt;
		$item['next']     = $now + $delay;
		if ( null !== $error && '' !== $error ) {
			$item['error'] = substr( wp_strip_all_tags( $error ), 0, 300 );
		}
		return $item;
	}

	private static function schedule( $at ) {
		$next = wp_next_scheduled( self::HOOK );
		if ( false !== $next && $next <= $at ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( max( time(), (int) $at ), self::HOOK );
	}

	private static function reschedule( array $items ) {
		if ( ! $items ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}
		$earliest = min( wp_list_pluck( $items, 'next' ) );
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( max( time() + 30, (int) $earliest ), self::HOOK );
	}

	private static function store( array $items ) {
		if ( $items ) {
			update_option( self::OPTION, $items, false );
		} else {
			delete_option( self::OPTION );
		}
	}

	private static function keep_attachments( $id, $attachments ) {
		if ( is_string( $attachments ) ) {
			$attachments = '' === trim( $attachments ) ? array() : explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}
		if ( ! is_array( $attachments ) || ! $attachments ) {
			return array();
		}
		$total = 0;
		foreach ( $attachments as $path ) {
			if ( ! is_string( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				return false;
			}
			$total += (int) filesize( $path );
		}
		if ( $total > self::MAX_ATTACHMENTS || ! self::ensure_folder() ) {
			return false;
		}
		$fs     = Store::fs();
		$folder = Store::dir( self::FOLDER . '/' . $id );
		if ( ! wp_mkdir_p( $folder ) ) {
			return false;
		}
		$kept = array();
		$used = array();
		foreach ( $attachments as $name => $path ) {
			$file = sanitize_file_name( wp_basename( $path ) );
			$file = '' === $file ? 'attachment' : $file;
			while ( isset( $used[ $file ] ) ) {
				$file = wp_unique_id( 'a' ) . '-' . $file;
			}
			$used[ $file ] = true;
			if ( ! $fs->copy( $path, $folder . '/' . $file, true, Store::mode() ) ) {
				self::forget_attachments( $id );
				return false;
			}
			$kept[ is_string( $name ) ? $name : count( $kept ) ] = $id . '/' . $file;
		}
		return $kept;
	}

	private static function forget_attachments( $id ) {
		$folder = Store::dir( self::FOLDER . '/' . sanitize_key( $id ) );
		if ( is_dir( $folder ) ) {
			Store::fs()->rmdir( $folder, true );
		}
	}

	private static function ensure_folder() {
		if ( ! Store::ensure() ) {
			return false;
		}
		$folder = Store::dir( self::FOLDER );
		if ( ! wp_mkdir_p( $folder ) ) {
			return false;
		}
		$fs = Store::fs();
		if ( ! file_exists( $folder . '/.htaccess' ) ) {
			$fs->put_contents( $folder . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n", Store::mode() );
		}
		if ( ! file_exists( $folder . '/index.php' ) ) {
			$fs->put_contents( $folder . '/index.php', "<?php\n", Store::mode() );
		}
		return true;
	}
}
