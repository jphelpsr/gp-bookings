<?php
add_filter( 'gpb_default_status', function( $status, $bookable, $entry ) {
	if ( ! $bookable ) {
		return $status;
	}

	$service_statuses = [
		10 =&gt; 'confirmed', // Service ID 10 books confirmed by default.
		13 =&gt; 'pending',   // Service ID 13 stays pending.
	];

	// Resource-backed services also create a Resource booking;
	// resolve it back to its service so the map applies once.
	if ( $bookable instanceof \GP_Bookings\Resource &amp;&amp; $bookable-&gt;get_service() ) {
		$bookable = $bookable-&gt;get_service();
	}

	return $service_statuses[ $bookable-&gt;get_id() ] ?? $status;
}, 10, 3 );
?>
