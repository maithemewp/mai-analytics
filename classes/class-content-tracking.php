<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

class Mai_Analytics_Content_Tracking {

	function __construct() {
		$this->hooks();
	}

	/**
	 * Runs frontend hooks.
	 *
	 * @since TBD
	 *
	 * @return void
	 */
	function hooks() {
		add_filter( 'maicca_content', [ $this, 'add_attributes' ], 10, 2 );
	}

	function add_attributes( $content, $args ) {
		if ( ! $content ) {
			return $content;
		}

		if ( ! isset( $args['id'] ) || empty( $args['id'] ) ) {
			return $content;
		}

		$dom      = maicca_get_dom_document( $content );
		$children = $dom->getElementsByTagName('body')->item(0)->childNodes;

		// Bail if no nodes.
		if ( ! $children->length ) {
			return $content;
		}

		if ( 1 === $children->length ) {
			// Get first element and set main attributes.
			$first = $children->item(0);
			$first->setAttribute( 'data-track-content', '' );
			$first->setAttribute( 'data-content-name', get_the_title( $args['id'] ) );
		} else {
			foreach ( $children as $node ) {
				// Skip if not an element we can add attributes to.
				if ( 'DOMElement' !== get_class( $node ) ) {
					continue;
				}

				// Set main attributes to all top level child elements.
				$node->setAttribute( 'data-track-content', '' );
				$node->setAttribute( 'data-content-name', get_the_title( $args['id'] ) );
			}
		}

		// Query elements.
		$xpath   = new DOMXPath( $dom );
		$actions = $xpath->query( '//a | //button | //input[@type="submit"]' );

		if ( $actions->length ) {
			foreach ( $actions as $node ) {
				$piece = 'input' === $node->tagName ? $node->getAttribute( 'value' ) : $node->textContent;

				if ( $piece ) {
					$node->setAttribute( 'data-content-piece', $piece );
				}

				// Disabled, because target should happen automatically via href in Matomo.
				// $target = 'a' === $node->tagName ? $node->getAttribute( 'href' ) : '';
				// if ( $target ) {
				// 	$node->setAttribute( 'data-content-target', $target );
				// }
			}
		}

		// Save new content.
		$content = $dom->saveHTML( $dom->documentElement );

		return $content;
	}
}
