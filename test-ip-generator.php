<?php

include __DIR__ . '/class-d-asyncsocket.php';
include __DIR__ . '/generator-get-ip.php';

foreach ( array(
	's.w.org', // cname
	's.w.dfd', // invalid
	'dd32.id.au', // ipv4 + ipv6
	'www.wordpress.org', // cname to parent
) as $hostname ) {

	echo "Looking up $hostname\n";

	$gen = gen_get_ip( $hostname );
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
				echo "\nWhoop, The IP for $hostname is " . $current[1] . "\n";
				break 2;
		}

		$gen->next();
		usleep( 500 );
	}
	echo "\n\n";
} // end foreach