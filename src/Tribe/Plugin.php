<?php

namespace Tribe\Extensions\OutlookExportButtons;

use tad_DI52_ServiceProvider;

/**
 * Class Plugin
 *
 * @package Tribe\Extensions\OutlookExportButtons
 * @since   1.0.0
 *
 */
class Plugin extends tad_DI52_ServiceProvider {
	/**
	 * Stores the version for the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Stores the base slug for the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const SLUG = 'outlook-export-buttons';

	/**
	 * Stores the base slug for the extension.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const FILE = TRIBE_EXTENSION_OUTLOOK_EXPORT_BUTTONS_FILE;

	/**
	 * @since 1.0.0
	 *
	 * @var string Plugin Directory.
	 */
	public $plugin_dir;

	/**
	 * @since 1.0.0
	 *
	 * @var string Plugin path.
	 */
	public $plugin_path;

	/**
	 * @since 1.0.0
	 *
	 * @var string Plugin URL.
	 */
	public $plugin_url;

	/**
	 * Setup the Extension's properties.
	 *
	 * This always executes even if the required plugins are not present.
	 *
	 * @since 1.0.0
	 */
	public function register() {
		// Set up the plugin provider properties.
		$this->plugin_path = trailingslashit( dirname( static::FILE ) );
		$this->plugin_dir  = trailingslashit( basename( $this->plugin_path ) );
		$this->plugin_url  = plugins_url( $this->plugin_dir, $this->plugin_path );

		// Register this provider as the main one and use a bunch of aliases.
		$this->container->singleton( static::class, $this );
		$this->container->singleton( 'extension.outlook_export_buttons', $this );
		$this->container->singleton( 'extension.outlook_export_buttons.plugin', $this );
		$this->container->register( PUE::class );

		if ( ! $this->check_plugin_dependencies() ) {
			// If the plugin dependency manifest is not met, then bail and stop here.
			return;
		}

		// Start binds.

		add_filter( 'tribe_events_ical_single_event_links', [ $this, 'generate_outlook_markup' ], 10, 1 );

		add_filter( 'tribe_template_path_list', [ $this, 'alternative_template_locations' ], 10, 2 );

		// End binds.

		$this->container->register( Hooks::class );
		$this->container->register( Assets::class );
	}

	/**
	 * Generate the parameters for the Outlook export buttons.
	 *
	 * @param $calendar Whether it's Outlook live or Outlook 365.
	 *
	 * @return string   Part of the URL containing the event information.
	 */
	public function generate_outlook_add_url( $calendar = 'live' ) {
		// Getting the event details
		$event = tribe_get_event();

		$path = '/calendar/action/compose';
		$rrv = 'addevent';

		$startdt = $event->start_date_utc;
		$enddt = $event->end_date_utc;

		/**
		 * If event is an all day event, then adjust the end time.
		 * Using the 'allday' parameter doesn't work well through time zones.
		 */
		if ( $event->all_day ) {
			$enddt = date( 'Y-m-d', strtotime( $enddt ) )
				. 'T'
				. date( 'H:i:s', strtotime( $startdt ) )
				. date( 'P', strtotime( $enddt ) );
		} else {
			$enddt = date( 'c', strtotime( $enddt ) ); // 17 chars
		}

		$startdt = date( 'c', strtotime( $startdt ) );

		$subject = $this->space_replace_and_encode( strip_tags( $event->post_title ) ); // 8+ chars

		/**
		 * A filter to hide or show the event description
		 *
		 * @param bool $include_event_description
		 */
		$include_event_description = (bool) apply_filters( 'tribe_events_ical_outlook_include_event_description', true );

		if ( $include_event_description ) {
			$body = $event->post_content;

			// Stripping tags
			$body = strip_tags( $body, '<p>' );

			// Truncate Event Description and add permalink if greater than 900 characters
			if ( strlen( $body ) > 900 ) {

				$body = substr( $body, 0, 900 );

				$event_url = get_permalink( $event->ID );

				//Only add the permalink if it's shorter than 900 characters, so we don't exceed the browser's URL limits (~2000)
				if ( strlen( $event_url ) < 900 ) {
					$body .= ' ' . sprintf( esc_html__( '(View Full %1$s Description Here: %2$s)', 'the-events-calendar' ), tribe_get_event_label_singular(), $event_url );
				}
			}

			/**
			 * Allows the filtering the length of the event description
			 *
			 * @param bool|int $num_words
			 */
			$num_words = apply_filters( 'tribe_events_ical_outlook_event_description_num_words', false );

			// Encoding and trimming
			if ( (int)$num_words > 0 ) {
				$body = wp_trim_words( $body, $num_words );
			}

			// Changing the spaces to %20, Outlook can take that.
			$body = $this->space_replace_and_encode( $body );
		} else {
			$body = false;
		}

		$params = [
			'path'    => $path,
			'rrv'     => $rrv,
			'startdt' => $startdt,
			'enddt'   => $enddt,
			'subject' => $subject,
			'body'    => $body,
		];

		return $params;
		/*$base_url = 'https://outlook.' . $calendar .'.com/calendar/0/deeplink/compose/';
		$url      = add_query_arg( $params, $base_url );

		return $url;*/
	}

