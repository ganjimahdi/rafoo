<?php

namespace Rafoo\Admin;

use Rafoo\Assets\Library;
use Rafoo\Assets\Store;
use Rafoo\Connectivity\Monitor;
use Rafoo\Network;
use Rafoo\Settings;
use Rafoo\State;

defined( 'ABSPATH' ) || exit;

final class SiteHealth {

	const LATE = 600;

	public static function tests( $tests ) {
		$tests = is_array( $tests ) ? $tests : array();
		foreach ( array(
			'connection' => __( 'Rafoo connection state', 'rafoo' ),
			'checks'     => __( 'Rafoo connection checks', 'rafoo' ),
			'files'      => __( 'Rafoo local copies', 'rafoo' ),
			'paused'     => __( 'Rafoo is active', 'rafoo' ),
		) as $name => $label ) {
			$tests['direct'][ 'rafoo_' . $name ] = array(
				'label' => $label,
				'test'  => array( __CLASS__, $name ),
			);
		}
		return $tests;
	}

	public static function connection() {
		$state = State::all();
		if ( $state['local_down'] ) {
			return self::result( 'connection', 'recommended', __( 'This server cannot reach any network', 'rafoo' ), __( 'Rafoo could not reach servers inside or outside Iran. The problem is the network of this server, so Rafoo leaves the mode as it is. Ask your host to check the server connection.', 'rafoo' ), 'connection' );
		}
		if ( 'offline' === $state['override'] ) {
			return self::result( 'connection', 'recommended', __( 'Rafoo is fixed in outage mode by hand', 'rafoo' ), __( 'Outage mode stays on even when the internet works, so outside requests keep failing at once. Switch the mode back to automatic when you have finished testing.', 'rafoo' ), 'overview' );
		}
		if ( 'online' === $state['override'] ) {
			return self::result( 'connection', 'recommended', __( 'Rafoo is fixed in online mode by hand', 'rafoo' ), __( 'Outage mode will not switch on by itself, so during an outage pages and the dashboard will wait for unreachable servers. Switch the mode back to automatic.', 'rafoo' ), 'overview' );
		}
		if ( State::offline() ) {
			return self::result( 'connection', 'recommended', __( 'The international internet is down', 'rafoo' ), __( 'Rafoo has switched on outage mode by itself: local copies are served and requests to unreachable servers fail at once. It switches off by itself when the internet is back.', 'rafoo' ), 'overview' );
		}
		return self::result( 'connection', 'good', __( 'Rafoo detects outages automatically', 'rafoo' ), __( 'The international internet is reachable. If it is cut off, Rafoo switches on outage mode by itself within about three minutes.', 'rafoo' ), 'overview' );
	}

	public static function checks() {
		$state = State::all();
		$next  = (int) wp_next_scheduled( Monitor::HOOK );
		$last  = (int) $state['checked_at'];
		$late  = $last > 0 ? time() - $last > self::LATE : ( $next > 0 && time() - $next > self::LATE );
		if ( $late ) {
			$text = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
				? __( 'WP-Cron is turned off on this site and no system cron job runs it, so Rafoo cannot notice an outage. Add a cron job that requests wp-cron.php every minute.', 'rafoo' )
				: __( 'WP-Cron runs only when someone visits the site, so on quiet sites an outage is noticed late. Add a cron job that requests wp-cron.php every minute.', 'rafoo' );
			return self::result( 'checks', 'recommended', __( 'Rafoo connection checks are not running on time', 'rafoo' ), $text, 'overview' );
		}
		return self::result( 'checks', 'good', __( 'Rafoo connection checks run on time', 'rafoo' ), __( 'Rafoo checks the connection every minute in the background.', 'rafoo' ), 'overview' );
	}

	public static function files() {
		$dir = Store::dir();
		if ( ! self::writable( $dir ) ) {
			return self::result(
				'files',
				'critical',
				__( 'Rafoo cannot save local copies', 'rafoo' ),
				/* translators: %s: folder path */
				sprintf( __( 'The folder %s is not writable, so libraries and fonts cannot be copied to this server. Ask your host to give PHP write access to the uploads folder.', 'rafoo' ), '<code>' . esc_html( $dir ) . '</code>' ),
				'files'
			);
		}
		$summary = Library::summary();
		if ( $summary['failed'] > 0 ) {
			return self::result(
				'files',
				'recommended',
				/* translators: %d: number of files */
				sprintf( __( 'Rafoo could not copy %d files', 'rafoo' ), $summary['failed'] ),
				__( 'These files are still loaded from their original address and will not load during an outage. Open Rafoo › Files to see why and try again.', 'rafoo' ),
				'files'
			);
		}
		return self::result(
			'files',
			'good',
			/* translators: %d: number of files */
			sprintf( __( 'Rafoo serves %d local copies', 'rafoo' ), $summary['ready'] ),
			__( 'Libraries and fonts from public CDNs are served from this server.', 'rafoo' ),
			'files'
		);
	}

