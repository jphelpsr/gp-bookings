<?php
/**
 * 
 * Use {gpb_booking_status} in confirmations, notifications, and Calendar Sync.
 * Like the original snippet, entries with multiple services use the first one.
 */
class GW_GPB_Booking_Status_Merge_Tag {
	private static $transitions = [];

	public static function init() {
		add_action( 'gpb_status_transitioned', [ __CLASS__, 'begin_transition' ], -9999, 3 );
		add_action( 'gpb_status_transitioned', [ __CLASS__, 'end_transition' ], PHP_INT_MAX );
		add_filter( 'gform_replace_merge_tags', [ __CLASS__, 'replace' ], 99, 7 );
	}

	public static function begin_transition( $booking, $new_status, $old_status ) {
		$service_id = $booking->get_type() === 'service' ? $booking->get_id() : $booking->get_parent_booking_id();
		self::$transitions[] = [
			'booking_id' => (int) $service_id,
			'status'     => $new_status,
		];
	}

	public static function end_transition() {
		array_pop( self::$transitions );
	}

	public static function replace( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		if ( ! is_string( $text ) || strpos( $text, '{gpb_booking_status}' ) === false ) {
			return $text;
		}

		$status = '';
		if ( ! empty( $entry['id'] ) && class_exists( '\GP_Bookings\Queries\Booking_Query' ) ) {
			$bookings = \GP_Bookings\Queries\Booking_Query::get_entry_bookings( (int) $entry['id'] );
			foreach ( $bookings as $booking ) {
				if ( $booking->get_type() !== 'service' ) {
					continue;
				}

				$status = $booking->get_status();
				// Resource Calendar pushes can occur before the service row is updated.
				// Use the announced status only while that transition hook is running.
				foreach ( array_reverse( self::$transitions ) as $transition ) {
					if ( $transition['booking_id'] === (int) $booking->get_id() ) {
						$status = $transition['status'];
						break;
					}
				}
				break;
			}
		}

		if ( $status !== '' ) {
			$status = is_callable( [ '\GP_Bookings\Booking', 'get_status_label' ] )
				? \GP_Bookings\Booking::get_status_label( $status )
				: ucfirst( $status );
		}
		if ( $esc_html ) {
			$status = esc_html( $status );
		}
		if ( $url_encode ) {
			$status = urlencode( $status );
		}

		return str_replace( '{gpb_booking_status}', $status, $text );
	}
}

GW_GPB_Booking_Status_Merge_Tag::init();
