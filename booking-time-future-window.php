<?php
/**
 * GP Bookings — Booking Time Future Window
 *
 * Control how far into the future each Booking Time field and/or Service can be booked.
 * Powered by the gpb_availability_end filter.
 *
 * GP Bookings has no server-side limit on how far ahead bookings can be made. The ~2 year
 * look-ahead you see in the Booking Time calendar is a frontend fallback that only applies
 * when no gpb_availability_end filter returns a date. Returning one overrides it in BOTH
 * directions: it tightens or extends the calendar range AND enforces the limit at booking
 * validation.
 */

add_filter( 'gpb_availability_end', 'gpbt_limit_future_window', 10, 3 );

/**
 * Apply the configured future window for the service being checked.
 *
 * Precedence when a Booking Time field rule and a service rule both match:
 * the field rule wins (it's scoped to that specific booking UI); service rules
 * are the general fallback.
 *
 * @param \Carbon\CarbonImmutable|null $end          Current end (null = no restriction).
 * @param \GP_Bookings\Service|null    $service      Service being checked.
 * @param int[]                        $resource_ids Resource IDs being checked.
 * @return \Carbon\CarbonImmutable|null
 */
function gpbt_limit_future_window( $end, $service, $resource_ids ) {
	if ( ! class_exists( '\GP_Bookings\Service' ) || ! $service instanceof \GP_Bookings\Service ) {
		return $end;
	}

	$config     = gpbt_future_window_config();
	$service_id = (int) $service->get_id();

	// 1. Booking Time field rules — most specific, wins over service rules.
	if ( ! empty( $config['booking_time_fields'] ) ) {
		$field_services = gpbt_booking_time_field_services();
		foreach ( $config['booking_time_fields'] as $key => $spec ) {
			if ( isset( $field_services[ (string) $key ][ $service_id ] ) ) {
				return gpbt_resolve_window( $spec );
			}
		}
	}

	// 2. Service rules — general fallback.
	if ( isset( $config['services'][ $service_id ] ) ) {
		return gpbt_resolve_window( $config['services'][ $service_id ] );
	}

	return $end;
}

/**
 * Configure your future windows here.
 *
 * Service rules:    service ID => window spec.
 * Field rules:      Booking Time field ID => window spec. Matches that field in any form.
 *                   Use '<formId>.<fieldId>' (e.g. '12.3') to target one form's field.
 *
 * Window spec accepts:
 *   - Natural language: '30 days', '6 months', '18 months', '2 years', '1 year 6 months'
 *   - DateInterval spec: 'P90D', 'P6M', 'P1Y6M'
 *   - Absolute date:    '2027-12-31' (window ends end-of-day, inclusive)
 *   - null:             explicitly no restriction (same as not configuring the rule)
 *
 * The window starts "today" in the site timezone and ends at the end of the final day
 * (inclusive), matching GP Bookings' own timezone handling.
 *
 */
function gpbt_future_window_config() {
	$config = [
		'services' => [
			// 72 => '1 year',
		],

		'booking_time_fields' => [
			92.6  => '1 year',
		],
	];

	/**
	 * Filter the future window configuration (alternative to editing the arrays above).
	 *
	 * @param array $config See gpbt_future_window_config() for the shape.
	 */
	return apply_filters( 'gpbt_future_window_config', $config );
}

/**
 * Resolve a window spec to an inclusive end-of-day CarbonImmutable in the site timezone.
 *
 * @param string|null|\Carbon\CarbonImmutable $spec Window spec.
 * @return \Carbon\CarbonImmutable|null
 */
