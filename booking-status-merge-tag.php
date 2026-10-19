<?php
add_filter( 'gform_replace_merge_tags', function (
	$text,
	$form,
	$entry,
	$url_encode,
	$esc_html,
	$nl2br,
	$format
) {
	if (
		! is_string( $text )
		|| strpos( $text, '{gpb_booking_status}' ) === false
	) {
		return $text;
	}

	$status = '';

	if (
		! empty( $entry['id'] )
		&& class_exists( '\GP_Bookings\Queries\Booking_Query' )
	) {
		$bookings = \GP_Bookings\Queries\Booking_Query::get_entry_bookings(
			(int) $entry['id']
		);

		foreach ( $bookings as $booking ) {
			if ( $booking->get_type() !== 'service' ) {
				continue;
			}

			$status = $booking->get_status();
			break;
		}
	}

	if (
		$status !== ''
		&& class_exists( '\GP_Bookings\Booking' )
	) {
		$status = \GP_Bookings\Booking::get_status_label( $status );
	} elseif ( $status !== '' ) {
		$status = ucfirst( $status );
	}

	if ( $esc_html ) {
		$status = esc_html( $status );
	}

	if ( $url_encode ) {
		$status = urlencode( $status );
	}

	return str_replace(
		'{gpb_booking_status}',
		$status,
		$text
	);
}, 99, 7 );
?>
