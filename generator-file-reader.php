<?php

function gen_file_reader( $file ) {
	$fp = fopen( $file, 'r' );
	try {
		while( $line = fgets( $fp ) ) {
			yield trim( $line );
		}
	} finally {
		fclose( $fp );
	}
}