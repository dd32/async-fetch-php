<?php

// Wrapper to make it easier to use `::ready_for_write()` and `::ready_for_read()`
class D_AsyncSocket {
	protected $socket;
	protected $error = false;

	function __construct( $connect_str, $timeout = 10 ) {
		$flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
		$this->socket = @stream_socket_client( $connect_str, $errno, $errorMessage, $timeout, $flags );

		if ( ! $this->socket || $errno ) {
			$this->error = $errno ? $errno . ' - ' . $errorMessage : false;
		}
	}

	public function error() {
		if ( $this->error ) {
			return $this->error;
		}
		if ( ! $this->socket ) {
			return true;
		}
	}

	public function ready_for_write() {
		$read = array();
		$write = array( $this->socket );
		$except = array();
		return (bool) stream_select( $read, $write, $except, 0 );
	}

	public function ready_for_read() {
		$read = array( $this->socket );
		$write = array();
		$except = array();
		return (bool) stream_select( $read, $write, $except, 0 );
	}

	public function write( $data, $length ) {
		return fwrite( $this->socket, $data, $length );
	}

	public function read( $length ) {
		return fread( $this->socket, $length );
	}

	public function eof() {
		return feof( $this->socket );
	}

	function __destruct() {
		if ( $this->socket ) {
			fclose( $this->socket );
		}
	}
}
