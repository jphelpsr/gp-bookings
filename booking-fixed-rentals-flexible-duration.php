<?php
/**
  * Gravity Perks // Bookings // Saturday-to-Saturday Weekly Rentals
 *
  * Required service settings:
  * - Block Size: Full Day
  * - Treat as Nights: enabled
  * - Flexible Duration: enabled
  * - Minimum Duration: 7 nights
  * - Maximum Duration: 28 nights
 *
  * Replace the service ID(s) below.
 *
  * Do not run the original Fixed Check-in and Check-out Days snippet
  * for the same services alongside this replacement.
 */

/**
  * Limit these rules to the specified GP Bookings services.
 */
function gw_yacht_weekly_applies( $service ) {
	$service_ids = array( 11 );

	return in_array( (int) $service->get_id(), $service_ids, true );
}

/**
  * Only allow Saturdays as check-in and check-out dates.
 *
  * Intervening weekdays remain available within the selected stay.
  * GP Bookings still applies its availability and capacity checks.
 */
add_filter( 'gpb_calendar_date_endpoints', function( $endpoints, $date, $service ) {
	if ( gw_yacht_weekly_applies( $service ) ) {
		$is_saturday = (int) $date->format( 'N' ) === 6;

		$endpoints['start'] = $is_saturday;
		$endpoints['end']   = $is_saturday;
	}

	return $endpoints;
}, 10, 3 );

/**
  * Enforce the same restriction during server-side validation.
 */
add_filter( 'gpb_validate_booking_duration', function( $result, $start, $end, $service ) {
	if ( ! $result['is_valid'] || ! gw_yacht_weekly_applies( $service ) ) {
		return $result;
	}

	// Count calendar nights rather than dividing elapsed hours by 24.
	$nights = (int) $start->startOfDay()->diffInDays( $end->startOfDay() );

	$is_allowed =
		(int) $start->format( 'N' ) === 6
		&& (int) $end->format( 'N' ) === 6
		&& in_array( $nights, array( 7, 14, 21, 28 ), true );

	if ( ! $is_allowed ) {
		return array(
			'is_valid' => false,
			'message'  => 'Please book Saturday to Saturday for 7, 14, 21 or 28 nights.',
		);
	}

	return $result;
}, 10, 4 );
