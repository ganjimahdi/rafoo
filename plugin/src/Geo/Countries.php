<?php

namespace Rafoo\Geo;

defined( 'ABSPATH' ) || exit;

final class Countries {

	const CONTINENTS = array( 'AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA' );

	const DATA = 'AD EU 42.5 1.5|AE AS 24 54|AF AS 33 65|AG NA 17.1 -61.8|AL EU 41 20|AM AS 40 45|AO AF -12.5 18.5|AQ AN -75 0|AR SA -34 -64|AT EU 47.3 13.3|AU OC -25 135|AZ AS 40.5 47.5|BA EU 44 18|BB NA 13.2 -59.5|BD AS 24 90|BE EU 50.8 4|BF AF 13 -2|BG EU 43 25|BH AS 26 50.5|BI AF -3.5 30|BJ AF 9.5 2.3|BN AS 4.5 114.7|BO SA -17 -65|BR SA -10 -55|BS NA 24 -76|BT AS 27.5 90.5|BW AF -22 24|BY EU 53 28|BZ NA 17.2 -88.7|CA NA 60 -95|CD AF 0 25|CF AF 7 21|CG AF -1 15|CH EU 47 8|CI AF 8 -5|CL SA -30 -71|CM AF 6 12|CN AS 35 105|CO SA 4 -72|CR NA 10 -84|CU NA 21.5 -80|CV AF 16 -24|CY EU 35 33|CZ EU 49.7 15.5|DE EU 51 9|DJ AF 11.5 43|DK EU 56 10|DM NA 15.4 -61.3|DO NA 19 -70.7|DZ AF 28 3|EC SA -2 -77.5|EE EU 59 26|EG AF 27 30|ER AF 15 39|ES EU 40 -4|ET AF 8 38|FI EU 64 26|FJ OC -18 175|FR EU 46 2|GA AF -1 11.8|GB EU 54 -2|GD NA 12.1 -61.7|GE AS 42 43.5|GH AF 8 -2|GM AF 13.5 -15.5|GN AF 11 -10|GQ AF 2 10|GR EU 39 22|GT NA 15.5 -90.3|GW AF 12 -15|GY SA 5 -59|HK AS 22.3 114.2|HN NA 15 -86.5|HR EU 45.2 15.5|HT NA 19 -72.4|HU EU 47 20|ID AS -5 120|IE EU 53 -8|IL AS 31.5 34.8|IN AS 20 77|IQ AS 33 44|IR AS 32 53|IS EU 65 -18|IT EU 42.8 12.8|JM NA 18.2 -77.5|JO AS 31 36|JP AS 36 138|KE AF 1 38|KG AS 41 75|KH AS 13 105|KM AF -12.2 44.2|KN NA 17.3 -62.7|KP AS 40 127|KR AS 37 127.5|KW AS 29.3 47.7|KZ AS 48 68|LA AS 18 105|LB AS 33.8 35.8|LC NA 13.9 -61|LI EU 47.2 9.5|LK AS 7 81|LR AF 6.5 -9.5|LS AF -29.5 28.5|LT EU 56 24|LU EU 49.8 6.2|LV EU 57 25|LY AF 25 17|MA AF 32 -5|MC EU 43.7 7.4|MD EU 47 29|ME EU 42.5 19.3|MG AF -20 47|MK EU 41.8 22|ML AF 17 -4|MM AS 22 98|MN AS 46 105|MO AS 22.2 113.5|MR AF 20 -12|MT EU 35.9 14.4|MU AF -20.3 57.6|MV AS 3.2 73|MW AF -13.5 34|MX NA 23 -102|MY AS 2.5 112.5|MZ AF -18.3 35|NA AF -22 17|NE AF 16 8|NG AF 10 8|NI NA 13 -85|NL EU 52.5 5.8|NO EU 62 10|NP AS 28 84|NZ OC -41 174|OM AS 21 57|PA NA 9 -80|PE SA -10 -76|PG OC -6 147|PH AS 13 122|PK AS 30 70|PL EU 52 20|PR NA 18.2 -66.5|PS AS 32 35.2|PT EU 39.5 -8|PY SA -23 -58|QA AS 25.5 51.2|RO EU 46 25|RS EU 44 21|RU EU 60 100|RW AF -2 30|SA AS 25 45|SB OC -8 159|SC AF -4.6 55.7|SD AF 15 30|SE EU 62 15|SG AS 1.4 103.8|SI EU 46 15|SK EU 48.7 19.5|SL AF 8.5 -11.5|SM EU 43.9 12.4|SN AF 14 -14|SO AF 10 49|SR SA 4 -56|SS AF 7 30|SV NA 13.8 -88.9|SY AS 35 38|SZ AF -26.5 31.5|TD AF 15 19|TG AF 8 1.2|TH AS 15 100|TJ AS 39 71|TL AS -8.8 125.7|TM AS 40 60|TN AF 34 9|TO OC -20 -175|TR AS 39 35|TT NA 11 -61|TW AS 23.5 121|TZ AF -6 35|UA EU 49 32|UG AF 1 32|US NA 38 -97|UY SA -33 -56|UZ AS 41 64|VA EU 41.9 12.45|VC NA 13.2 -61.2|VE SA 8 -66|VN AS 16 106|VU OC -16 167|WS OC -13.6 -172.3|YE AS 15 48|ZA AF -29 24|ZM AF -15 30|ZW AF -20 30';

	private static $map = null;

	public static function all() {
		if ( null === self::$map ) {
			self::$map = array();
			foreach ( explode( '|', self::DATA ) as $row ) {
				$parts                  = explode( ' ', $row );
				self::$map[ $parts[0] ] = array(
					'continent' => $parts[1],
					'lat'       => (float) $parts[2],
					'lon'       => (float) $parts[3],
				);
			}
		}
		return self::$map;
	}

	public static function valid( $code ) {
		return is_string( $code ) && isset( self::all()[ strtoupper( $code ) ] );
	}

	public static function get( $code ) {
		$all  = self::all();
		$code = strtoupper( (string) $code );
		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}

	public static function distance( array $a, array $b ) {
		$lat1 = deg2rad( $a['lat'] );
		$lat2 = deg2rad( $b['lat'] );
		$dlat = $lat2 - $lat1;
		$dlon = deg2rad( $b['lon'] - $a['lon'] );
		$h    = sin( $dlat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $dlon / 2 ) ** 2;
		return 12742 * asin( min( 1, sqrt( $h ) ) );
	}

	public static function centre( $continent ) {
		$centres = array(
			'AF' => array( 'lat' => 2, 'lon' => 20 ),
			'AN' => array( 'lat' => -75, 'lon' => 0 ),
			'AS' => array( 'lat' => 30, 'lon' => 90 ),
			'EU' => array( 'lat' => 50, 'lon' => 12 ),
			'NA' => array( 'lat' => 40, 'lon' => -95 ),
			'OC' => array( 'lat' => -25, 'lon' => 140 ),
			'SA' => array( 'lat' => -15, 'lon' => -60 ),
		);
		return isset( $centres[ $continent ] ) ? $centres[ $continent ] : $centres['EU'];
	}
}
