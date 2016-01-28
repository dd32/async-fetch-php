<?php

include __DIR__ . '/class-d-asyncsocket.php';
include __DIR__ . '/generator-get-ip.php';
include __DIR__ . '/generator-http-request.php';

foreach ( array(
	'http://dd32.id.au/', // redirect to ssl
//	'https://dd32.id.au/', // SSL is not working right now.. continuously hits error 115
	'http://google.com/', // Yeah, why not
	'http://httpbin.org/ip',
) as $url ) {

	echo "Checkout out $url\n";

	$gen = gen_http_request( $url );
	while ( true ) {
		$key = $gen->key();
		$current = $gen->current();
		switch ( $key ) {
			case 'error':
				echo "\nError!, " . $current[0] . ' ' . $current[1] . "\n";
				break 2;
			case 'notdone':
				echo '.';
				break;
			case 'result':
				var_dump( compact( 'url' ) + $current[1] );
				break 2;
		}

		$gen->next();
		usleep( 500 );
	}
	echo "\n\n";
} // end foreach
