<?php

namespace Rafoo\Geo;

use Rafoo\Backup\Dump;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Copies keep the same tables as the main host; identifiers are quoted by Db::ident and values escaped by $wpdb.

final class Db {

	const TEMP = 'rfgs_';

	const OLD = 'rfgo_';

	const WIDTH = 1000;

	const REPLY_BYTES = 4194304;

	const STATEMENT_BYTES = 262144;

	const PREFIXED = array(
		'options'  => 'option_name',
		'usermeta' => 'meta_key',
	);

	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	public static function tables() {
		global $wpdb;
		$out = array();
		foreach ( Dump::tables() as $table ) {
			if ( ! Changes::skipped_table( $table ) && 0 !== strpos( $table, self::TEMP ) && 0 !== strpos( $table, self::OLD ) ) {
				$out[] = substr( $table, strlen( $wpdb->base_prefix ) );
			}
		}
		return $out;
	}

	public static function describe( $name ) {
		global $wpdb;
		$table   = $wpdb->base_prefix . $name;
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::ident( $table ), ARRAY_A );
		if ( ! $columns ) {
			return null;
		}
		$names  = array();
		$schema = array();
		foreach ( $columns as $column ) {
			$names[]  = (string) $column['Field'];
			$type     = strtolower( (string) $column['Type'] );
			$type     = (string) preg_replace( '/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type );
			$schema[] = $column['Field'] . ' ' . $type . ' ' . $column['Null'];
		}
		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
		$key  = '';
		foreach ( (array) $keys as $row ) {
			if ( 1 === (int) $row['Seq_in_index'] ) {
				foreach ( $columns as $column ) {
					if ( $column['Field'] === $row['Column_name'] && preg_match( '/int/i', (string) $column['Type'] ) ) {
						$key = (string) $row['Column_name'];
					}
				}
			}
		}
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A );
		$avg    = $status && isset( $status['Avg_row_length'] ) ? max( 64, (int) $status['Avg_row_length'] ) : 512;
		return array(
			'name'    => $name,
			'columns' => $names,
			'schema'  => md5( implode( "\n", $schema ) ),
			'key'     => $key,
			'width'   => '' === $key ? 0 : (int) max( 50, min( 5000, floor( self::WIDTH * 2048 / $avg ) ) ),
			'rows'    => $status && isset( $status['Rows'] ) ? (int) $status['Rows'] : 0,
		);
	}

	public static function create_statement( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::ident( $wpdb->base_prefix . $name ), ARRAY_N );
		if ( ! $row || ! isset( $row[1] ) ) {
			return '';
		}
		return (string) preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `{{table}}`', str_replace( array( "\r\n", "\n", "\r" ), ' ', (string) $row[1] ) );
	}

	private static function column( $name, $column ) {
		global $wpdb;
		$quoted = self::ident( $column );
		if ( isset( self::PREFIXED[ $name ] ) && self::PREFIXED[ $name ] === $column ) {
			$length = strlen( $wpdb->base_prefix );
			return 'IF(LEFT(' . $quoted . ', ' . $length . ') = ' . self::quote( $wpdb->base_prefix ) . ", CONCAT('~P~', SUBSTRING(" . $quoted . ', ' . ( $length + 1 ) . ')), ' . $quoted . ')';
		}
		return $quoted;
	}

	private static function quote( $value ) {
		global $wpdb;
		return "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $value ) ) . "'";
	}

	private static function filter( $name ) {
		if ( 'options' !== $name ) {
			return '1=1';
		}
		$names = array_map( array( __CLASS__, 'quote' ), Store::local_options() );
		return "NOT (option_name LIKE '\\_transient%' OR option_name LIKE '\\_site\\_transient%' OR (option_name LIKE 'rafoo\\_%' AND option_name <> 'rafoo_settings') OR option_name IN (" . implode( ',', $names ) . '))';
	}

	private static function bucket( array $info ) {
		return '' === $info['key'] ? '0' : 'FLOOR(' . self::ident( $info['key'] ) . ' / ' . (int) $info['width'] . ')';
	}

	private static function range( array $info, $bucket ) {
		if ( '' === $info['key'] ) {
			return '1=1';
		}
		$low = (int) $bucket * (int) $info['width'];
		return self::ident( $info['key'] ) . ' >= ' . $low . ' AND ' . self::ident( $info['key'] ) . ' < ' . ( $low + (int) $info['width'] );
	}

	public static function sums( array $info ) {
		global $wpdb;
		$name  = $info['name'];
		$parts = array();
		$nulls = array();
		foreach ( $info['columns'] as $column ) {
			$parts[] = self::column( $name, $column );
			$nulls[] = 'ISNULL(' . self::ident( $column ) . ')';
		}
		$hash = "CAST(CONV(SUBSTRING(MD5(CONCAT_WS('#', " . implode( ', ', $parts ) . ', CONCAT(' . implode( ', ', $nulls ) . '))), 1, 16), 16, 10) AS UNSIGNED)';
		$sql  = 'SELECT b, COUNT(*) AS n, CAST(BIT_XOR(h) AS CHAR) AS x FROM (SELECT ' . self::bucket( $info ) . ' AS b, ' . $hash . ' AS h FROM ' . self::ident( $wpdb->base_prefix . $name ) . ' WHERE ' . self::filter( $name ) . ') q GROUP BY b ORDER BY b';
		$out  = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_N ) as $row ) {
			$out[ (string) (int) $row[0] ] = (int) $row[1] . ':' . (string) $row[2];
		}
		$wpdb->flush();
		return '' !== $wpdb->last_error ? null : $out;
	}

	public static function rows( array $info, array $buckets ) {
		global $wpdb;
		$name    = $info['name'];
		$table   = self::ident( $wpdb->base_prefix . $name );
		$columns = implode( ', ', array_map( array( __CLASS__, 'ident' ), $info['columns'] ) );
		$out     = array();
		$size    = 0;
		$done    = array();
		foreach ( $buckets as $bucket ) {
			$sql  = 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . self::range( $info, $bucket ) . ' AND ' . self::filter( $name );
			$rows = $wpdb->get_results( $sql, ARRAY_N );
			$wpdb->flush();
			$list = array();
			foreach ( (array) $rows as $row ) {
				$encoded = array();
				foreach ( $row as $value ) {
					$encoded[] = null === $value ? null : base64_encode( (string) $value );
					$size     += null === $value ? 4 : strlen( (string) $value );
				}
				$list[] = $encoded;
			}
			unset( $rows );
			$out[ (string) (int) $bucket ] = $list;
			$done[]                        = (int) $bucket;
			if ( $size >= self::REPLY_BYTES ) {
				break;
			}
		}
		return array(
			'rows' => $out,
			'done' => $done,
		);
	}

	public static function local_name( $name ) {
		global $wpdb;
		return $wpdb->base_prefix . $name;
	}

	private static function translate( $name, array $columns, array $row, $from ) {
		global $wpdb;
		if ( ! isset( self::PREFIXED[ $name ] ) || $from === $wpdb->base_prefix ) {
			return $row;
		}
		$index = array_search( self::PREFIXED[ $name ], $columns, true );
		if ( false !== $index && null !== $row[ $index ] && 0 === strpos( (string) $row[ $index ], $from ) ) {
			$row[ $index ] = $wpdb->base_prefix . substr( (string) $row[ $index ], strlen( $from ) );
		}
		return $row;
	}

	public static function apply( array $info, $bucket, array $rows, $from, $target = '' ) {
		global $wpdb;
		$name    = $info['name'];
		$table   = '' !== $target ? $target : self::local_name( $name );
		$columns = $info['columns'];
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( 'DELETE FROM ' . self::ident( $table ) . ' WHERE ' . self::range( $info, $bucket ) . ' AND ' . self::filter( $name ) );
		$head   = 'INSERT INTO ' . self::ident( $table ) . ' (' . implode( ', ', array_map( array( __CLASS__, 'ident' ), $columns ) ) . ') VALUES ';
		$values = array();
		$bytes  = 0;
		$error  = '';
		foreach ( $rows as $encoded ) {
			$row = array();
			foreach ( (array) $encoded as $value ) {
				$row[] = null === $value ? null : (string) base64_decode( (string) $value );
			}
			$line     = Dump::values( self::translate( $name, $columns, $row, $from ) );
			$values[] = $line;
			$bytes   += strlen( $line );
			if ( $bytes >= self::STATEMENT_BYTES ) {
				if ( false === $wpdb->query( $head . implode( ',', $values ) ) ) {
					$error = $wpdb->last_error;
				}
				$values = array();
				$bytes  = 0;
			}
		}
		if ( $values && false === $wpdb->query( $head . implode( ',', $values ) ) ) {
			$error = $wpdb->last_error;
		}
		if ( '' !== $error ) {
			$wpdb->query( 'ROLLBACK' );
			return $error;
		}
		$wpdb->query( 'COMMIT' );
		return '';
	}

	public static function create_temp( $name, $statement ) {
		global $wpdb;
		$temp = self::TEMP . self::local_name( $name );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ident( $temp ) );
		$sql = str_replace( '`{{table}}`', self::ident( $temp ), (string) $statement );
		if ( false === $wpdb->query( $sql ) ) {
			$sql = (string) preg_replace( '/utf8mb4_0900_[a-z_]+/', 'utf8mb4_unicode_520_ci', $sql );
			if ( false === $wpdb->query( $sql ) ) {
				return new \WP_Error( 'rafoo_geo_create', $wpdb->last_error );
			}
		}
		return $temp;
	}

	public static function swap_temp( $name ) {
		global $wpdb;
		$live = self::local_name( $name );
		$temp = self::TEMP . $live;
		$old  = self::OLD . $live;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ident( $old ) );
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live ) ) );
		if ( $exists && 'options' === $name ) {
			$wpdb->query( 'INSERT IGNORE INTO ' . self::ident( $temp ) . ' SELECT * FROM ' . self::ident( $live ) . ' WHERE NOT (' . self::filter( $name ) . ')' );
		}
		$ok = $exists
			? $wpdb->query( 'RENAME TABLE ' . self::ident( $live ) . ' TO ' . self::ident( $old ) . ', ' . self::ident( $temp ) . ' TO ' . self::ident( $live ) )
			: $wpdb->query( 'RENAME TABLE ' . self::ident( $temp ) . ' TO ' . self::ident( $live ) );
		if ( false === $ok ) {
			return $wpdb->last_error;
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ident( $old ) );
		return '';
	}

	public static function drop_local( $name ) {
		global $wpdb;
		if ( isset( self::PREFIXED[ $name ] ) || in_array( $name, array( 'posts', 'users', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links' ), true ) ) {
			return;
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ident( self::local_name( $name ) ) );
	}

	public static function drop_temps() {
		global $wpdb;
		foreach ( array( self::TEMP, self::OLD ) as $prefix ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . self::ident( $table ) );
			}
		}
	}
}
