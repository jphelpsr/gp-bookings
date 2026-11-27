<?php
if ( ! defined( 'GPBRC_FORM_ID' ) ) {
	define( 'GPBRC_FORM_ID', 92 );
}

add_filter( 'gform_field_input_' . GPBRC_FORM_ID, 'gpbrc_render_resource_as_radio', 10, 5 );
/**
 * Convert a GP Bookings Resource field (Manual allocation) dropdown into a
 * radio group.
 */
function gpbrc_render_resource_as_radio( $input, $field, $value, $lead_id, $form_id ) {

	if ( ! $field instanceof GP_Bookings\Fields\Resource
		|| $field->gpbAllocationMode !== 'manual'
	) {
		return $input;
	}

	if ( \GFCommon::is_entry_detail_edit() || \GFCommon::is_form_editor() ) {
		return $input;
	}

	$form = \GFAPI::get_form( $form_id );
	if ( empty( $form ) ) {
		return $input;
	}

	// Let GP Bookings render first, then convert only its actual choice select.
	$rendered = $field->get_field_input( $form, $value );

	if ( strpos( $rendered, "data-allocation-mode='manual'" ) !== false ) {
		return gpbrc_select_to_radio( $rendered, $form_id, $field->id );
	}

	// "Select a service first" placeholder (manual-service mode): render an
	// empty radio group the JS will populate once a service is chosen.
	if ( strpos( $rendered, '<select' ) !== false ) {
		return gpbrc_service_placeholder_to_radio( $rendered, $form_id, $field->id );
	}

	return $rendered;
}

add_action( 'wp_head', 'gpbrc_resource_radio_styles' );
/**
 * Neutralize list bullets when GP Bookings' JS rebuilds resource radios.
 */
function gpbrc_resource_radio_styles() {
	if ( ! class_exists( 'GP_Bookings\Fields\Resource' ) || is_admin() ) {
		return;
	}

	$scope = '#gform_' . absint( GPBRC_FORM_ID );

	echo '<style id="gpbrc-resource-radio">'
		. "{$scope} .gfield--type-choice .ginput_container_resource .gfield_radio li.gchoice{list-style:none;margin:0;padding:0;}"
		. "{$scope} .gfield--type-choice .ginput_container_resource .gfield_radio li.gchoice label{display:inline-block;}"
		. "{$scope} .gpb-radio-placeholder{color:#8a8a8a;font-size:0.95em;}"
		. "{$scope} .ginput_container_resource .gchoice{display:flex;align-items:center;}"
		. '</style>' . "\n";
}

function gpbrc_service_placeholder_to_radio( $html, $form_id, $field_id ) {
	$attrs = '';
	foreach ( [ 'data-booking-field', 'data-service-field', 'data-gpb-default-value' ] as $attr ) {
		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '="([^"]*)"/', $html, $m ) ) {
			$attrs .= ' ' . $attr . '="' . esc_attr( $m[1] ) . '"';
		}
	}

	$radio  = "<div class='ginput_container ginput_container_resource' data-allocation-mode='manual'{$attrs}>";
	$radio .= "<div class='gfield_radio' id='input_{$form_id}_{$field_id}'>";
	$radio .= "<div class='gpb-radio-placeholder'>" . esc_html__( 'Select a service first', 'gp-bookings' ) . '</div>';
	$radio .= '</div></div>';

	return $radio;
}

add_filter( 'gform_field_css_class', 'gpbrc_resource_radio_field_css_class', 10, 3 );
/**
 * Add gfield--type-choice so Gravity Forms' choice styling applies (the same
 * class GP Bookings adds for Service field radios).
 */
function gpbrc_resource_radio_field_css_class( $classes, $field, $form ) {
	if ( (int) rgar( $form, 'id' ) !== (int) GPBRC_FORM_ID ) {
		return $classes;
	}

	if ( $field instanceof GP_Bookings\Fields\Resource
		&& $field->gpbAllocationMode === 'manual'
		&& strpos( $classes, 'gfield--type-choice' ) === false
	) {
		$classes .= ' gfield--type-choice';
	}
	return $classes;
}

function gpbrc_select_to_radio( $html, $form_id, $field_id ) {

	if ( ! preg_match( '#<select\b[^>]*>(.*?)</select>#is', $html, $select_match ) ) {
		return $html;
	}

	$default_attr = '';
	if ( preg_match( '/data-gpb-default-value="([^"]*)"/', $html, $default_match ) ) {
		$default_attr = 'data-gpb-default-value="' . esc_attr( $default_match[1] ) . '"';
	}

	$choices = array();

	if ( preg_match_all( '#<option\b[^>]*>(.*?)</option>#is', $select_match[1], $option_matches, PREG_SET_ORDER ) ) {
		foreach ( $option_matches as $match ) {
			$tag   = $match[0];
			$label = $match[1];

			$value = '';
			if ( preg_match( "/value='([^']*)'/", $tag, $v ) ) {
				$value = $v[1];
			} elseif ( preg_match( '/value="([^"]*)"/', $tag, $v ) ) {
				$value = $v[1];
			}

			$choices[] = array(
				'value'    => html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ),
				'label'    => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
				'selected' => (bool) preg_match( '/\bselected\b/i', $tag ),
			);
		}
	}

	if ( empty( $choices ) ) {
		return $html;
	}

	// Drop the placeholder option; a radio group is unselected by default.
	$choices = array_values( array_filter( $choices, function ( $choice ) {
		return $choice['value'] !== '';
	} ) );

	// Mirror GP Bookings' own manual-mode wrapper so its JS recognizes the
	// field identically (useDOMFieldSync, useResourceFieldUpdater).
	$radio  = "<div class='ginput_container ginput_container_resource' data-allocation-mode='manual'>";
	$radio .= "<div class='gfield_radio' id='input_{$form_id}_{$field_id}' {$default_attr}>";

	foreach ( $choices as $index => $choice ) {
		$input_id = "choice_{$form_id}_{$field_id}_{$index}";
		$checked  = $choice['selected'] ? "checked='checked'" : '';
		$radio   .= "<div class='gchoice gchoice_{$form_id}_{$field_id}_{$index}'>";
		$radio   .= "<input class='gfield-choice-input' name='input_{$field_id}' type='radio' value='"
			. esc_attr( $choice['value'] ) . "' id='{$input_id}' {$checked} />";
		$radio   .= "<label for='{$input_id}' id='label_{$form_id}_{$field_id}_{$index}' class='gform-field-label gform-field-label--type-inline'>"
			. esc_html( $choice['label'] ) . '</label>';
		$radio   .= '</div>';
	}

	$radio .= '</div></div>';

	return $radio;
}
