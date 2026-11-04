<?php

namespace Rafoo\Admin;

use Rafoo\Peers\Engine as PeerEngine;
use Rafoo\Assets\Library;
use Rafoo\Assets\Loopback;
use Rafoo\Assets\Scanner;
use Rafoo\Assets\Store;
use Rafoo\Assets\Sync;
use Rafoo\Cache\Purge;
use Rafoo\Connectivity\Monitor;
use Rafoo\Http\Breaker;
use Rafoo\Http\Hosts;
use Rafoo\Http\Log;
use Rafoo\Mail\Queue;
use Rafoo\Mail\Relay;
use Rafoo\Network;
use Rafoo\Plugin;
use Rafoo\Report\Telemetry;
use Rafoo\Safety\Health;
use Rafoo\Safety\Rollback;
use Rafoo\Settings;
use Rafoo\Signature;
use Rafoo\State;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'rafoo/v1';

	public static function register() {
		$site    = array( __CLASS__, 'can_manage' );
		$network = array( __CLASS__, 'can_manage_network' );
		$key     = array(
			'key' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( __CLASS__, 'clean_key' ),
			),
		);

		self::route( '/overview', WP_REST_Server::READABLE, 'overview', $site );
		self::route(
			'/mode',
			WP_REST_Server::CREATABLE,
			'mode',
			$network,
			array(
				'mode' => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'auto', 'offline', 'online' ),
				),
			)
		);
		self::route( '/check', WP_REST_Server::CREATABLE, 'check', $site );
		self::route( '/assets', WP_REST_Server::READABLE, 'assets', $site );
		self::route( '/assets/sync', WP_REST_Server::CREATABLE, 'sync', $site );
		self::route( '/assets/scan', WP_REST_Server::CREATABLE, 'scan', $site );
		self::route( '/assets/retry', WP_REST_Server::CREATABLE, 'retry', $site, $key );
		self::route( '/assets/remove', WP_REST_Server::CREATABLE, 'remove', $site, $key );
		self::route(
			'/assets/exclude',
			WP_REST_Server::CREATABLE,
			'exclude',
			$site,
			array_merge(
				$key,
				array(
					'excluded' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				)
			)
		);
		self::route( '/settings', WP_REST_Server::READABLE, 'settings', $site );
		self::route( '/settings', WP_REST_Server::EDITABLE, 'save_settings', $site );
		self::route( '/purge', WP_REST_Server::CREATABLE, 'purge', $site );
		self::route(
			'/setup',
			WP_REST_Server::CREATABLE,
			'setup',
			$site,
			array(
				'preset'   => array(
					'type' => 'string',
					'enum' => array( 'recommended', 'outage', 'maximum', 'custom', 'skip' ),
				),
				'settings' => array( 'type' => 'object' ),
			)
		);
		$updates = array( __CLASS__, 'can_update' );
		self::route( '/rollback', WP_REST_Server::READABLE, 'rollback', $updates );
		self::route(
			'/rollback/restore',
			WP_REST_Server::CREATABLE,
			'restore',
			$updates,
			array(
				'type'    => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'plugin', 'theme' ),
				),
				'slug'    => array(
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[A-Za-z0-9._\-]{1,190}$',
				),
				'version' => array(
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[A-Za-z0-9._\-+]{1,60}$',
				),
			)
		);
		self::route( '/health', WP_REST_Server::CREATABLE, 'health', $site );
		self::route( '/requests', WP_REST_Server::READABLE, 'requests', $site );
		self::route( '/requests/clear', WP_REST_Server::CREATABLE, 'clear_requests', $site );
		self::route(
			'/requests/block',
			WP_REST_Server::CREATABLE,
			'block_host',
			$site,
			array(
				'host'    => array(
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[A-Za-z0-9.\-]{1,253}$',
				),
				'blocked' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			)
		);
		self::route(
			'/requests/browser',
			WP_REST_Server::CREATABLE,
			'browser_report',
			$site,
			array(
				'hosts' => array(
					'type'     => 'array',
					'required' => true,
					'maxItems' => 20,
					'items'    => array(
						'type'    => 'string',
						'pattern' => '^[A-Za-z0-9.\-]{1,253}$',
					),
				),
			)
		);
		self::route(
			'/pause',
			WP_REST_Server::CREATABLE,
			'pause',
			$network,
			array(
				'paused' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			)
		);
		self::route( '/mail', WP_REST_Server::READABLE, 'mail', $site );
		self::route( '/mail/send', WP_REST_Server::CREATABLE, 'send_mail', $site );
		self::route( '/mail/test', WP_REST_Server::CREATABLE, 'test_mail', $site );
		self::route(
			'/mail/delete',
			WP_REST_Server::CREATABLE,
			'delete_mail',
			$site,
			array(
				'id' => array(
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[a-z0-9]{20}$',
				),
			)
		);
		self::route(
			'/mail/relay',
			WP_REST_Server::CREATABLE,
			'save_relay',
			$site,
			array(
				'host'   => array( 'type' => 'string' ),
				'port'   => array( 'type' => 'integer' ),
				'secure' => array(
					'type' => 'string',
					'enum' => Relay::SECURE,
				),
				'user'   => array( 'type' => 'string' ),
				'pass'   => array( 'type' => 'string' ),
				'from'   => array( 'type' => 'string' ),
			)
		);
		self::route(
			'/feedback',
			WP_REST_Server::CREATABLE,
			'feedback',
			$site,
			array(
				'message' => array(
					'type'     => 'string',
					'required' => true,
				),
				'contact' => array(
					'type'    => 'string',
					'default' => '',
				),
			)
		);
		$interval = array(
			'interval' => array(
				'type'    => 'integer',
				'enum'    => array( 60, 300 ),
				'default' => 300,
			),
		);
		self::route( '/peers', WP_REST_Server::READABLE, 'peers', $site );
		self::route( '/peers/join', WP_REST_Server::CREATABLE, 'peers_join', $site, $interval );
		self::route( '/peers/interval', WP_REST_Server::CREATABLE, 'peers_interval', $site, $interval );
		self::route( '/peers/leave', WP_REST_Server::CREATABLE, 'peers_leave', $site );
		self::route( '/peers/check', WP_REST_Server::CREATABLE, 'peers_check', $site );
		if ( is_multisite() ) {
			self::route( '/network', WP_REST_Server::READABLE, 'network', $network );
			self::route(
				'/network',
				WP_REST_Server::CREATABLE,
				'save_network',
				$network,
				array(
					'policy'   => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Network::POLICIES,
					),
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
				)
			);
		}

		do_action( 'rafoo_rest_routes', self::NS );
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public static function can_manage_network() {
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	public static function clean_key( $value ) {
		$value = (string) $value;
		return preg_match( '#^[A-Za-z0-9@._~+\-/?&=:;,%|]{3,600}$#', $value ) ? $value : '';
	}

	public static function overview() {
		$state = State::all();
		return array(
			'mode'         => State::offline() ? 'offline' : 'online',
			'detected'     => $state['detected'],
			'override'     => $state['override'],
			'checked_at'   => (int) $state['checked_at'],
			'changed_at'   => (int) $state['changed_at'],
			'outage_total' => (int) $state['outage_total'],
			'local_down'   => (bool) $state['local_down'],
			'targets'      => $state['targets'],
			'hint'         => $state['hint'] ? $state['hint'] : null,
			'signature'    => Signature::available(),
			'assets'       => Library::summary(),
			'breaker'      => Breaker::snapshot(),
			'events'       => State::events( 10 ),
			'edition'      => RAFOO_EDITION,
			'version'      => RAFOO_VERSION,
			'paused'       => Settings::paused(),
			'network'      => self::can_manage_network(),
			'cron'         => array(
				'disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_check' => (int) wp_next_scheduled( Monitor::HOOK ),
				'late'       => $state['checked_at'] > 0 && time() - $state['checked_at'] > 600,
			),
			'now'          => time(),
		);
	}

	public static function mode( WP_REST_Request $request ) {
		State::set_override( $request['mode'] );
		Purge::later();
		return self::overview();
	}

	public static function check() {
		Monitor::run( true );
		return self::overview();
	}

	public static function assets() {
		$excluded = Settings::get( 'exclude' );
		$out      = array();
		foreach ( Library::records() as $key => $record ) {
			$out[] = array(
				'key'        => $key,
				'origin'     => $record['origin'],
				'local'      => 'ready' === $record['status'] ? Store::url( $record['local'] ) : '',
				'kind'       => $record['kind'],
				'type'       => $record['type'],
				'cdn'        => $record['cdn'],
				'package'    => $record['pkg'],
				'version'    => $record['ver'],
				'status'     => in_array( $record['origin'], $excluded, true ) ? 'excluded' : $record['status'],
				'verified'   => $record['verified'],
				'source'     => $record['source'],
				'size'       => (int) $record['size'],
				'files'      => 1 + count( $record['deps'] ),
				'error'      => $record['error'],
				'updated_at' => (int) $record['updated_at'],
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['origin'], $b['origin'] );
			}
		);
		return $out;
	}

	public static function sync() {
		delete_transient( Sync::LOCK );
		$result = Sync::run();
		if ( ! empty( $result['remaining'] ) ) {
			Library::schedule_soon();
		}
		return array(
			'result' => $result,
			'assets' => self::assets(),
		);
	}

	public static function scan() {
		Scanner::run( Settings::get( 'scan_as_user' ) ? Loopback::cookies() : array() );
		Library::schedule_soon();
		return array( 'assets' => self::assets() );
	}

	public static function retry( WP_REST_Request $request ) {
		if ( ! Library::retry( $request['key'] ) ) {
			return self::not_found();
		}
		return array( 'assets' => self::assets() );
	}

	public static function remove( WP_REST_Request $request ) {
		if ( ! Library::remove( $request['key'] ) ) {
			return self::not_found();
		}
		return array( 'assets' => self::assets() );
	}

	public static function exclude( WP_REST_Request $request ) {
		$records = Library::records();
		$key     = $request['key'];
		if ( ! isset( $records[ $key ] ) ) {
			return self::not_found();
		}
		$origin   = $records[ $key ]['origin'];
		$excluded = array_values( array_diff( Settings::get( 'exclude' ), array( $origin ) ) );
		if ( $request['excluded'] ) {
			$excluded[] = $origin;
		}
		Settings::update( array( 'exclude' => $excluded ) );
		Library::rebuild( $records );
		return array( 'assets' => self::assets() );
	}

	public static function settings() {
		return self::public_settings();
	}

	public static function save_settings( WP_REST_Request $request ) {
		$changes = array_intersect_key( (array) $request->get_json_params(), array_flip( Settings::EDITABLE ) );
		if ( $changes && 'done' === Settings::get( 'setup' ) && '' !== Settings::get( 'preset' ) ) {
			$changes['preset'] = 'custom';
		}
		return self::apply( $changes );
	}

	public static function setup( WP_REST_Request $request ) {
		$preset   = (string) $request['preset'];
		$settings = is_array( $request['settings'] ) ? $request['settings'] : array();
		if ( 'skip' === $preset ) {
			return self::apply( array( 'setup' => 'skipped' ) );
		}
		$changes = 'custom' === $preset
			? array_intersect_key( $settings, array_flip( Settings::EDITABLE ) )
			: Settings::preset( $preset );
		if ( array_key_exists( 'telemetry', $settings ) ) {
			$changes['telemetry'] = $settings['telemetry'];
		}
		$changes['setup']  = 'done';
		$changes['preset'] = $preset;
		$result            = self::apply( $changes );
		if ( ! wp_next_scheduled( 'rafoo_scan' ) ) {
			wp_schedule_single_event( time(), 'rafoo_scan' );
		}
		State::event( 'setup', array( 'preset' => $preset ) );
		return $result;
	}

	private static function apply( array $changes ) {
		$before  = Settings::all();
		$after   = Settings::update( $changes );

		if ( $after['telemetry'] && ! $before['telemetry'] ) {
			Telemetry::ping( 'consent' );
			wp_schedule_single_event( time() + 5, Plugin::DAILY, array( 'consent' ) );
		}
		if ( ! $after['telemetry'] && $before['telemetry'] ) {
			Telemetry::forget();
		}
		if ( $after !== $before ) {
			Library::rebuild( Library::records() );
			Purge::later();
			do_action( 'rafoo_settings_changed', $after, $before );
		}
		return self::public_settings();
	}

	public static function can_update() {
		return current_user_can( 'manage_options' ) && ( current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) );
	}

	public static function rollback() {
		return array(
			'enabled'   => Rollback::enabled(),
			'available' => class_exists( 'ZipArchive' ),
			'items'     => Rollback::listing(),
		);
	}

	public static function restore( WP_REST_Request $request ) {
		$type = $request['type'];
		if ( ! current_user_can( 'theme' === $type ? 'update_themes' : 'update_plugins' ) ) {
			return new WP_Error( 'rafoo_forbidden', __( 'You are not allowed to change this.', 'rafoo' ), array( 'status' => 403 ) );
		}
		if ( ! Rollback::restore( $type, $request['slug'], $request['version'] ) ) {
			return new WP_Error( 'rafoo_restore_failed', __( 'The previous version could not be put back. The files were left as they were.', 'rafoo' ), array( 'status' => 500 ) );
		}
		$results = Health::probe();
		return array_merge(
			self::rollback(),
			array(
				'healthy' => Health::healthy( $results ),
				'reason'  => Health::summary( $results ),
			)
		);
	}

	public static function health() {
		$results = Health::probe();
		return array(
			'healthy' => Health::healthy( $results ),
			'reason'  => Health::summary( $results ),
			'checks'  => $results,
		);
	}

	public static function requests() {
		return array(
			'enabled' => Log::enabled(),
			'items'   => Log::report(),
		);
	}

	public static function clear_requests() {
		Log::clear();
		return self::requests();
	}

	public static function block_host( WP_REST_Request $request ) {
		$host  = strtolower( (string) $request['host'] );
		$rules = array_values( array_diff( Settings::get( 'block_hosts' ), array( $host ) ) );
		if ( $request['blocked'] ) {
			$rules[] = $host;
		}
		self::apply( array( 'block_hosts' => $rules ) );
		return array_merge( self::requests(), array( 'block_hosts' => Settings::get( 'block_hosts' ) ) );
	}

	public static function browser_report( WP_REST_Request $request ) {
		foreach ( array_slice( (array) $request['hosts'], 0, 20 ) as $host ) {
			$host = strtolower( (string) $host );
			if ( '' !== $host && ! Hosts::local( $host ) ) {
				Log::record( $host, 'refused', array(), 'browser' );
			}
		}
		return array( 'ok' => true );
	}

	public static function pause( WP_REST_Request $request ) {
		$paused = (bool) $request['paused'];
		if ( $paused !== Settings::paused() ) {
			Settings::update( array( 'paused' => $paused ) );
			State::event( $paused ? 'paused' : 'resumed' );
			Purge::later();
		}
		return self::overview();
	}

	public static function mail() {
		return array(
			'enabled' => Queue::enabled(),
			'down'    => Queue::down(),
			'queue'   => Queue::view(),
			'relay'   => Relay::view(),
			'admin'   => (string) get_option( 'admin_email' ),
		);
	}

	public static function send_mail() {
		Queue::run( true );
		return self::mail();
	}

	public static function delete_mail( WP_REST_Request $request ) {
		if ( ! Queue::delete( $request['id'] ) ) {
			return new WP_Error( 'rafoo_not_found', __( 'This message is no longer waiting.', 'rafoo' ), array( 'status' => 404 ) );
		}
		return self::mail();
	}

	public static function save_relay( WP_REST_Request $request ) {
		$in = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'host', 'port', 'secure', 'user', 'pass', 'from' ) ) );
		if ( isset( $in['host'] ) && '' !== trim( (string) $in['host'] ) && '' === Relay::normalize( $in )['host'] ) {
			return new WP_Error( 'rafoo_invalid', __( 'Enter a valid mail server address, for example mail.example.ir.', 'rafoo' ), array( 'status' => 400 ) );
		}
		Relay::save( $in );
		return self::mail();
	}

	public static function test_mail() {
		if ( ! Relay::configured() ) {
			return new WP_Error( 'rafoo_invalid', __( 'Save a mail server first.', 'rafoo' ), array( 'status' => 400 ) );
		}
		$result = Queue::test( (string) get_option( 'admin_email' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'rafoo_mail_test', $result->get_error_message(), array( 'status' => 502 ) );
		}
		return array( 'sent' => true );
	}

	public static function purge() {
		return array( 'purged' => Purge::all() );
	}

	public static function peers( WP_REST_Request $request ) {
		return PeerEngine::status( ! empty( $request['fresh'] ) );
	}

	public static function peers_join( WP_REST_Request $request ) {
		$result = PeerEngine::join( (int) $request['interval'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}
		return PeerEngine::status( true );
	}

	public static function peers_interval( WP_REST_Request $request ) {
		PeerEngine::set_interval( (int) $request['interval'] );
		return PeerEngine::status( true );
	}

	public static function peers_leave() {
		PeerEngine::leave();
		return PeerEngine::status();
	}

	public static function peers_check() {
		PeerEngine::tick( true );
		return PeerEngine::status( true );
	}

	public static function feedback( WP_REST_Request $request ) {
		$message = trim( sanitize_textarea_field( $request['message'] ) );
		$contact = trim( sanitize_text_field( $request['contact'] ) );
		if ( mb_strlen( $message ) < 5 || mb_strlen( $message ) > 2000 || mb_strlen( $contact ) > 200 ) {
			return new WP_Error( 'rafoo_invalid', __( 'Please write between 5 and 2000 characters.', 'rafoo' ), array( 'status' => 400 ) );
		}
		$throttle = 'rafoo_feedback';
		if ( get_transient( $throttle ) ) {
			return new WP_Error( 'rafoo_slow_down', __( 'Please wait a minute before sending another message.', 'rafoo' ), array( 'status' => 429 ) );
		}
		if ( ! Telemetry::feedback( $message, $contact ) ) {
			return new WP_Error( 'rafoo_unreachable', __( 'rafoo.ir could not be reached. Please try again later.', 'rafoo' ), array( 'status' => 502 ) );
		}
		set_transient( $throttle, 1, MINUTE_IN_SECONDS );
		return array( 'sent' => true );
	}

	public static function public_settings() {
		$settings = Settings::all();
		unset( $settings['npm_mirrors'], $settings['wp_mirrors'], $settings['exclude'] );
		return $settings;
	}

	public static function network() {
		return Network::view();
	}

	public static function save_network( WP_REST_Request $request ) {
		$settings = $request['settings'];
		return Network::save( (string) $request['policy'], is_array( $settings ) ? $settings : array() );
	}

	public static function managed() {
		return array(
			'enforced' => Network::enforced(),
			'keys'     => Network::enforced() ? Network::keys() : array(),
			'url'      => is_multisite() && self::can_manage_network() ? network_admin_url( 'admin.php?page=' . Page::SLUG ) : '',
		);
	}

	private static function route( $path, $methods, $handler, $permission, array $args = array() ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( __CLASS__, $handler ),
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}

	private static function not_found() {
		return new WP_Error( 'rafoo_not_found', __( 'This file is no longer in the list.', 'rafoo' ), array( 'status' => 404 ) );
	}
}
