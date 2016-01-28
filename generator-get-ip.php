<?php

if ( ! defined( 'DNS_SERVER' ) ) {
	define( 'DNS_SERVER', '8.8.8.8' );
}

function gen_get_ip( $domain ) {
	$domain = "{$domain}.";
	$dns = DNS_SERVER;
	$timeout = 30;
	$started_at = time();

	/* Message ID, ( QR, OPCODE, AA, TC ), Recursion Desired, Recursion Avail, Response Code, Entries in Question?? */
	$data = pack('n6', rand(10, 77), 0x0100, 1, 0, 0, 0);

	/* Question */
	foreach ( explode( '.', $domain ) as $bit ) {
    		$l = strlen( $bit );
	    	$data .= chr( $l ) . $bit;
	}
	$data .= pack( 'n2', 1, 1 );  // QTYPE=A, QCLASS=IN

	$socket = new D_AsyncSocket( "udp://{$dns}:53", $timeout );

	if ( $socket->error() ) {
		yield 'error' => [ $domain, "failed to connect" ];
	}

	do {

		if ( ( time() - $started_at ) > $timeout ) {
			//echo "Giving up $url";
			unset( $socket );
			yield 'error' => [$domain, 'Giving Up' ];
		}

		if ( $socket->error() ) {
			//echo "Error on $url\n";
			yield 'error' => [$domain, 'DNS Hung up on us' ];
		}

		if ( ! $socket->ready_for_write() ) {
			//echo "[$url] waiting for socket connect\n";
			yield 'notdone' => [ $domain, 'waiting for socket connect' ];
			continue;
		}

		$wrote = $socket->write( $data, strlen($data) );
		$data = substr( $data, $wrote );

	} while ( $data );

	$data = '';
	$ip = false;
	while ( ! $ip && ! $socket->error() && ! $socket->eof() ) {

		if ( ( time() - $started_at ) > $timeout ) {
			//echo "Giving up $url";
			yield 'error' => [ $domain, 'Giving Up' ];
		}

		if ( ! $socket->ready_for_read() ) {
			//echo "[$url] waiting for data\n";
			yield 'notdone' => [ $domain, 'waiting for data' ];
			continue;
		}

		$data .= $socket->read( 8192 );
		$data_length = strlen( $data );

		$i = 0;
		if ( $data_length >= 12 ) {
			list(, $message_id, $QR_OPCODE_AA_TC_RD_RA_RCODE, $QDCOUNT, $ANCOUNT, $NSCOUNT, $ARCOUNT ) = unpack( 'n6', substr( $data, 0, 12 ) );

			$QR_OPCODE_AA_TC_RD_RA_RCODE = decbin( $QR_OPCODE_AA_TC_RD_RA_RCODE );
			// Format of $QR_OPCODE_AA_TC_RD_RA_RCODE uint16 in binary
			// 1  0000   0  0  1  1  0    0    0    0000
			// QR OPCODE AA TC RD RA res1 res2 res3 RCODE
			$QR     = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE,  0, 1 ) );
			$OPCODE = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE,  1, 4 ) );
			$AA     = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE,  5, 1 ) );
			$TC     = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE,  6, 1 ) );
			$RD     = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE,  7, 1 ) );
			$RA     = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE,  8, 1 ) );
			// $res1, $res2, $res2 = 9, 10, and 11
			$RCODE  = bindec( substr( $QR_OPCODE_AA_TC_RD_RA_RCODE, 12, 4 ) );
			unset( $QR_OPCODE_AA_TC_RD_RA_RCODE );

		//	var_dump( compact( 'message_id', 'QR', 'OPCODE', 'AA', 'TC', 'RD', 'RA', 'RCODE', 'QDCOUNT', 'ANCOUNT', 'NSCOUNT', 'ARCOUNT' ) );

			if ( $RCODE > 0 ) {
				unset( $socket );
				yield 'error' => [ $domain, 'Error code raised: ' . $RCODE ];
			}
			$i = 12;
		}
		if ( $data_length > $i ) {
			$answer = '';
			while( "\0" != substr( $data, $i, 1 ) ) {
				$field_length = ord( substr( $data, $i, 1 ) );
				$answer .= substr( $data, $i+1, $field_length ) . '.';
				$i += $field_length + 1;
			}
		//	var_dump( compact( 'answer' ) );
		}

		if ( $data_length >= $i + 5 ) {
			$i++;
			list(, $qtype, $qclass ) = unpack( 'n2', substr( $data, $i, 4 ) );
		//	var_dump( compact( 'qtype', 'qclass' ) );
			$i += 4;
		}

		if ( $data_length > $i ) {
			for ( $answer = 1; $answer <= $ANCOUNT; $answer++ ) {

				list(, $NAME, $TYPE, $CLASS) = unpack( 'n3', substr( $data, $i, 6 ) );
				$i += 6;
	
				list(, $TTL ) = unpack( 'N', substr( $data, $i, 4 ) );
				$i += 4;

				list(, $RDLENGTH ) = unpack( 'n', substr( $data, $i, 2 ) );
				$i += 2;
	
				// IPv4 is always $RDLENGTH = 4, others 2
				if ( 1 == $TYPE && 4 == $RDLENGTH ) {			
					list(, $RDATA ) = unpack( 'N', substr( $data, $i, $RDLENGTH ) );
					$ip = long2ip( $RDATA );
				} elseif ( $RDLENGTH > 4 ) { // We don't actually need to know this field.
					// Assume FQDN.
					$_RDATA = substr( $data, $i, $RDLENGTH );
					$_i = 0;
					$RDATA = '';
					while( "\0" != substr( $_RDATA, $_i, 1 ) ) {
						$field_length = ord( substr( $_RDATA, $_i, 1 ) );
						$RDATA .= substr( $_RDATA, $_i+1, $field_length ) . '.';
						$_i += $field_length + 1;
					}
					unset( $_RDATA, $_i, $field_length );
				} else {
					// We don't really care about this anyway...
					$RDATA = substr( $data, $i, $RDLENGTH );
				}
				$i += $RDLENGTH;
	
//				var_dump( compact( 'NAME', 'TYPE', 'CLASS', 'TTL', 'RDLENGTH', 'RDATA' ) );
			}
		}

	}

	unset( $socket );
	
	yield 'result' => [ $answer, $ip ];
}