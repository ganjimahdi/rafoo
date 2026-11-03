<?php

namespace Rafoo\Backup;

defined( 'ABSPATH' ) || exit;

final class Dump {

	const BATCH = 500;

	const STATEMENT_BYTES = 262144;

	const TEMP = 'rfrn_';

	const OLD = 'rfro_';

	public static function tables() {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$out = array();
		foreach ( $tables as $table ) {
			if ( 0 !== strpos( $table, self::TEMP ) && 0 !== strpos( $table, self::OLD ) ) {
				$out[] = $table;
			}
		}
		return $out;
	}

	public static function start( $file ) {
		$out = Ledger::open( $file, 'wb' );
		if ( ! $out ) {
			return null;
		}
		$out->fwrite( "SET NAMES utf8mb4;\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n" );
		$out = null;
		return array(
			'tables' => self::tables(),
			'table'  => 0,
			'after'  => null,
			'offset' => 0,
			'begun'  => false,
			'rows'   => 0,
		);
	}

	public static function export( $file, array $job, $deadline ) {
		global $wpdb;
		$out = Ledger::open( $file, 'ab' );
		if ( ! $out ) {
			return false;
		}
		while ( $job['table'] < count( $job['tables'] ) && microtime( true ) < $deadline ) {
			$table = $job['tables'][ $job['table'] ];
			if ( ! $job['begun'] ) {
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
				if ( ! $create ) {
					++$job['table'];
					continue;
				}
				$out->fwrite( 'DROP TABLE IF EXISTS `' . $table . "`;\n" . str_replace( array( "\r\n", "\n", "\r" ), ' ', $create[1] ) . ";\n" );
				$job['begun'] = true;
				$job['key']   = self::numeric_key( $table );
			}
			$rows = self::rows( $table, $job );
			if ( ! $rows ) {
				++$job['table'];
				$job['begun']  = false;
				$job['after']  = null;
				$job['offset'] = 0;
				continue;
			}
			$values = array();
			$bytes  = 0;
			foreach ( $rows as $row ) {
				$line     = self::values( $row );
				$values[] = $line;
				$bytes   += strlen( $line );
				if ( $bytes >= self::STATEMENT_BYTES ) {
					$out->fwrite( 'INSERT INTO `' . $table . '` VALUES ' . implode( ',', $values ) . ";\n" );
					$values = array();
					$bytes  = 0;
				}
			}
			if ( $values ) {
				$out->fwrite( 'INSERT INTO `' . $table . '` VALUES ' . implode( ',', $values ) . ";\n" );
			}
			$job['rows'] += count( $rows );
			if ( '' !== $job['key'] ) {
				$last         = end( $rows );
				$job['after'] = $last[ $job['key'] ];
			} else {
				$job['offset'] += count( $rows );
			}
		}
		$out         = null;
		$job['done'] = $job['table'] >= count( $job['tables'] );
		return $job;
	}

	public static function import( $file, array $job, $deadline ) {
		global $wpdb;
		$in = Ledger::open( $file, 'rb' );
		if ( ! $in ) {
			return false;
		}
		$in->fseek( $job['offset'] );
		$wpdb->query( 'SET foreign_key_checks = 0' );
		$job['done'] = false;
		while ( microtime( true ) < $deadline ) {
			$line = $in->eof() ? false : $in->fgets();
			if ( false === $line || ( '' === $line && $in->eof() ) ) {
				$job['done'] = true;
				break;
			}
			$job['offset'] += strlen( $line );
			$line           = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			$sql = self::rename( $line, $job );
			if ( null === $sql ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statements come from the site's own backup file, written by Dump::export with escaped values, and run into temporary rfrn_ tables.
			if ( false === $wpdb->query( $sql ) ) {
				$job['errors'][] = substr( $wpdb->last_error, 0, 200 );
				if ( count( $job['errors'] ) > 20 ) {
					return false;
				}
			}
			if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) ) {
				$job['created'][] = $m[1];
			}
		}
		return $job;
	}

	public static function rename( $line, array $job ) {
		if ( 0 === strpos( $line, 'SET ' ) ) {
			return $line;
		}
		if ( ! preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $line, $m ) ) {
			return null;
		}
		$name = $m[2];
		if ( 0 !== strpos( $name, $job['from'] ) ) {
			return null;
		}
		$target = self::TEMP . $job['to'] . substr( $name, strlen( $job['from'] ) );
		return $m[1] . ' `' . $target . '`' . substr( $line, strlen( $m[0] ) );
	}

	public static function swap( array $created, $to ) {
		$created = array_values( array_unique( $created ) );
		$pairs   = array();
		$live    = array();
		$exists  = array_flip( self::tables() );
		foreach ( $created as $temp ) {
			$name = substr( $temp, strlen( self::TEMP ) );
			if ( isset( $exists[ $name ] ) ) {
				self::drop( self::OLD . $name );
				$pairs[] = array( $name, self::OLD . $name, $temp );
				$live[]  = $name;
			} else {
				$pairs[] = array( $temp, $name, '' );
			}
		}
		foreach ( $pairs as $pair ) {
			if ( false === self::rename_pair( $pair ) ) {
				return false;
			}
		}
		return $live;
	}

	public static function undo( array $live ) {
		foreach ( $live as $name ) {
			self::drop( self::TEMP . $name );
			self::rename_pair( array( $name, self::TEMP . $name, self::OLD . $name ) );
		}
		self::drop_prefixed( self::TEMP );
	}

	public static function drop_prefixed( $prefix ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) {
			self::drop( $table );
		}
	}

	public static function fix_prefix( $from, $to ) {
		global $wpdb;
		if ( $from === $to ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', self::TEMP . $to . 'options', $to . 'user_roles', $from . 'user_roles' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s', self::TEMP . $to . 'usermeta', $to, strlen( $from ) + 1, $wpdb->esc_like( $from ) . '%' ) );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::TEMP . $to ) . '%' . $wpdb->esc_like( '_options' ) ) ) as $table ) {
			if ( preg_match( '/_(\d+)_options$/', $table, $m ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_name = %s WHERE option_name = %s', $table, $to . $m[1] . '_user_roles', $from . $m[1] . '_user_roles' ) );
			}
		}
	}

	private static function drop( $table ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	private static function rename_pair( array $pair ) {
		global $wpdb;
		if ( '' === $pair[2] ) {
			return $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $pair[0], $pair[1] ) );
		}
		return $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $pair[0], $pair[1], $pair[2], $pair[0] ) );
	}

	private static function rows( $table, array $job ) {
		global $wpdb;
		if ( '' !== $job['key'] ) {
			return null === $job['after']
				? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i LIMIT %d', $table, $job['key'], self::BATCH ), ARRAY_A )
				: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %d ORDER BY %i LIMIT %d', $table, $job['key'], $job['after'], $job['key'], self::BATCH ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, self::BATCH, $job['offset'] ), ARRAY_A );
	}

	private static function numeric_key( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );
		if ( 1 !== count( $keys ) ) {
			return '';
		}
		$column = $keys[0]['Column_name'];
		$type   = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i WHERE Field = %s', $table, $column ), ARRAY_A );
		return $type && preg_match( '/int/i', $type['Type'] ) ? $column : '';
	}

	private static function values( array $row ) {
		global $wpdb;
		$out = array();
		foreach ( $row as $value ) {
			$out[] = null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
		}
		return '(' . implode( ',', $out ) . ')';
	}
}
