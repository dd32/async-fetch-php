<?php

function gen_http_request( $url ) {
	yield 'notdone' => [ $url, 'Just Loaded' ]; // Always yield to start with to help with queueing up requests

	$url = trim( $url );
	if ( ! $url ) {
		yield 'error' => [ $url, 'No URL was provided' ];
	}

	$started_at = time();

	$parsed_url = parse_url( $url );
	$parsed_url['path']	= $parsed_url['path'] ?: '/';

	$request = "GET {$parsed_url['path']} HTTP/1.0\r\n" .
			"Host: {$parsed_url['host']}\r\n" .
			"User-Agent: Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36 Edge/12.10136\r\n" .
			"Connection: close\r\n" .
			"Accept: */*\r\n" .
			"pragma: no-cache\r\n" . 
			"dnt: 1\r\n" . 
			"\r\n";

	// GET THE IP
	$ip = false;
	$ip_gen = gen_get_ip( $parsed_url['host'] );
	while ( ! $ip && $ip_gen->key() ) {
		$ip_gen->next();
		list( $domain, $result ) = $ip_gen->current();
		switch( $ip_gen->key() ) {
			case 'notdone':
				yield 'notdone' => [ $url, 'Waiting on DNS' ];
				continue;
			case 'error':
				yield 'error' => [ $url, 'DNS resolution failed' ];
				continue;
			case 'result':
				$ip = $result;
				break;
		}
	}
	//var_dump( "Found the IP for $url it's $ip" );

	$connect_str = ( 'https' == $parsed_url['scheme'] ) ? "ssl://{$ip}:443" : "tcp://{$ip}:80";
	$timeout = 15;
	$context = stream_context_create( array(
		'ssl' => array(
			// We don't really care about validating HTTPS
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
		)
	) );

	$socket = new D_AsyncSocket( $connect_str, $timeout, $context );

	if ( $socket->error() ) {
		yield 'error' => [$url, "Connect failed with " . $socket->error() ];
	}
	
	do {

		if ( ( time() - $started_at ) > 15 ) {
			//echo "Giving up $url";
			unset( $socket );
			yield 'error' => [$url, 'Giving Up' ];
		}

		if ( $socket->error() ) {
			//echo "Error on $url\n";
			yield 'error' => [$url, 'URL Hung up on us' ];
		}

		if ( ! $socket->ready_for_write() ) {
			//echo "[$url] waiting for socket connect\n";
			yield 'notdone' => [ $url, 'waiting for socket connect' ];
			continue;
		}

		$wrote = $socket->write( $request, strlen($request) );
		$request = substr( $request, $wrote );

	} while ( ! $socket->error() && $request );

	$data = '';
	while ( ! $socket->error() && ! $socket->eof() ) {

		if ( ( time() - $started_at ) > 15 ) {
			//echo "Giving up $url";
			yield 'error' => [ $url, 'Giving Up' ];
		}

		if ( ! $socket->ready_for_read() ) {
			//echo "[$url] waiting for data\n";
			yield 'notdone' => [ $url, 'waiting for data' ];
			continue;
		}

		$data .= $socket->read( 8192 );
	}

	unset( $socket );

	if ( ! $data ) {
		//echo "[$url] No data\n";
		yield 'error' => [ $url, 'no data' ];
	}


	list( $headers, $body ) = parse_http_response( $data );

	$result = [ 'headers' => $headers, 'body' => $body, 'resolved_ip' => $ip ];

	yield 'result' => [ $url, $result ];

}


function parse_http_response( $data ) {
	list( $raw_headers, $body ) = explode( "\r\n\r\n", $data, 2 );

	$headers = array();
	foreach ( explode( "\n", $raw_headers ) as $h ) {
		if ( 'HTTP/' == substr( $h, 0, 5 ) ) {
			list( $protocol, $code, $message ) = explode( ' ', $h, 3 );
			$message = trim( $message );
			$headers['_code'] = compact( 'protocol', 'code', 'message' );
			continue;
		}
		list( $key, $val ) = explode( ':', $h, 2 );
		if ( isset( $headers[ $key ] ) ) {
			$headers[ $key ] .= ';' . $val;
		} else {
			$headers[ $key ] = trim( $val );
		}
	}

	return [ $headers, $body ];
}