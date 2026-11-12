<?php

namespace Rafoo\Backup\Remote;

use Rafoo\Backup\Destinations;
use Rafoo\Backup\Ledger;
use Rafoo\Http\Client;

defined( 'ABSPATH' ) || exit;

final class FilesIr extends Remote {

	const TYPE = 'filesir';

	const ID = 'dfilesir';

	const BASE = 'https://my.files.ir/api/v1';

	const TUS = '1.0.0';

	const TUS_CHUNK = 8388608;

	const READ_CHUNK = 33554432;

	const MEMORY = 67108864;

	const FOLDER = 'Rafoo';

	const STALE = 72000;

	const PAGES = 20;

	const PER_PAGE = 500;

	private $entries = null;

	private $code = 0;

	public static function base() {
		return untrailingslashit( (string) apply_filters( 'rafoo_files_ir_base', self::BASE ) );
	}

	public static function stream() {
		return is_readable( __DIR__ . '/FilesCurl.php' ) && FilesCurl::available();
	}

	public static function folder_path() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = preg_replace( '/[^a-z0-9.\-]/', '', $host );
		return self::FOLDER . '/' . ( '' !== $host ? $host : 'site' );
	}

	public static function probe( $key ) {
		$key = trim( (string) $key );
		if ( strlen( $key ) < 16 || strlen( $key ) > 400 || preg_match( '/\s/', $key ) ) {
			return array(
				'ok'    => false,
				'error' => 'bad_key',
			);
		}
		$remote = new self(
			array(
				'id'         => '',
				'type'       => self::TYPE,
				'pass_plain' => $key,
				'path'       => self::folder_path(),
				'workspace'  => 0,
			)
		);
		$scopes = $remote->call( 'GET', '/access-token/scopes', array( 'timeout' => 15 ) );
		if ( false === $scopes && in_array( $remote->code, array( 401, 403 ), true ) ) {
			return array(
				'ok'    => false,
				'error' => 'rejected',
			);
		}
		$workspace = 0;
		$name      = '';
		if ( false !== $scopes ) {
			$choice    = self::workspace( $scopes['body'] );
			$workspace = $choice[0];
			$name      = $choice[1];
			if ( null === $workspace ) {
				return array(
					'ok'    => false,
					'error' => 'no_workspace',
				);
			}
		}
		$remote->config['workspace'] = $workspace;
		$usage                       = $remote->usage();
		if ( false === $scopes && false === $usage ) {
			return array(
				'ok'     => false,
				'error'  => in_array( $remote->code, array( 401, 403 ), true ) ? 'rejected' : 'unreachable',
				'detail' => $remote->error(),
			);
		}
		if ( ! $remote->prepare_folder() ) {
			return array(
				'ok'     => false,
				'error'  => 'folder',
				'detail' => $remote->error(),
			);
		}
		$test = $remote->write_test();
		if ( false === $test ) {
			return array(
				'ok'     => false,
				'error'  => 'write',
				'detail' => $remote->error(),
			);
		}
		$usage = $remote->usage();
		return array(
			'ok'        => true,
			'type'      => self::TYPE,
			'workspace' => (int) $workspace,
			'space'     => $name,
			'folder_id' => (int) $remote->config['folder_id'],
			'path'      => self::folder_path(),
			'speed'     => (int) $test['speed'],
			'used'      => $usage ? (int) $usage['used'] : -1,
			'free'      => $usage ? (int) $usage['free'] : -1,
		);
	}

	public static function workspace( $body ) {
		$token = is_array( $body ) && isset( $body['token'] ) && is_array( $body['token'] ) ? $body['token'] : null;
		if ( ! $token || ! isset( $body['workspaces'] ) || ! is_array( $body['workspaces'] ) ) {
			return array( 0, '' );
		}
		$all      = 'all' === strtolower( trim( (string) ( isset( $token['workspace_access'] ) ? $token['workspace_access'] : 'all' ) ) );
		$personal = $all;
		$first    = null;
		foreach ( $body['workspaces'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_numeric( $item['id'] ) ) {
				continue;
			}
			if ( 0 === (int) $item['id'] ) {
				$personal = true;
			} elseif ( null === $first && (int) $item['id'] > 0 ) {
				$first = array( (int) $item['id'], isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '' );
			}
		}
		if ( $personal ) {
			return array( 0, '' );
		}
		return $first ? $first : array( null, '' );
	}

	public function connect( $timeout = 15 ) {
		return '' !== $this->token() || $this->fail( 'no api key' );
	}

	public function close() {
	}

	public function usage() {
		$res = $this->call( 'GET', '/user/space-usage', array( 'timeout' => 15 ) );
		if ( false === $res ) {
			return false;
		}
		$body = isset( $res['body']['data'] ) && is_array( $res['body']['data'] ) ? $res['body']['data'] : $res['body'];
		if ( ! isset( $body['used'] ) && ! isset( $body['available'] ) && ! isset( $body['remaining'] ) ) {
			return false;
		}
		$used  = isset( $body['used'] ) ? (int) $body['used'] : 0;
		$total = isset( $body['available'] ) && is_numeric( $body['available'] ) ? (int) $body['available'] : -1;
		$free  = isset( $body['remaining'] ) && is_numeric( $body['remaining'] ) ? (int) $body['remaining'] : ( $total >= 0 ? max( 0, $total - $used ) : -1 );
		return array(
			'used'  => $used,
			'total' => $total,
			'free'  => $free,
		);
	}

	public function free_space() {
		$usage = $this->usage();
		return $usage ? (int) $usage['free'] : -1;
	}

	public function prepare_folder() {
		if ( ! empty( $this->config['folder_id'] ) ) {
			return true;
		}
		$id = $this->ensure( isset( $this->config['path'] ) ? (string) $this->config['path'] : self::folder_path() );
		if ( ! $id ) {
			return false;
		}
		$this->config['folder_id'] = $id;
		if ( ! empty( $this->config['id'] ) ) {
			Destinations::update( $this->config['id'], array( 'folder_id' => $id ) );
		}
		return true;
	}

	public function mkdir( $path ) {
		return (bool) $this->ensure( $path );
	}

	public function size( $name ) {
		$entries = $this->entries();
		$name    = basename( $name );
		return isset( $entries[ $name ] ) ? (int) $entries[ $name ][0]['size'] : -1;
	}

	public function names() {
		return array_keys( $this->entries() );
	}

	public function delete( $name ) {
		$entries = $this->entries();
		$name    = basename( $name );
		if ( empty( $entries[ $name ] ) ) {
			return true;
		}
		$ids = array();
		foreach ( $entries[ $name ] as $item ) {
			$ids[] = (string) $item['id'];
		}
		$res = $this->call(
			'POST',
			'/file-entries/delete',
			array(
				'json' => array(
					'entryIds'      => $ids,
					'deleteForever' => true,
				),
			)
		);
		if ( false === $res ) {
			return false;
		}
		unset( $this->entries[ $name ] );
		return true;
	}

	public function put( $local, $name, $offset, $deadline ) {
		clearstatcache( true, $local );
		if ( ! is_readable( $local ) ) {
			return $this->fail( 'local file missing' );
		}
		$name  = basename( $name );
		$size  = (int) filesize( $local );
		$key   = $this->state_key( $name );
		$state = Ledger::read( $key );
		$fresh = ! $state || 0 === (int) $offset || (int) $state['size'] !== $size || time() - (int) $state['at'] > self::STALE;
		if ( $fresh ) {
			if ( $state && ! empty( $state['session'] ) ) {
				$this->abort( (string) $state['session'] );
			}
			$state = $this->begin( $name, $size );
			if ( ! $state ) {
				Ledger::write( $key, null );
				return false;
			}
			Ledger::write( $key, $state );
		}
		switch ( $state['mode'] ) {
			case 'single':
			case 's3-single':
				$ok = $this->whole( $state, $local, $size );
				break;
			case 's3-multipart':
				$ok = $this->multipart( $state, $local, $size, $deadline, $key );
				break;
			case 'tus':
				$ok = $this->tus( $state, $local, $size, $deadline, $key );
				break;
			default:
				$ok = $this->fail( 'unsupported upload mode: ' . $state['mode'] );
		}
		if ( false === $ok ) {
			Ledger::write( $key, null );
			$this->abort( (string) $state['session'] );
			return false;
		}
		if ( (int) $state['sent'] < $size ) {
			$state['at'] = time();
			Ledger::write( $key, $state );
			return (int) $state['sent'];
		}
		if ( empty( $state['entry'] ) && ! $this->complete( $state ) ) {
			Ledger::write( $key, null );
			return false;
		}
		Ledger::write( $key, null );
		return $size;
	}

	public function get( $name, $local, $offset, $deadline ) {
		$entries = $this->entries();
		$name    = basename( $name );
		if ( empty( $entries[ $name ] ) ) {
			return $this->fail( 'file not found on Files.ir' );
		}
		$id    = (int) $entries[ $name ][0]['id'];
		$total = (int) $entries[ $name ][0]['size'];
		$url   = $this->download_url( $id );
		if ( '' === $url ) {
			return false;
		}
		if ( 0 === $offset && false === file_put_contents( $local, '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a part file in Rafoo's private backup folder.
			return $this->fail( 'local file not writable' );
		}
		$retried = false;
		while ( $offset < $total && microtime( true ) < $deadline ) {
			$end  = min( $total, $offset + self::READ_CHUNK ) - 1;
			$temp = Ledger::temp( $name . '.get' );
			$wait = max( 30, (int) ( $deadline - microtime( true ) ) + 30 );
			$res  = wp_remote_get(
				$url,
				Client::args(
					array(
						'headers'     => $this->headers_for( $url, array( 'Range' => 'bytes=' . $offset . '-' . $end ) ),
						'timeout'     => $wait,
						'redirection' => 3,
						'stream'      => true,
						'filename'    => $temp,
					)
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( 206 !== $code && ! ( 200 === $code && 0 === $offset ) ) {
				wp_delete_file( $temp );
				if ( ! $retried && in_array( $code, array( 0, 401, 403, 404, 410 ), true ) ) {
					$retried = true;
					$url     = $this->download_url( $id );
					if ( '' !== $url ) {
						continue;
					}
				}
				return $this->fail( is_wp_error( $res ) ? $res->get_error_message() : 'download failed: HTTP ' . $code );
			}
			$written = $this->append( $temp, $local );
			wp_delete_file( $temp );
			if ( $written <= 0 ) {
				return $this->fail( 'download failed: empty answer' );
			}
			$offset += $written;
			if ( 200 === $code ) {
				break;
			}
		}
		clearstatcache( true, $local );
		return (int) filesize( $local );
	}

	public function write_test() {
		$result = parent::write_test();
		$this->entries = null;
		return $result;
	}

	private function begin( $name, $size ) {
		if ( ! $this->prepare_folder() ) {
			return false;
		}
		$this->delete( $name );
		$init = $this->init( $name, $size );
		if ( false === $init && in_array( $this->code, array( 403, 404, 422 ), true ) ) {
			$this->config['folder_id'] = 0;
			if ( ! empty( $this->config['id'] ) ) {
				Destinations::update( $this->config['id'], array( 'folder_id' => 0 ) );
			}
			$this->entries = null;
			if ( $this->prepare_folder() ) {
				$init = $this->init( $name, $size );
			}
		}
		if ( false === $init ) {
			return false;
		}
		$body = $init['body'];
		if ( empty( $body['uploadSessionId'] ) || empty( $body['uploadMode'] ) ) {
			return $this->fail( 'Files.ir did not start the upload' );
		}
		return array(
			'name'    => $name,
			'size'    => $size,
			'session' => (string) $body['uploadSessionId'],
			'mode'    => (string) $body['uploadMode'],
			'part'    => isset( $body['partSize'] ) ? (int) $body['partSize'] : 0,
			'next'    => isset( $body['next'] ) && is_array( $body['next'] ) ? $body['next'] : array(),
			'parts'   => array(),
			'url'     => '',
			'sent'    => 0,
			'entry'   => null,
			'at'      => time(),
		);
	}

	private function init( $name, $size ) {
		$payload = array(
			'filename' => $name,
			'size'     => (int) $size,
		);
		if ( ! empty( $this->config['folder_id'] ) ) {
			$payload['parentId'] = (int) $this->config['folder_id'];
		}
		if ( ! empty( $this->config['workspace'] ) ) {
			$payload['workspaceId'] = (int) $this->config['workspace'];
		}
		return $this->call( 'POST', '/uploads-new/init', array( 'json' => $payload ), array( 200, 201 ) );
	}

	private function whole( array &$state, $local, $size ) {
		$wait = (int) min( 3600, max( 300, $size / 65536 ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $wait + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- one whole-file upload can take longer than the default limit; some hosts disable set_time_limit and the upload then runs within the normal limit.
		}
		if ( 'single' === $state['mode'] ) {
			$url = self::base() . '/uploads-new/' . rawurlencode( $state['session'] ) . '/file';
			$res = self::stream()
				? FilesCurl::multipart( $url, $this->headers_for( $url ), $local, $state['name'], $wait )
				: $this->memory_multipart( $url, $local, $state['name'], $size, $wait );
			if ( ! $this->accepted( $res ) ) {
				return false;
			}
			$state['entry'] = $this->entry_of( $res['body'] );
			$state['sent']  = $size;
			$this->remember( $state );
			return true;
		}
		$next = $state['next'];
		$url  = isset( $next['url'] ) ? (string) $next['url'] : '';
		if ( '' === $url ) {
			return $this->fail( 'Files.ir gave no upload address' );
		}
		$res = $this->send( 'PUT', $url, $this->extra_headers( isset( $next['headers'] ) ? $next['headers'] : array() ), $local, 0, $size, $wait );
		if ( ! $this->accepted( $res ) ) {
			return false;
		}
		$state['sent'] = $size;
		return true;
	}

	private function multipart( array &$state, $local, $size, $deadline, $key ) {
		$part = (int) $state['part'];
		if ( $part <= 0 ) {
			return $this->fail( 'Files.ir gave no part size' );
		}
		if ( ! self::stream() && $part > self::MEMORY ) {
			return $this->fail( 'this upload needs the PHP cURL extension' );
		}
		$count = (int) ceil( $size / $part );
		$urls  = array();
		while ( count( $state['parts'] ) < $count && microtime( true ) < $deadline ) {
			$number = count( $state['parts'] ) + 1;
			if ( ! isset( $urls[ $number ] ) ) {
				$urls = $this->sign( $state['session'], range( $number, min( $count, $number + 9 ) ) );
				if ( false === $urls || ! isset( $urls[ $number ] ) ) {
					return $this->fail( '' !== $this->error ? $this->error : 'Files.ir did not sign the upload parts' );
				}
			}
			$start = ( $number - 1 ) * $part;
			$bytes = min( $part, $size - $start );
			$wait  = (int) min( 1800, max( 120, $bytes / 65536 ) );
			$res   = $this->send( 'PUT', $urls[ $number ], array(), $local, $start, $bytes, $wait );
			if ( ! $this->accepted( $res ) ) {
				return false;
			}
			$etag = isset( $res['headers']['etag'] ) ? (string) $res['headers']['etag'] : '';
			if ( '' === $etag ) {
				return $this->fail( 'the storage did not confirm part ' . $number );
			}
			$state['parts'][] = array(
				'PartNumber' => $number,
				'ETag'       => $etag,
			);
			$state['sent']    = min( $size, $start + $bytes );
			$state['at']      = time();
			Ledger::write( $key, $state );
		}
		if ( count( $state['parts'] ) >= $count ) {
			$state['sent'] = $size;
		}
		return true;
	}

	private function tus( array &$state, $local, $size, $deadline, $key ) {
		$next = $state['next'];
		if ( '' === $state['url'] ) {
			$create = isset( $next['url'] ) ? $this->resolve( (string) $next['url'] ) : '';
			if ( '' === $create ) {
				return $this->fail( 'Files.ir gave no upload address' );
			}
			$res = $this->raw(
				'POST',
				$create,
				array(
					'Tus-Resumable'   => self::TUS,
					'Upload-Length'   => (string) $size,
					'Upload-Metadata' => $this->metadata( isset( $next['metadata'] ) ? $next['metadata'] : array() ),
				),
				'',
				30
			);
			if ( false === $res || 201 !== $res['code'] || empty( $res['headers']['location'] ) ) {
				return $this->fail( '' !== $this->error ? $this->error : 'Files.ir did not create the upload' );
			}
			$state['url'] = $this->resolve( (string) $res['headers']['location'] );
			Ledger::write( $key, $state );
		} elseif ( $state['sent'] > 0 ) {
			$head = $this->raw( 'HEAD', $state['url'], array( 'Tus-Resumable' => self::TUS ), null, 20 );
			if ( false !== $head && isset( $head['headers']['upload-offset'] ) ) {
				$state['sent'] = (int) $head['headers']['upload-offset'];
			}
		}
		while ( $state['sent'] < $size && microtime( true ) < $deadline ) {
			$bytes = min( self::TUS_CHUNK, $size - $state['sent'] );
			$chunk = $this->read( $local, $state['sent'], $bytes );
			if ( false === $chunk ) {
				return false;
			}
			$res = $this->raw(
				'PATCH',
				$state['url'],
				array(
					'Tus-Resumable' => self::TUS,
					'Upload-Offset' => (string) $state['sent'],
					'Content-Type'  => 'application/offset+octet-stream',
				),
				$chunk,
				120
			);
			if ( false === $res || 204 !== $res['code'] || ! isset( $res['headers']['upload-offset'] ) ) {
				return $this->fail( '' !== $this->error ? $this->error : 'upload stopped at byte ' . $state['sent'] );
			}
			$moved = (int) $res['headers']['upload-offset'];
			if ( $moved <= $state['sent'] ) {
				return $this->fail( 'upload did not move forward' );
			}
			$state['sent'] = $moved;
			$state['at']   = time();
			Ledger::write( $key, $state );
		}
		return true;
	}

	private function complete( array &$state ) {
		$next    = $state['next'];
		$url     = ! empty( $next['completeUrl'] ) && 'single' !== $state['mode'] ? $this->resolve( (string) $next['completeUrl'] ) : self::base() . '/uploads-new/' . rawurlencode( $state['session'] ) . '/complete';
		$payload = array();
		if ( 's3-multipart' === $state['mode'] ) {
			$payload['parts'] = $state['parts'];
		} elseif ( 'tus' === $state['mode'] ) {
			$payload['uploadKey'] = basename( (string) wp_parse_url( $state['url'], PHP_URL_PATH ) );
		}
		$res = $this->call( 'POST', $url, array( 'json' => $payload ? $payload : new \stdClass() ), array( 200, 201 ) );
		if ( false === $res ) {
			return false;
		}
		$state['entry'] = $this->entry_of( $res['body'] );
		$this->remember( $state );
		return true;
	}

	private function remember( array $state ) {
		$entry = $state['entry'];
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			$this->entries = null;
			return;
		}
		if ( null !== $this->entries ) {
			$this->entries[ $state['name'] ] = array(
				array(
					'id'   => (int) $entry['id'],
					'size' => isset( $entry['file_size'] ) ? (int) $entry['file_size'] : (int) $state['size'],
				),
			);
		}
		if ( isset( $entry['file_size'] ) && (int) $entry['file_size'] > 0 && (int) $entry['file_size'] !== (int) $state['size'] ) {
			$this->entries = null;
		}
	}

	private function entry_of( $body ) {
		return is_array( $body ) && isset( $body['fileEntry'] ) && is_array( $body['fileEntry'] ) ? $body['fileEntry'] : null;
	}

	private function abort( $session ) {
		if ( '' !== $session ) {
			$this->call( 'DELETE', '/uploads-new/' . rawurlencode( $session ), array( 'timeout' => 10 ), array( 200, 204, 404 ) );
		}
	}

	private function sign( $session, array $numbers ) {
		$res = $this->call( 'POST', '/uploads-new/' . rawurlencode( $session ) . '/parts/sign', array( 'json' => array( 'partNumbers' => array_values( $numbers ) ) ) );
		if ( false === $res || empty( $res['body']['urls'] ) || ! is_array( $res['body']['urls'] ) ) {
			return false;
		}
		$map = array();
		foreach ( $res['body']['urls'] as $item ) {
			if ( isset( $item['partNumber'], $item['url'] ) ) {
				$map[ (int) $item['partNumber'] ] = (string) $item['url'];
			}
		}
		return $map;
	}

	private function entries() {
		if ( null !== $this->entries ) {
			return $this->entries;
		}
		$out = array();
		if ( ! $this->prepare_folder() ) {
			return $out;
		}
		for ( $page = 1; $page <= self::PAGES; $page++ ) {
			$query = array(
				'parentIds' => (string) $this->config['folder_id'],
				'perPage'   => self::PER_PAGE,
				'page'      => $page,
			);
			if ( ! empty( $this->config['workspace'] ) ) {
				$query['workspaceId'] = (int) $this->config['workspace'];
			}
			$res = $this->call( 'GET', '/drive/file-entries', array( 'query' => $query ) );
			if ( false === $res ) {
				break;
			}
			$list = self::list_of( $res['body'] );
			foreach ( $list as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['id'], $item['name'] ) || ( isset( $item['type'] ) && 'folder' === $item['type'] ) ) {
					continue;
				}
				if ( isset( $item['parent_id'] ) && (int) $item['parent_id'] !== (int) $this->config['folder_id'] ) {
					continue;
				}
				$out[ (string) $item['name'] ][] = array(
					'id'   => (int) $item['id'],
					'size' => isset( $item['file_size'] ) ? (int) $item['file_size'] : 0,
				);
			}
			$last = isset( $res['body']['last_page'] ) ? (int) $res['body']['last_page'] : ( isset( $res['body']['fileEntries']['last_page'] ) ? (int) $res['body']['fileEntries']['last_page'] : 0 );
			if ( count( $list ) < self::PER_PAGE || ( $last > 0 && $page >= $last ) ) {
				break;
			}
		}
		$this->entries = $out;
		return $out;
	}

	private function ensure( $path ) {
		$segments = array_values( array_filter( explode( '/', trim( str_replace( '\\', '/', (string) $path ), '/ ' ) ), 'strlen' ) );
		$parent   = null;
		foreach ( $segments as $segment ) {
			$found = $this->find_folder( $segment, $parent );
			if ( false === $found ) {
				return false;
			}
			if ( 0 === $found ) {
				$found = $this->create_folder( $segment, $parent );
				if ( ! $found ) {
					return false;
				}
			}
			$parent = $found;
		}
		return null === $parent ? 0 : (int) $parent;
	}

	private function find_folder( $name, $parent ) {
		$query = array(
			'type'    => 'folder',
			'query'   => $name,
			'perPage' => 50,
		);
		if ( null !== $parent ) {
			$query['parentIds'] = (string) $parent;
		}
		if ( ! empty( $this->config['workspace'] ) ) {
			$query['workspaceId'] = (int) $this->config['workspace'];
		}
		$res = $this->call( 'GET', '/drive/file-entries', array( 'query' => $query ) );
		if ( false === $res ) {
			return false;
		}
		foreach ( self::list_of( $res['body'] ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['name'] ) || (string) $item['name'] !== (string) $name ) {
				continue;
			}
			$above = isset( $item['parent_id'] ) ? (int) $item['parent_id'] : 0;
			if ( ( null === $parent && 0 === $above ) || ( null !== $parent && $above === (int) $parent ) ) {
				return (int) $item['id'];
			}
		}
		return 0;
	}

	private function create_folder( $name, $parent ) {
		$payload = array( 'name' => $name );
		if ( null !== $parent ) {
			$payload['parentId'] = (int) $parent;
		}
		if ( ! empty( $this->config['workspace'] ) ) {
			$payload['workspaceId'] = (int) $this->config['workspace'];
			if ( null === $parent ) {
				$payload['targetWorkspaceId'] = (int) $this->config['workspace'];
			}
		}
		$res = $this->call( 'POST', '/folders', array( 'json' => $payload ), array( 200, 201 ) );
		if ( false === $res ) {
			return false;
		}
		if ( isset( $res['body']['folder']['id'] ) ) {
			return (int) $res['body']['folder']['id'];
		}
		return $this->fail( 'Files.ir did not create the folder' );
	}

	private function download_url( $id ) {
		$url = self::base() . '/file-entries/' . (int) $id;
		$res = $this->raw( 'GET', $url, array( 'Range' => 'bytes=0-0' ), null, 30 );
		if ( false === $res ) {
			return '';
		}
		if ( $res['code'] >= 300 && $res['code'] < 400 && ! empty( $res['headers']['location'] ) ) {
			return $this->resolve( (string) $res['headers']['location'] );
		}
		if ( in_array( $res['code'], array( 200, 206 ), true ) ) {
			return $url;
		}
		$this->fail( 'download refused: HTTP ' . $res['code'] );
		return '';
	}

	private static function list_of( $body ) {
		if ( ! is_array( $body ) ) {
			return array();
		}
		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			return $body['data'];
		}
		if ( isset( $body['fileEntries']['data'] ) && is_array( $body['fileEntries']['data'] ) ) {
			return $body['fileEntries']['data'];
		}
		return isset( $body[0] ) && is_array( $body[0] ) ? $body : array();
	}

	private function call( $method, $path, array $args = array(), array $expect = array( 200 ) ) {
		$url = 0 === strpos( $path, 'http' ) ? $path : self::base() . $path;
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args['query'] ) ), $url );
		}
		$headers = array( 'Accept' => 'application/json' );
		$body    = null;
		if ( array_key_exists( 'json', $args ) ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $args['json'] );
		}
		$retry = 'GET' === $method ? 3 : 1;
		$res   = false;
		for ( $try = 1; $try <= $retry; $try++ ) {
			$res = $this->raw( $method, $url, $headers, $body, isset( $args['timeout'] ) ? (int) $args['timeout'] : 30 );
			if ( false !== $res && 429 !== $res['code'] && $res['code'] < 500 ) {
				break;
			}
			if ( $try < $retry ) {
				sleep( 2 );
			}
		}
		if ( false === $res ) {
			return false;
		}
		$json = json_decode( (string) $res['body'], true );
		if ( ! in_array( $res['code'], $expect, true ) ) {
			$message = is_array( $json ) && isset( $json['message'] ) && is_string( $json['message'] ) ? $json['message'] : substr( wp_strip_all_tags( (string) $res['body'] ), 0, 160 );
			return $this->fail( 'Files.ir HTTP ' . $res['code'] . ( '' !== trim( $message ) ? ': ' . sanitize_text_field( $message ) : '' ) );
		}
		return array(
			'code' => $res['code'],
			'body' => is_array( $json ) ? $json : array(),
		);
	}

	private function raw( $method, $url, array $headers, $body, $timeout ) {
		$this->code = 0;
		$args       = array(
			'method'      => $method,
			'headers'     => $this->headers_for( $url, $headers ),
			'timeout'     => max( 5, (int) $timeout ),
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$res = wp_remote_request( $url, Client::args( $args ) );
		if ( is_wp_error( $res ) ) {
			return $this->fail( $res->get_error_message() );
		}
		$this->code = (int) wp_remote_retrieve_response_code( $res );
		$out        = array();
		$headers    = wp_remote_retrieve_headers( $res );
		$headers    = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		foreach ( $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return array(
			'code'    => $this->code,
			'headers' => $out,
			'body'    => (string) wp_remote_retrieve_body( $res ),
		);
	}

	private function send( $method, $url, array $headers, $local, $offset, $bytes, $wait ) {
		for ( $try = 1; $try <= 3; $try++ ) {
			if ( self::stream() ) {
				$res = FilesCurl::upload( $method, $url, $this->headers_for( $url, $headers ), $local, $offset, $bytes, $wait );
				if ( false === $res ) {
					$this->fail( FilesCurl::error() );
				}
			} else {
				if ( $bytes > self::MEMORY ) {
					return $this->fail( 'this upload needs the PHP cURL extension' );
				}
				$chunk = $this->read( $local, $offset, $bytes );
				$res   = false === $chunk ? false : $this->raw( $method, $url, $headers, $chunk, $wait );
			}
			if ( false !== $res && $res['code'] < 500 && 429 !== $res['code'] ) {
				return $res;
			}
			if ( false !== $res ) {
				$this->fail( 'storage HTTP ' . $res['code'] );
			}
			if ( $try < 3 ) {
				sleep( 2 );
			}
		}
		return false;
	}

	private function memory_multipart( $url, $local, $name, $size, $wait ) {
		if ( $size > self::MEMORY ) {
			return $this->fail( 'this upload needs the PHP cURL extension' );
		}
		$data = $this->read( $local, 0, $size );
		if ( false === $data ) {
			return false;
		}
		$boundary = 'rafoo' . strtolower( wp_generate_password( 24, false ) );
		$body     = '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="file"; filename="' . str_replace( '"', '', $name ) . '"' . "\r\n" . "Content-Type: application/octet-stream\r\n\r\n" . $data . "\r\n--" . $boundary . "--\r\n";
		unset( $data );
		return $this->raw( 'POST', $url, array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ), $body, $wait );
	}

	private function accepted( &$res ) {
		if ( false === $res ) {
			return $this->fail( '' !== $this->error ? $this->error : 'upload failed' );
		}
		if ( $res['code'] < 200 || $res['code'] >= 300 ) {
			$json    = json_decode( (string) $res['body'], true );
			$message = is_array( $json ) && isset( $json['message'] ) && is_string( $json['message'] ) ? ': ' . sanitize_text_field( $json['message'] ) : '';
			return $this->fail( 'upload refused: HTTP ' . $res['code'] . $message );
		}
		if ( is_string( $res['body'] ) ) {
			$json        = json_decode( $res['body'], true );
			$res['body'] = is_array( $json ) ? $json : array();
		}
		return true;
	}

	private function read( $local, $offset, $bytes ) {
		$in = Ledger::open( $local, 'rb' );
		if ( ! $in || 0 !== $in->fseek( (int) $offset ) ) {
			return $this->fail( 'local file unreadable' );
		}
		$data = (string) $in->fread( max( 1, (int) $bytes ) );
		return strlen( $data ) === (int) $bytes ? $data : $this->fail( 'local file changed while sending' );
	}

	private function append( $from, $to ) {
		$in  = Ledger::open( $from, 'rb' );
		$out = Ledger::open( $to, 'ab' );
		if ( ! $in || ! $out ) {
			return 0;
		}
		$written = 0;
		while ( ! $in->eof() ) {
			$chunk = (string) $in->fread( 1048576 );
			if ( '' === $chunk ) {
				break;
			}
			$written += (int) $out->fwrite( $chunk );
		}
		return $written;
	}

	private function metadata( $metadata ) {
		$out = array();
		foreach ( (array) $metadata as $name => $value ) {
			$out[] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $name ) . ' ' . base64_encode( (string) $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the tus protocol requires base64 metadata values.
		}
		return implode( ',', $out );
	}

	private function extra_headers( $headers ) {
		$out = array();
		foreach ( (array) $headers as $name => $value ) {
			if ( is_string( $name ) && preg_match( '/^[A-Za-z0-9\-]+$/', $name ) && 'authorization' !== strtolower( $name ) ) {
				$out[ $name ] = (string) $value;
			}
		}
		return $out;
	}

	private function headers_for( $url, array $headers = array() ) {
		$api  = strtolower( (string) wp_parse_url( self::base(), PHP_URL_HOST ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' !== $host && $host === $api ) {
			$headers['Authorization'] = 'Bearer ' . $this->token();
		} else {
			unset( $headers['Authorization'] );
		}
		$headers['User-Agent'] = Client::user_agent();
		return $headers;
	}

	private function resolve( $url ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$parts  = wp_parse_url( self::base() );
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return 0 === strpos( $url, '/' ) ? $origin . $url : self::base() . '/' . ltrim( $url, '/' );
	}

	private function token() {
		return $this->secret( 'pass' );
	}

	private function state_key( $name ) {
		return 'files-' . substr( md5( ( isset( $this->config['id'] ) ? (string) $this->config['id'] : '' ) . '|' . $name ), 0, 16 );
	}
}
