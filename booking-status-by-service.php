<?php
add_filter( 'gpb_default_status', function( $status, $bookable, $entry ) {
	if ( ! $bookable ) {
		return $status;
	}

	$service_statuses = [
		10 => 'confirmed', // Service ID 10 books confirmed by default.
		13 => 'pending',   // Service ID 13 stays pending.
	];

	// Resource-backed services also create a Resource booking;
	// resolve it back to its service so the map applies once.
	if ( $bookable instanceof \GP_Bookings\Resource && $bookable->get_service() ) {
		$bookable = $bookable->get_service();
	}

	return $service_statuses[ $bookable->get_id() ] ?? $status;
}, 10, 3 );
?>