function gpbt_resolve_window( $spec ) {
	if ( null === $spec || '' === $spec ) {
		return null; // No restriction.
	}

	$today = \GP_Bookings\Utils\DateTimeUtils::today();

	try {
		if ( $spec instanceof \Carbon\CarbonImmutable ) {
			return $spec->endOfDay();
		}

		if ( is_string( $spec ) && preg_match( '/^P\d/i', $spec ) ) {
			// DateInterval spec: 'P90D', 'P6M', 'P1Y6M'.
			return $today->add( new \DateInterval( $spec ) )->endOfDay();
		}

		if ( is_string( $spec ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $spec ) ) {
			// Absolute date: window ends end-of-day, inclusive.
			return \GP_Bookings\Utils\DateTimeUtils::parse( $spec )->endOfDay();
		}

		// Natural language: '90 days', '6 months', '2 years', '1 year 6 months'.
		return $today->add( \Carbon\CarbonInterval::fromString( $spec ) )->endOfDay();
	} catch ( \Throwable $e ) {
		trigger_error( 'GP Bookings future window: invalid spec ' . wp_json_encode( $spec ) . ' — ' . $e->getMessage(), E_USER_WARNING );
		return null;
	}
}

/**
 * Map Booking Time field IDs to the service IDs they can book.
 *
 * Keys are the field ID (e.g. '3', matches any form) and '<formId>.<fieldId>'
 * (e.g. '12.3'). Values are arrays keyed by service ID.
 *
 * Cached for an hour; invalidated when forms or services are saved.
 *
 * @return array<string, array<int, true>>
 */
function gpbt_booking_time_field_services() {
	$cached = get_transient( 'gpbt_booking_time_field_services' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$map = [];

	foreach ( \GFFormsModel::get_forms( true ) as $summary ) {
		$form = \GFAPI::get_form( (int) $summary->id );
		if ( ! $form || empty( $form['fields'] ) ) {
			continue;
		}

		// Service fields grouped by the booking field they are linked to.
		$service_fields = [];
		foreach ( $form['fields'] as $field ) {
			if ( $field->type === 'gpb_service' && ! empty( $field->gpbBookingField ) ) {
				$service_fields[ (int) $field->gpbBookingField ][] = $field;
			}
		}

		foreach ( $form['fields'] as $field ) {
			if ( $field->type !== 'gpb_booking_time' ) {
				continue;
			}

			$service_ids = [];
			foreach ( $service_fields[ (int) ( $field->gpbBookingField ?? 0 ) ] ?? [] as $service_field ) {
				$service_ids = array_merge( $service_ids, gpbt_service_field_service_ids( $service_field ) );
			}

			// Legacy direct link (no booking field).
			if ( ! $service_ids && ! empty( $field->gpbServiceField ) ) {
				foreach ( $form['fields'] as $candidate ) {
					if ( (int) $field->gpbServiceField === (int) $candidate->id ) {
						$service_ids = gpbt_service_field_service_ids( $candidate );
						break;
					}
				}
			}

			foreach ( array_unique( $service_ids ) as $service_id ) {
				$map[ (string) $field->id ][ (int) $service_id ]      = true;
				$map[ $form['id'] . '.' . $field->id ][ $service_id ] = true;
			}
		}
	}

	set_transient( 'gpbt_booking_time_field_services', $map, HOUR_IN_SECONDS );

	return $map;
}

/**
 * Service IDs a Service field can offer.
 *
 * @param \GF_Field $service_field The gpb_service field.
 * @return int[]
 */
function gpbt_service_field_service_ids( $service_field ) {
	if ( ( $service_field->gpbSelectionMode ?? 'preselected' ) === 'manual' ) {
		if ( ! empty( $service_field->gpbServices ) && is_array( $service_field->gpbServices ) ) {
			return array_map( 'intval', $service_field->gpbServices );
		}

		// No services configured — the field offers every published service.
		return array_map(
			'intval',
			get_posts( [
				'post_type'      => 'gpb_service',
				'post_status'   => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] )
		);
	}

	return [ (int) $service_field->gpbService ];
}

// Invalidate the field → services map when forms or services change.
add_action( 'gform_after_save_form', 'gpbt_flush_field_service_map' );
add_action( 'save_post_gpb_service', 'gpbt_flush_field_service_map' );
add_action( 'trashed_post', 'gpbt_flush_field_service_map' );
add_action( 'untrashed_post', 'gpbt_flush_field_service_map' );

/**
 * Delete the cached field → services map.
 *
 * @return void
 */
function gpbt_flush_field_service_map() {
	delete_transient( 'gpbt_booking_time_field_services' );
}
?>
