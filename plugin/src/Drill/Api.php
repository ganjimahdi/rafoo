<?php

namespace Rafoo\Drill;

use Rafoo\Admin\Rest;
use Rafoo\Assets\Fonts;
use Rafoo\Assets\Library;
use Rafoo\Assets\Loopback;
use Rafoo\Assets\Recognizer;
use Rafoo\Assets\Scanner;
use Rafoo\Assets\Sync;
use Rafoo\Cache\Purge;
use Rafoo\Http\Hosts;
use Rafoo\Plugin;
use Rafoo\Settings;
use Rafoo\State;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Api {

	const REPORT = 'rafoo_drill_report';

	const HISTORY = 'rafoo_drill_history';

	const PREFS = 'rafoo_drill_prefs';

	const KEEP = 10;

	const ACTIONS = array( 'download', 'include', 'setting', 'block', 'allow', 'scan', 'sync', 'resume' );

	const FRAMES = array( 'ok', 'blocked', 'timeout', 'error', 'skipped' );

	public static function routes( $namespace ) {
		$can = array( Rest::class, 'can_manage' );
		register_rest_route(
			$namespace,
			'/drill',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'view' ),
				'permission_callback' => $can,
			)
		);
		register_rest_route(
			$namespace,
			'/drill/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => $can,
				'args'                => array(
					'kinds'   => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => 20,
						'items'    => array(
							'type' => 'string',
							'enum' => Plan::KINDS,
						),
					),
					'custom'  => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => Plan::CUSTOM,
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
					),
					'guest'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'manual'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'compare' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/drill/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'finish' ),
				'permission_callback' => $can,
				'args'                => array(
					'run'     => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9]{8}$',
					),
					'results' => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => 20,
						'items'    => array( 'type' => 'object' ),
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/drill/fix',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'fix' ),
				'permission_callback' => $can,
				'args'                => array(
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => self::ACTIONS,
					),
					'target' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 600,
					),
					'issue'  => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^([a-f0-9]{12}|[a-z_]{1,30})?$',
					),
				),
			)
		);
		register_rest_route(
			$namespace,
			'/drill/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => $can,
			)
		);
	}

	public static function view() {
		$session = Session::current();
		return array(
			'active'     => $session ? self::public_session( $session ) : null,
			'candidates' => Plan::candidates(),
			'prefs'      => self::prefs(),
			'readiness'  => Plan::readiness(),
			'report'     => self::report(),
			'history'    => self::history(),
			'paused'     => Settings::paused(),
			'network'    => Rest::can_manage_network(),
			'offline'    => State::offline(),
			'edition'    => RAFOO_EDITION,
			'ttl'        => Session::TTL,
			'now'        => time(),
		);
	}

	public static function start( WP_REST_Request $request ) {
		if ( Settings::paused() ) {
			return new WP_Error( 'rafoo_paused', __( 'Rafoo is paused. Resume it first, then run the rehearsal.', 'rafoo' ), array( 'status' => 409 ) );
		}
		$manual = (bool) $request['manual'];
		$kinds  = array_values( array_intersect( (array) $request['kinds'], Plan::KINDS ) );
		$custom = Plan::custom( (array) $request['custom'] );
		$pages  = $manual ? array() : Plan::build( $kinds, $custom );
		if ( ! $manual && ! $pages ) {
			return new WP_Error( 'rafoo_invalid', __( 'Choose at least one page to test.', 'rafoo' ), array( 'status' => 400 ) );
		}
		$prefs          = self::prefs();
		$prefs['guest'] = (bool) $request['guest'];
		if ( ! $manual ) {
			$prefs['kinds']   = $kinds;
			$prefs['custom']  = $custom;
			$prefs['compare'] = (bool) $request['compare'];
		}
		update_option( self::PREFS, $prefs, false );
		$session = Session::open( $pages, (bool) $request['guest'], $manual );
		State::event( 'drill_started', array( 'pages' => count( $pages ) ) );
		return self::view_with( $session );
	}

	public static function finish( WP_REST_Request $request ) {
		$session = Session::stored();
		if ( ! $session || $session['run'] !== $request['run'] ) {
			return new WP_Error( 'rafoo_drill_gone', __( 'This rehearsal has already ended. Start a new one.', 'rafoo' ), array( 'status' => 409 ) );
		}
		$pages = array();
		foreach ( (array) $session['pages'] as $id => $page ) {
			$pages[ (int) $id ] = array_merge(
				$page,
				array(
					'client' => null,
					'base'   => null,
					'frame'  => 'skipped',
				)
			);
		}
		foreach ( (array) $request['results'] as $result ) {
			if ( ! is_array( $result ) || ! isset( $result['id'] ) || ! isset( $pages[ (int) $result['id'] ] ) ) {
				continue;
			}
			$id                   = (int) $result['id'];
			$frame                = isset( $result['frame'] ) ? (string) $result['frame'] : 'ok';
			$pages[ $id ]['frame'] = in_array( $frame, self::FRAMES, true ) ? $frame : 'error';
			if ( ! empty( $result['client'] ) && is_array( $result['client'] ) ) {
				$pages[ $id ]['client'] = Beacon::clean( $result['client'] );
			}
			if ( ! empty( $result['base'] ) && is_array( $result['base'] ) ) {
				$pages[ $id ]['base'] = Beacon::clean( $result['base'] );
			}
		}
		$captures = Session::captures( $session['run'] );
		$context  = array(
			'settings' => Settings::all(),
			'records'  => Library::records(),
			'excluded' => Settings::get( 'exclude' ),
			'manual'   => ! empty( $session['manual'] ),
			'beacons'  => $captures['c'],
		);
		$draft  = Classify::report( $pages, $captures['s'], $context );
		$search = array();
		foreach ( $draft['issues'] as $issue ) {
			if ( 'handled' === $issue['sev'] || $issue['origins'] ) {
				continue;
			}
			$needle = Attribution::needle( $issue );
			if ( '' !== $needle ) {
				$search[ $issue['cat'] . '|' . $issue['subject'] ] = $needle;
			}
		}
		$context['owners'] = $search ? Attribution::owners( $search ) : array();
		$result            = Classify::report( $pages, $captures['s'], $context );
		$readiness         = Plan::readiness();
		$score             = Classify::score( $result['issues'], $result['pages'], $readiness );
		$origins           = array();
		foreach ( $result['issues'] as $issue ) {
			$origins = array_merge( $origins, $issue['origins'] );
		}
		foreach ( $result['pages'] as $index => $page ) {
			$source                             = isset( $pages[ $page['id'] ] ) ? $pages[ $page['id'] ] : array();
			$result['pages'][ $index ]['title'] = isset( $source['title'] ) ? (string) $source['title'] : '';
		}
		$report = array(
			'run'       => $session['run'],
			'at'        => time(),
			'started'   => (int) $session['started'],
			'mode'      => empty( $session['manual'] ) ? 'frame' : 'manual',
			'guest'     => ! empty( $session['guest'] ),
			'score'     => $score,
			'grade'     => Classify::grade( $score ),
			'pages'     => $result['pages'],
			'issues'    => $result['issues'],
			'stats'     => $result['stats'],
			'readiness' => $readiness,
			'names'     => Attribution::names( $origins ),
			'fixed'     => array(),
		);
		update_option( self::REPORT, $report, false );
		self::remember( $report );
		Session::close();
		State::event(
			'drill_finished',
			array(
				'score' => $score,
				'pages' => count( $result['pages'] ),
			)
		);
		return self::view();
	}

	public static function cancel() {
		if ( Session::stored() ) {
			Session::close();
			State::event( 'drill_cancelled' );
		}
		return self::view();
	}

	public static function fix( WP_REST_Request $request ) {
		$action = (string) $request['action'];
		$target = trim( (string) $request['target'] );
		$done   = self::apply( $action, $target );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$issue = (string) $request['issue'];
		if ( '' !== $issue ) {
			$report = get_option( self::REPORT, array() );
			if ( is_array( $report ) && isset( $report['fixed'] ) && ! in_array( $issue, $report['fixed'], true ) ) {
				$report['fixed'][] = $issue;
				update_option( self::REPORT, $report, false );
			}
		}
		return array_merge( self::view(), array( 'done' => $done ) );
	}

	public static function apply( $action, $target ) {
		switch ( $action ) {
			case 'setting':
				return self::setting( $target );
			case 'resume':
				if ( ! Rest::can_manage_network() ) {
					return new WP_Error( 'rafoo_forbidden', __( 'You are not allowed to change this.', 'rafoo' ), array( 'status' => 403 ) );
				}
				if ( Settings::paused() ) {
					Settings::update( array( 'paused' => false ) );
					State::event( 'resumed' );
					Purge::later();
				}
				return array( 'state' => 'done' );
			case 'block':
			case 'allow':
				$host = strtolower( $target );
				if ( ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) || Hosts::local( $host ) ) {
					return self::invalid();
				}
				$key   = 'block' === $action ? 'block_hosts' : 'allow_hosts';
				$rules = array_values( array_diff( Settings::get( $key ), array( $host ) ) );
				$rules[] = $host;
				Rest::apply( self::custom_preset( array( $key => $rules ) ) );
				if ( ! in_array( $host, Settings::get( $key ), true ) ) {
					return self::managed();
				}
				return array( 'state' => 'done' );
			case 'download':
				return self::download( $target );
			case 'include':
				$records = Library::records();
				$key     = Rest::clean_key( $target );
				if ( '' === $key || ! isset( $records[ $key ] ) ) {
					return self::invalid();
				}
				$excluded = array_values( array_diff( Settings::get( 'exclude' ), array( $records[ $key ]['origin'] ) ) );
				Settings::update( array( 'exclude' => $excluded ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Rafoo setting of excluded file addresses, not a query argument.
				Library::rebuild( $records );
				return self::download( $key );
			case 'scan':
				Scanner::run( Settings::get( 'scan_as_user' ) ? Loopback::cookies() : array() );
				return self::sync_now();
			case 'sync':
				return self::sync_now();
		}
		return self::invalid();
	}

	private static function setting( $key ) {
		if ( ! isset( Classify::SETTINGS[ $key ] ) || ( 'updates' === $key && ! Plugin::complete() ) ) {
			return self::invalid();
		}
		$value = Classify::SETTINGS[ $key ];
		if ( 'replace' === $key && 'off' !== Settings::get( 'replace' ) ) {
			return array( 'state' => 'done' );
		}
		if ( is_string( $value ) && 'off' !== Settings::get( $key ) ) {
			return array( 'state' => 'done' );
		}
		Rest::apply( self::custom_preset( array( $key => $value ) ) );
		if ( Settings::get( $key ) !== $value ) {
			return self::managed();
		}
		if ( in_array( $key, array( 'fonts', 'replace', 'html_scan' ), true ) && ! wp_next_scheduled( 'rafoo_scan' ) ) {
			wp_schedule_single_event( time() + 5, 'rafoo_scan' );
		}
		return array( 'state' => 'done' );
	}

	private static function download( $target ) {
		$records = Library::records();
		$key     = Rest::clean_key( $target );
		if ( '' !== $key && isset( $records[ $key ] ) ) {
			Library::retry( $key );
		} else {
			$url   = esc_url_raw( $target, array( 'https', 'http' ) );
			$asset = '' !== $url ? Recognizer::parse( $url ) : null;
			$kind  = 'asset';
			if ( ! $asset && '' !== $url ) {
				$asset = Fonts::parse( $url );
				$kind  = 'font';
			}
			if ( ! $asset ) {
				return self::invalid();
			}
			$key = $asset['key'];
			if ( isset( $records[ $key ] ) ) {
				Library::retry( $key );
			} else {
				Library::note( $asset, '', $kind );
				Library::flush_notes();
			}
		}
		$sync    = self::sync_now();
		$records = Library::records();
		$record  = isset( $records[ $key ] ) ? $records[ $key ] : null;
		return array(
			'state'  => $record ? (string) $record['status'] : 'pending',
			'error'  => $record ? (string) $record['error'] : '',
			'key'    => $key,
			'synced' => $sync['state'],
		);
	}

	private static function sync_now() {
		delete_transient( Sync::LOCK );
		$result = Sync::run( 15 );
		if ( ! empty( $result['remaining'] ) ) {
			Library::schedule_soon();
		}
		return array(
			'state'     => ! empty( $result['skipped'] ) ? 'busy' : 'done',
			'remaining' => isset( $result['remaining'] ) ? (int) $result['remaining'] : 0,
		);
	}

	private static function custom_preset( array $changes ) {
		if ( 'done' === Settings::get( 'setup' ) && '' !== Settings::get( 'preset' ) ) {
			$changes['preset'] = 'custom';
		}
		return $changes;
	}

	private static function view_with( array $session ) {
		$view           = self::view();
		$view['active'] = self::public_session( $session );
		return $view;
	}

	private static function public_session( array $session ) {
		$pages = array();
		foreach ( (array) $session['pages'] as $id => $page ) {
			$pages[] = array(
				'id'    => (int) $id,
				'kind'  => (string) $page['kind'],
				'url'   => Session::link( $session, $id, $page['url'] ),
				'base'  => add_query_arg( Session::BASE, $session['run'] . '-' . (int) $id, $page['url'] ),
				'plain' => (string) $page['url'],
				'admin' => ! empty( $page['admin'] ),
				'title' => isset( $page['title'] ) ? (string) $page['title'] : '',
			);
		}
		return array(
			'run'     => (string) $session['run'],
			'manual'  => ! empty( $session['manual'] ),
			'guest'   => ! empty( $session['guest'] ),
			'started' => (int) $session['started'],
			'expires' => (int) $session['expires'],
			'pages'   => $pages,
			'home'    => home_url( '/' ),
		);
	}

	private static function prefs() {
		$prefs = get_option( self::PREFS, array() );
		$prefs = is_array( $prefs ) ? $prefs : array();
		return array(
			'kinds'   => isset( $prefs['kinds'] ) ? array_values( (array) $prefs['kinds'] ) : Plan::KINDS,
			'custom'  => isset( $prefs['custom'] ) ? array_values( (array) $prefs['custom'] ) : array(),
			'guest'   => isset( $prefs['guest'] ) ? (bool) $prefs['guest'] : true,
			'compare' => isset( $prefs['compare'] ) ? (bool) $prefs['compare'] : true,
		);
	}

	private static function report() {
		$report = get_option( self::REPORT, null );
		return is_array( $report ) && ! empty( $report['run'] ) ? $report : null;
	}

	private static function history() {
		$history = get_option( self::HISTORY, array() );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	private static function remember( array $report ) {
		$counts = array(
			'blocker' => 0,
			'warning' => 0,
			'info'    => 0,
			'handled' => 0,
		);
		foreach ( $report['issues'] as $issue ) {
			++$counts[ $issue['sev'] ];
		}
		$history = self::history();
		array_unshift(
			$history,
			array(
				'run'    => $report['run'],
				'at'     => $report['at'],
				'score'  => $report['score'],
				'grade'  => $report['grade'],
				'mode'   => $report['mode'],
				'pages'  => count( $report['pages'] ),
				'counts' => $counts,
			)
		);
		update_option( self::HISTORY, array_slice( $history, 0, self::KEEP ), false );
	}

	private static function invalid() {
		return new WP_Error( 'rafoo_invalid', __( 'This fix does not apply any more. Run the rehearsal again.', 'rafoo' ), array( 'status' => 400 ) );
	}

	private static function managed() {
		return new WP_Error( 'rafoo_managed', __( 'This setting is managed by the network administrator.', 'rafoo' ), array( 'status' => 409 ) );
	}
}