	public static function paused() {
		if ( Settings::paused() ) {
			return self::result( 'paused', 'recommended', __( 'Rafoo is paused', 'rafoo' ), __( 'Rafoo changes nothing on the site while it is paused, so it cannot help during an outage. Resume it when you have finished testing.', 'rafoo' ), 'overview' );
		}
		return self::result( 'paused', 'good', __( 'Rafoo is active', 'rafoo' ), __( 'Rafoo makes the changes chosen in its settings.', 'rafoo' ), 'overview' );
	}

	public static function info( $info ) {
		$info     = is_array( $info ) ? $info : array();
		$state    = State::all();
		$summary  = Library::summary();
		$settings = Settings::all();
		$yes      = __( 'Yes', 'rafoo' );
		$no       = __( 'No', 'rafoo' );
		$flag     = static function ( $value ) use ( $yes, $no ) {
			return array(
				'value' => $value ? $yes : $no,
				'debug' => $value ? 'yes' : 'no',
			);
		};
		$fields = array(
			'version'  => array(
				'label' => __( 'Version', 'rafoo' ),
				'value' => RAFOO_VERSION . ' (' . RAFOO_EDITION . ')',
			),
			'mode'     => array(
				'label' => __( 'Mode', 'rafoo' ),
				'value' => State::offline() ? __( 'Outage', 'rafoo' ) : __( 'Online', 'rafoo' ),
				'debug' => ( State::offline() ? 'offline' : 'online' ) . ', detected ' . $state['detected'] . ', override ' . $state['override'] . ( $state['local_down'] ? ', server network down' : '' ),
			),
			'checked'  => array(
				'label' => __( 'Last connection check', 'rafoo' ),
				'value' => $state['checked_at'] ? wp_date( 'Y-m-d H:i:s', (int) $state['checked_at'] ) : __( 'never', 'rafoo' ),
				'debug' => $state['checked_at'] ? gmdate( 'c', (int) $state['checked_at'] ) : 'never',
			),
			'files'    => array(
				'label' => __( 'Local copies', 'rafoo' ),
				/* translators: 1: ready files, 2: pending files, 3: failed files */
				'value' => sprintf( __( '%1$d ready, %2$d waiting, %3$d failed', 'rafoo' ), $summary['ready'], $summary['pending'], $summary['failed'] ),
				'debug' => sprintf( '%d ready, %d pending, %d failed, %d unverified', $summary['ready'], $summary['pending'], $summary['failed'], $summary['unverified'] ),
			),
			'disk'     => array(
				'label' => __( 'Disk space used', 'rafoo' ),
				'value' => size_format( $summary['bytes'] ),
				'debug' => (string) $summary['bytes'],
			),
			'folder'   => array_merge( array( 'label' => __( 'Folder writable', 'rafoo' ) ), $flag( self::writable( Store::dir() ) ) ),
			'paused'   => array_merge( array( 'label' => __( 'Paused', 'rafoo' ) ), $flag( $settings['paused'] ) ),
			'replace'  => array(
				'label' => __( 'Use local copies', 'rafoo' ),
				'value' => $settings['replace'],
			),
			'guard'    => array_merge( array( 'label' => __( 'Browser guard', 'rafoo' ) ), $flag( $settings['browser_guard'] ) ),
			'dedupe'   => array_merge( array( 'label' => __( 'Merge identical requests', 'rafoo' ) ), $flag( $settings['dedupe'] ) ),
			'heartbeat' => array(
				'label' => __( 'Heartbeat', 'rafoo' ),
				'value' => $settings['heartbeat'],
			),
			'allow'    => array(
				'label' => __( 'Allowed hosts', 'rafoo' ),
				'value' => count( $settings['allow_hosts'] ),
			),
			'block'    => array(
				'label' => __( 'Blocked hosts', 'rafoo' ),
				'value' => count( $settings['block_hosts'] ),
			),
			'cron'     => array_merge( array( 'label' => __( 'WP-Cron turned off', 'rafoo' ) ), $flag( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ),
		);
		if ( is_multisite() ) {
			$network            = Network::all();
			$fields['network']  = array(
				'label' => __( 'Network settings', 'rafoo' ),
				'value' => Network::enforced() ? __( 'Applied to every site', 'rafoo' ) : __( 'Defaults for new sites', 'rafoo' ),
				'debug' => $network['policy'] . ', revision ' . $network['revision'],
			);
		}
		$info['rafoo'] = array(
			'label'  => __( 'Rafoo', 'rafoo' ),
			'fields' => $fields,
		);
		return $info;
	}

	private static function result( $name, $status, $label, $description, $tab ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Rafoo', 'rafoo' ),
				'color' => 'critical' === $status ? 'red' : ( 'good' === $status ? 'blue' : 'orange' ),
			),
			'description' => '<p>' . wp_kses( $description, array( 'code' => array() ) ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( Page::url() . '#/' . $tab ) . '">' . esc_html__( 'Open Rafoo', 'rafoo' ) . '</a></p>',
			'test'        => 'rafoo_' . $name,
		);
	}

	private static function writable( $dir ) {
		return is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( dirname( $dir ) );
	}
}
