<?php

/*
Plugin Name: WP All Import - Estatik Add-On
Description: Import properties into Estatik. Supports Estatik theme and the Estatik plugin.
Version: 1.0.0
*/


include "rapid-addon.php";

if ( ! function_exists( 'is_plugin_active' ) ) {

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

}

$estatik_addon = new RapidAddon('Estatik Add-On', 'estatik_addon');

$estatik_addon->add_field(
	'location_settings',
	'Property Map Location',
	'radio', 
	array(
		'search_by_address' => array(
			'Search by Address',
			$estatik_addon->add_options( 
				$estatik_addon->add_field(
					'es_property_address',
					'Property Address',
					'text'
				),
				'Google Geocode API Settings', 
				array(
					$estatik_addon->add_field(
						'address_geocode',
						'Request Method',
						'radio',
						array(
							'address_no_key' => array(
								'No API Key',
								'Limited number of requests.'
							),
							'address_google_developers' => array(
								'Google Developers API Key - <a href="https://developers.google.com/maps/documentation/geocoding/#api_key">Get free API key</a>',
								$estatik_addon->add_field(
									'address_google_developers_api_key', 
									'API Key', 
									'text'
								),
								'Up to 2,500 requests per day and 5 requests per second.'
							),
							'address_google_for_work' => array(
								'Google for Work Client ID & Digital Signature - <a href="https://developers.google.com/maps/documentation/business">Sign up for Google for Work</a>',
								$estatik_addon->add_field(
									'address_google_for_work_client_id', 
									'Google for Work Client ID', 
									'text'
								), 
								$estatik_addon->add_field(
									'address_google_for_work_digital_signature', 
									'Google for Work Digital Signature', 
									'text'
								),
								'Up to 100,000 requests per day and 10 requests per second'
							)
						) // end Request Method options array
					) // end Request Method nested radio field 
				) // end Google Geocode API Settings fields
			) // end Google Gecode API Settings options panel
		), // end Search by Address radio field
		'search_by_coordinates' => array(
			'Enter Coordinates',
			$estatik_addon->add_field(
				'es_property_latitude', 
				'Latitude', 
				'text', 
				null, 
				'Example: 34.0194543'
			),
			$estatik_addon->add_field(
				'es_property_longitude', 
				'Longitude', 
				'text', 
				null, 
				'Example: -118.4911912'
			) // end coordinates Option panel
		) // end Search by Coordinates radio field
	) // end Property Location radio field
);

$estatik_addon->set_import_function('estatik_import');

$estatik_addon->admin_notice();

$estatik_addon->disable_default_images();

$estatik_addon->run(
	array(
		"post_types" => array("properties")
	)	
);


function estatik_import($post_id, $data, $import_options, $article) {

	global $estatik_addon;

	    // clear image fields to override import settings
	    $fields = array(
	    	'es_property_gallery'
	    );

	    if ( empty( $article['ID'] ) or $estatik_addon->can_update_image( $import_options ) ) {

	    	foreach ($fields as $field) {

		    	delete_post_meta($post_id, $field);

		    }

	    }

	    // update property location
	    $field   = 'es_property_address';

	    $address = $data[$field];

	    $lat  = $data['es_property_latitude'];

		$long = $data['es_property_longitude'];
		
		$api_key = null;

		$geocoding_failed = false;
	    
	    //  build search query
	    if ( $data['location_settings'] == 'search_by_address' ) {

	    	$search = ( !empty( $address ) ? 'address=' . rawurlencode( $address ) : null );

	    } else {

	    	$search = ( !empty( $lat ) && !empty( $long ) ? 'latlng=' . rawurlencode( $lat . ',' . $long ) : null );

	    }

	    // build api key
	    if ( $data['location_settings'] == 'search_by_address' ) {
	    
	    	if ( $data['address_geocode'] == 'address_google_developers' && !empty( $data['address_google_developers_api_key'] ) ) {
	        
		        $api_key = '&key=' . $data['address_google_developers_api_key'];
		    
		    } elseif ( $data['address_geocode'] == 'address_google_for_work' && !empty( $data['address_google_for_work_client_id'] ) && !empty( $data['address_google_for_work_signature'] ) ) {
		        
		        $api_key = '&client=' . $data['address_google_for_work_client_id'] . '&signature=' . $data['address_google_for_work_signature'];

		    }

	    }

	    // if all fields are updateable and $search has a value
	    if ( empty( $article['ID'] ) or ( $estatik_addon->can_update_meta( $field, $import_options ) && $estatik_addon->can_update_meta( 'es_property_latitude', $import_options ) && $estatik_addon->can_update_meta( 'es_property_longitude', $import_options ) && !empty ( $search ) ) ) {
	        
	        // build $request_url for api call
	        $request_url = 'https://maps.googleapis.com/maps/api/geocode/json?' . $search . $api_key;

	        $estatik_addon->log( '- Getting location data from Geocoding API: ' . $request_url );

	        $json = wp_remote_retrieve_body( wp_remote_get($request_url) );

	        // parse api response
	        if ( !empty( $json ) ) {

				$details = json_decode( $json, true );
				
				if ( array_key_exists( 'status', $details ) ) {
					if ( $details['status'] == 'INVALID_REQUEST' || $details['status'] == 'ZERO_RESULTS' || $details['status'] == 'REQUEST_DENIED' ) {
						$geocoding_failed = true;
						goto invalidrequest;
					}
				}

	            if ( $data['location_settings'] == 'search_by_address' ) {

		            $lat  = $details['results'][0]['geometry']['location']['lat'];

		            $long = $details['results'][0]['geometry']['location']['lng'];
					
					$formatted_address = $details['results'][0]['formatted_address'];
					
					$components = $details['results'][0]['address_components'];

		        } else {

		        	$address = $details['results'][0]['formatted_address'];

		        }

	        }
	        
	    }
	    
	    // update location fields
	    $fields = array(
	        'es_property_address' => $address,
			'es_property_address_components' => json_encode($components, JSON_UNESCAPED_UNICODE),		
	        'es_property_latitude' => $lat,
	        'es_property_longitude' => $long,
	    );

	    $estatik_addon->log( '- Updating location data' );
	    
	    foreach ( $fields as $key => $value ) {
	        
	        if ( empty( $article['ID'] ) or $estatik_addon->can_update_meta( $key, $import_options ) ) {
	            
	            update_post_meta( $post_id, $key, $value );
	        
	        }
		}
		
		invalidrequest:

		if ( $geocoding_failed ) {
			delete_post_meta( $post_id, 'geolocated' );
			$estatik_addon->log( "WARNING Geocoding failed with status: " . $details['status'] );
			if ( array_key_exists( 'error_message', $details ) ) {
				$estatik_addon->log( "WARNING Geocoding error message: " . $details['error_message'] );
			}
		}

}

add_action( 'pmxi_saved_post', 'estatik_addon_update_post_meta_fields', 10, 1 );

function estatik_addon_update_post_meta_fields( $post_id ) {
	
	$post_type = get_post_type( $post_id );

	if ( $post_type == 'properties' ) {

		// build array of all set fields, whether they were imported or not
		$fields = get_post_custom($post_id);

		$property_meta_fields = array();

		foreach($fields as $key => $values) {	

			$value = $values[0];

			// delete empty property_ postmeta
			if (strpos($key,'property_') !== false && empty($value)) {

				delete_post_meta($post_id, $key);

			} elseif (strpos($key,'_property_') !== false && $key != '_property_meta_fields') {

				$property_meta_fields[] = $key;

			}
		}

	}

}

