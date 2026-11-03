<?php

namespace Rafoo\Backup;

use Rafoo\Admin\Rest;
use Rafoo\Backup\Remote\Detect;
use Rafoo\Backup\Remote\Ftp;
use Rafoo\Backup\Remote\Remote;
use Rafoo\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Api {

	const DOWNLOAD = 'rafoo_backup_download';

	public static function routes( $namespace ) {
		$can  = array( Rest::class, 'can_manage_network' );
		$id   = array(
			'id' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[A-Za-z0-9\-]{1,40}$',
			),
		);
		$text = array( 'type' => 'string' );
		$list = array(
			array( '/backup', WP_REST_Server::READABLE, 'status', array() ),
			array( '/backup/run', WP_REST_Server::CREATABLE, 'run', array( 'full' => array( 'type' => 'boolean' ) ) ),
			array( '/backup/step', WP_REST_Server::CREATABLE, 'step', array() ),
			array( '/backup/cancel', WP_REST_Server::CREATABLE, 'cancel', array() ),
			array( '/backup/restore', WP_REST_Server::CREATABLE, 'restore', $id + array( 'password' => $text ) ),
			array( '/backup/delete', WP_REST_Server::CREATABLE, 'delete', $id ),
			array( '/backup/password', WP_REST_Server::CREATABLE, 'password', array( 'password' => $text ) ),
			array(
				'/backup/destinations/add',
				WP_REST_Server::CREATABLE,
				'add',
				array(
					'host'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'user'  => $text,
					'pass'  => $text,
					'key'   => $text,
					'path'  => $text,
					'label' => $text,
					'port'  => array( 'type' => 'integer' ),
					'type'  => array(
						'type' => 'string',
						'enum' => array( '', 'sftp', 'ftps', 'ftps-implicit', 'ftp' ),
					),
				),
			),
			array( '/backup/destinations/delete', WP_REST_Server::CREATABLE, 'remove', $id ),
			array( '/backup/destinations/test', WP_REST_Server::CREATABLE, 'test', $id ),
			array( '/transfer/invite', WP_REST_Server::CREATABLE, 'invite', array() ),
			array( '/transfer/invite/cancel', WP_REST_Server::CREATABLE, 'cancel_invite', array() ),
			array(
				'/transfer/send',
				WP_REST_Server::CREATABLE,
				'send',
				array(
					'code'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'final' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
			array(
				'/backup/move',
				WP_REST_Server::CREATABLE,
				'move',
				array(
					'destination' => array(
						'type'     => 'string',
						'required' => true,
					),
					'folder'      => $text,
					'url'         => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
		);
		foreach ( $list as $route ) {
			register_rest_route(
				$namespace,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( __CLASS__, $route[2] ),
					'permission_callback' => $can,
					'args'                => $route[3],
				)
			);
		}
	}

	public static function status() {
		$latest = Catalog::latest();
		return array(
			'job'          => Job::view(),
			'backups'      => Catalog::view(),
			'destinations' => Destinations::view(),
			'next'         => (int) wp_next_scheduled( Schedule::HOOK ),
			'last'         => $latest ? (int) $latest['created'] : 0,
			'password'     => '' !== Maker::password(),
			'support'      => array(
				'zip'      => class_exists( 'ZipArchive' ),
				'crypt'    => Crypt::available(),
				'ftp'      => Ftp::available() || Remote::curl(),
				'implicit' => Remote::curl(),
				'free'     => Ledger::free_space(),
				'writable' => '' !== Ledger::dir(),
			),
			'invite'       => Transfer::invite_view(),
			'frozen'       => Transfer::frozen(),
			'download'     => add_query_arg( array( 'action' => self::DOWNLOAD, '_wpnonce' => wp_create_nonce( self::DOWNLOAD ) ), admin_url( 'admin-post.php' ) ),
			'home'         => home_url(),
		);
	}

	public static function run( WP_REST_Request $request ) {
		$job = Maker::start(
			array(
				'full'   => (bool) $request['full'],
				'manual' => true,
			)
		);
		return self::answer( $job );
	}

	public static function step() {
		Job::tick( 8 );
		return self::status();
	}

	public static function cancel() {
		Job::cancel();
		return self::status();
	}

	public static function restore( WP_REST_Request $request ) {
		return self::answer( Restorer::start( $request['id'], (string) $request['password'] ) );
	}

	public static function delete( WP_REST_Request $request ) {
		$entry = Catalog::get( $request['id'] );
		if ( ! $entry ) {
			return new WP_Error( 'rafoo_not_found', __( 'This backup is no longer in the list.', 'rafoo' ), array( 'status' => 404 ) );
		}
		foreach ( Catalog::all() as $other ) {
			if ( isset( $other['parent'] ) && $other['parent'] === $entry['id'] ) {
				return new WP_Error( 'rafoo_needed', __( 'Newer backups are built on this one, so it cannot be deleted on its own.', 'rafoo' ), array( 'status' => 409 ) );
			}
		}
		wp_delete_file( Ledger::path( $entry['file'] ) );
		wp_delete_file( Ledger::path( $entry['id'] . '.list' ) );
		foreach ( array_keys( array_filter( (array) $entry['places'] ) ) as $target ) {
			$remote = Destinations::remote( $target );
			if ( $remote && $remote->connect() ) {
				$remote->delete( $entry['file'] );
				$remote->close();
			}
		}
		Catalog::forget( $entry['id'] );
		return self::status();
	}

	public static function password( WP_REST_Request $request ) {
		$password = (string) $request['password'];
		if ( '' !== $password && strlen( $password ) < 8 ) {
			return new WP_Error( 'rafoo_invalid', __( 'Use at least 8 characters.', 'rafoo' ), array( 'status' => 400 ) );
		}
		Maker::set_password( $password );
		return self::status();
	}

	public static function add( WP_REST_Request $request ) {
		$input = array_intersect_key( (array) $request->get_params(), array_flip( array( 'host', 'user', 'pass', 'key', 'path', 'label', 'port', 'type' ) ) );
		$found = Detect::run( $input );
		if ( empty( $found['ok'] ) ) {
			$message = 'login_failed' === $found['error']
				? __( 'The server answered but did not accept this username or password.', 'rafoo' )
				: ( 'bad_host' === $found['error'] ? __( 'Enter a server address such as backup.example.ir.', 'rafoo' ) : __( 'No SFTP, FTPS or FTP connection could be made to this server. Check the address and that the port is open.', 'rafoo' ) );
			return new WP_Error( 'rafoo_destination', $message, array( 'status' => 400, 'tried' => $found['tried'] ) );
		}
		$saved = Destinations::add( $input, $found );
		if ( ! $saved ) {
			return new WP_Error( 'rafoo_destination', __( 'Up to five servers can be added.', 'rafoo' ), array( 'status' => 400 ) );
		}
		return array_merge( self::status(), array( 'added' => $found ) );
	}

	public static function remove( WP_REST_Request $request ) {
		Destinations::remove( $request['id'] );
		return self::status();
	}

	public static function test( WP_REST_Request $request ) {
		$remote = Destinations::remote( $request['id'] );
		if ( ! $remote ) {
			return new WP_Error( 'rafoo_not_found', __( 'This server is no longer in the list.', 'rafoo' ), array( 'status' => 404 ) );
		}
		$result = $remote->connect() && $remote->prepare_folder() ? $remote->write_test() : false;
		$remote->close();
		if ( false === $result ) {
			Destinations::update( $request['id'], array( 'last_error' => $remote->error(), 'checked_at' => time() ) );
			return new WP_Error( 'rafoo_destination', __( 'The server could not be reached or did not accept a test file.', 'rafoo' ), array( 'status' => 502 ) );
		}
		Destinations::update( $request['id'], array( 'speed' => $result['speed'], 'checked_at' => time(), 'last_error' => '' ) );
		return self::status();
	}

	public static function invite() {
		return array_merge( self::status(), array( 'code' => Transfer::invite() ) );
	}

	public static function cancel_invite() {
		Transfer::cancel_invite();
		return self::status();
	}

	public static function send( WP_REST_Request $request ) {
		return self::answer( Sender::start( (string) $request['code'], (bool) $request['final'] ) );
	}

	public static function move( WP_REST_Request $request ) {
		return self::answer( Mover::start( (string) $request['destination'], (string) $request['folder'], (string) $request['url'] ) );
	}

	public static function download() {
		check_admin_referer( self::DOWNLOAD );
		if ( ! Rest::can_manage_network() ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		$entry = Catalog::get( sanitize_text_field( wp_unslash( isset( $_GET['id'] ) ? $_GET['id'] : '' ) ) );
		$file  = $entry ? Ledger::path( $entry['file'] ) : '';
		if ( '' === $file || ! is_file( $file ) ) {
			wp_die( esc_html__( 'This backup is no longer in the list.', 'rafoo' ), '', array( 'response' => 404 ) );
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		$in = Ledger::open( $file, 'rb' );
		if ( $in ) {
			$in->fpassthru();
		}
		exit;
	}

	private static function answer( $job ) {
		if ( is_wp_error( $job ) ) {
			$job->add_data( array( 'status' => 409 ) );
			return $job;
		}
		return self::status();
	}
}
