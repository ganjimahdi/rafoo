<?php

namespace Rafoo\Backup;

use Rafoo\Admin\Rest;
use Rafoo\Backup\Remote\Detect;
use Rafoo\Backup\Remote\FilesIr;
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
			array( '/backup/check', WP_REST_Server::CREATABLE, 'check', $id + array( 'password' => $text ) ),
			array( '/backup/log', WP_REST_Server::READABLE, 'log', array() ),
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
			array(
				'/backup/files/connect',
				WP_REST_Server::CREATABLE,
				'files_connect',
				array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			),
			array(
				'/backup/files/enable',
				WP_REST_Server::CREATABLE,
				'files_enable',
				array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			),
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
		$space  = Ledger::space();
		return array(
			'job'          => Job::view(),
			'backups'      => Catalog::view(),
			'destinations' => Destinations::view(),
			'files'        => Destinations::files_view(),
			'next'         => (int) wp_next_scheduled( Schedule::HOOK ),
			'last'         => $latest ? (int) $latest['created'] : 0,
			'password'     => '' !== Maker::password(),
			'support'      => array(
				'zip'      => class_exists( 'ZipArchive' ),
				'gzip'     => Archive\Sink::gzip(),
				'part'     => Packer::target(),
				'excluded' => Files::excluded(),
				'crypt'    => Crypt::available(),
				'ftp'      => Ftp::available() || Remote::curl(),
				'implicit' => Remote::curl(),
				'free'     => $space['free'],
				'space'    => $space['source'],
				'writable' => '' !== Ledger::dir(),
				'stream'   => FilesIr::stream(),
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

	public static function check( WP_REST_Request $request ) {
		return self::answer( Restorer::check( $request['id'], (string) $request['password'] ) );
	}

	public static function log() {
		return array( 'log' => Journal::tail() );
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
		$job = Job::running();
		if ( $job && isset( $job['data']['backup'] ) && $job['data']['backup'] === $entry['id'] ) {
			return new WP_Error( 'rafoo_busy', __( 'Another backup task is running. Wait until it finishes.', 'rafoo' ), array( 'status' => 409 ) );
		}
		Maker::discard( $entry );
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
		$changes = array( 'speed' => $result['speed'], 'checked_at' => time(), 'last_error' => '' );
		if ( $remote instanceof FilesIr ) {
			$before = Destinations::get( $request['id'] );
			if ( $before && in_array( FilesIr::issue( $before['last_error'] ), array( 'size', 'space' ), true ) ) {
				unset( $changes['last_error'] );
			}
			$usage = $remote->usage();
			if ( $usage ) {
				$changes['free'] = $usage['free'];
				$changes['used'] = $usage['used'];
			}
		}
		Destinations::update( $request['id'], $changes );
		return self::status();
	}

	public static function files_connect( WP_REST_Request $request ) {
		$key   = trim( (string) $request['key'] );
		$found = FilesIr::probe( $key );
		if ( empty( $found['ok'] ) ) {
			$messages = array(
				'bad_key'      => __( 'This does not look like a Files.ir API key. Copy the whole key again from your Files.ir account.', 'rafoo' ),
				'rejected'     => __( 'Files.ir did not accept this API key. Check that it is active and has access to files.', 'rafoo' ),
				'no_workspace' => __( 'This API key has no access to a drive or workspace. Give it access in your Files.ir account and try again.', 'rafoo' ),
				'folder'       => __( 'The backup folder could not be created in Files.ir. Check that the key may create folders.', 'rafoo' ),
				'write'        => __( 'Files.ir accepted the key but a test file could not be uploaded and read back. Check the free space and the key permissions.', 'rafoo' ),
				'unreachable'  => __( 'Files.ir could not be reached from this server. Try again in a few minutes.', 'rafoo' ),
			);
			$message = isset( $messages[ $found['error'] ] ) ? $messages[ $found['error'] ] : $messages['unreachable'];
			return new WP_Error( 'rafoo_files', $message, array( 'status' => 400, 'detail' => isset( $found['detail'] ) ? (string) $found['detail'] : '' ) );
		}
		Destinations::add_files( $key, $found );
		\Rafoo\State::event( 'files_connected' );
		return self::status();
	}

	public static function files_enable( WP_REST_Request $request ) {
		if ( ! Destinations::get( FilesIr::ID ) ) {
			return new WP_Error( 'rafoo_not_found', __( 'Connect Files.ir with an API key first.', 'rafoo' ), array( 'status' => 404 ) );
		}
		Destinations::update( FilesIr::ID, array( 'enabled' => (bool) $request['enabled'] ) );
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
		$names = $entry ? Catalog::files( $entry ) : array();
		$index = isset( $_GET['part'] ) ? absint( $_GET['part'] ) : 0;
		$file  = isset( $names[ $index ] ) ? Ledger::path( $names[ $index ] ) : '';
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