	/**
	 * Generate the full "Add to calendar" URL.
	 *
	 * @param $calendar Whether it's Outlook live or Outlook 365.
	 *
	 * @return string   The full URL.
	 */
	public function generate_outlook_full_url( $calendar ) {
		$params   = $this->generate_outlook_add_url();
		$base_url = 'https://outlook.' . $calendar .'.com/calendar/0/deeplink/compose/';
		$url      = add_query_arg( $params, $base_url );

		return $url;
	}

	/**
	 * Changing spaces to %20 and encoding.
	 * urlencode() changes the spaces to +. That is also how Outlook will show it.
	 * So we're replacing it temporarily and then changing them to %20 which will work.
	 *
	 * @param $string
	 *
	 * @return array|string|string[]
	 */
	public function space_replace_and_encode( $string ) {
		$string = str_replace( ' ', 'TEC_OUTLOOK_SPACE', $string );
		$string = urlencode( $string );
		$string = str_replace( 'TEC_OUTLOOK_SPACE', '%20', $string );

		return $string;
	}

	/**
	 * Generate the markup of the export buttons for Classic Editor
	 *
	 * @param $calendar_links
	 *
	 * @return string The full markup of the export buttons.
	 */
	public function generate_outlook_markup( $calendar_links ) {
		$params = $this->generate_outlook_add_url();

		// Outlook Live URL
		$outlook_live_base_url = 'https://outlook.live.com/calendar/0/deeplink/compose/';
		$outlook_live_url = add_query_arg( $params, $outlook_live_base_url );

		// Outlook 365 URL
		$outlook_365_base_url = 'https://outlook.office.com/calendar/0/deeplink/compose/';
		$outlook_365_url = add_query_arg( $params, $outlook_365_base_url );

		// Button markups
		$outlook_live_button = sprintf(
			'<a target="_blank" class="tribe-events-gcal tribe-events-outlook-live tribe-events-button" title="' . esc_attr__( 'Add to Outlook Live Calendar', 'tec-labs-outlook-export-buttons' ) . '" href="%1$s">%2$s</a>',
			$outlook_live_url,
			esc_html( '+ Outlook Live', 'tec-labs-outlook-export-buttons' )
		);
		$outlook_365_button  = sprintf(
			'<a target="_blank" class="tribe-events-gcal tribe-events-outlook-365 tribe-events-button" title="' . esc_attr__( 'Add to Outlook 365 Calendar', 'tec-labs-outlook-export-buttons' ) . '" href="%1$s">%2$s</a>',
			$outlook_365_url,
			esc_html( '+ Outlook 365', 'tec-labs-outlook-export-buttons' )
		);

		// Get the position of the opening div
		$opening_div_end = strpos( $calendar_links, '>' ) + 1;

		// Inject the Outlook export buttons at the beginning.
		$new_calendar_links =
			substr( $calendar_links, 0, $opening_div_end )
			. $outlook_live_button
			. $outlook_365_button
			. substr( $calendar_links, $opening_div_end );

		return $new_calendar_links;
	}

	/**
	 * Setting up the template location in the extension.
	 *
	 * @param                  $folders
	 * @param \Tribe__Template $template
	 *
	 * @return mixed
	 */
	public function alternative_template_locations( $folders, \Tribe__Template $template ) {
		// Which file namespace your plugin will use.
		$plugin_name = 'tec-labs-outlook-export-buttons';

		/**
		 * Which order we should load your plugin files at. Plugin in which the file was loaded from = 20.
		 * Events Pro = 25. Tickets = 17
		 */
		$priority = 5;

		// Which folder in your plugin the customizations will be loaded from.
		$custom_folder[] = 'src/views';

		// Builds the correct file path to look for.
		$plugin_path = array_merge(
			(array) trailingslashit( plugin_dir_path( TRIBE_EXTENSION_OUTLOOK_EXPORT_BUTTONS_FILE ) ),
			(array) $custom_folder,
			array_diff( $template->get_template_folder(), [ 'src', 'views' ] )
		);

		/*
		 * Custom loading location for overwriting file loading.
		 */
		$folders[ $plugin_name ] = [
			'id'        => $plugin_name,
			'namespace' => $plugin_name, // Only set this if you want to overwrite theme namespacing
			'priority'  => $priority,
			'path'      => $plugin_path,
		];

		return $folders;
	}

	/**
	 * Checks whether the plugin dependency manifest is satisfied or not.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether the plugin dependency manifest is satisfied or not.
	 */
	protected function check_plugin_dependencies() {
		$this->register_plugin_dependencies();

		return tribe_check_plugin( static::class );
	}

	/**
	 * Registers the plugin and dependency manifest among those managed by Tribe Common.
	 *
	 * @since 1.0.0
	 */
	protected function register_plugin_dependencies() {
		$plugin_register = new Plugin_Register();
		$plugin_register->register_plugin();

		$this->container->singleton( Plugin_Register::class, $plugin_register );
		$this->container->singleton( 'extension.outlook_export_buttons', $plugin_register );
	}
}
