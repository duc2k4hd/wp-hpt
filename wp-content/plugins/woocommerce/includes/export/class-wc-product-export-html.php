<?php
/**
 * Clean product-description fragments before CSV serialization.
 *
 * @package WooCommerce\Export
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM defines camelCase property names.

/** Repairs HTML and serializes balanced, single-line description cells. */
final class WC_Product_Export_HTML {
	/** Check dependencies before accepting a potentially long export job. */
	public static function assert_supported() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			throw new RuntimeException( 'Product HTML cleaning requires the PHP DOM extension.' );
		}
	}

	/**
	 * Clean markup, preserving attributes only on links/images, except class.
	 *
	 * @param string   $html  Raw description fragment.
	 * @param int|null $limit Optional Excel capacity in UTF-16 code units.
	 * @return string
	 */
	public static function clean( $html, $limit = null ) {
		self::assert_supported();
		if ( '' === $html ) {
			return '';
		}
		WC_Product_Export_Resources::check_memory( strlen( $html ) * 8 );
		if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
			$html = mb_convert_encoding( $html, 'UTF-8', 'UTF-8' );
		}
		$html = self::single_line( $html );
		$dom  = new DOMDocument( '1.0', 'UTF-8' );
		// No entity expansion, external DTD loading, or network requests. Keep the
		// global libxml error buffer untouched; malformed fragments are expected.
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"><!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		$body   = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $loaded || ! $body ) {
			throw new RuntimeException( 'The product description HTML could not be repaired safely.' );
		}
		$state = array(
			'parts'     => array(),
			'remaining' => null === $limit ? PHP_INT_MAX : $limit,
			'truncated' => false,
			'nodes'     => 0,
		);
		foreach ( $body->childNodes as $child ) {
			self::emit( $child, $state, array(), 0 );
			if ( $state['truncated'] ) {
				break;
			}
		}
		return trim( implode( '', $state['parts'] ) );
	}

	/** Remove physical record separators without removing words. */
	public static function single_line( $text ) {
		return str_replace( array( "\r\n", "\r", "\n", "\t", "\u{0085}", "\u{2028}", "\u{2029}" ), ' ', $text );
	}

	/** Emit repaired DOM nodes, reserving capacity for every closing tag. */
	private static function emit( $node, array &$state, array $ancestors, $depth ) {
		if ( $state['truncated'] ) {
			return;
		}
		if ( $depth > 128 ) {
			throw new RuntimeException( 'The product description HTML is nested too deeply to export safely.' );
		}
		if ( 0 === ++$state['nodes'] % 256 ) {
			WC_Product_Export_Resources::check_memory();
		}
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			self::text( preg_replace( '/[ \t\r\n\f\v]+/u', ' ', $node->nodeValue ), $state );
			return;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return; // Comments and processing instructions are not product content.
		}
		$tag = strtolower( $node->nodeName );
		if ( in_array( $tag, array( 'script', 'style', 'meta', 'link', 'base' ), true ) ) {
			return;
		}
		if ( 'br' === $tag ) {
			self::text( ' ', $state );
			return;
		}
		// Presentation wrappers carry no attributes after cleaning. Unwrap them,
		// and duplicate formatting ancestors, while retaining their contents.
		$unwrap = in_array( $tag, array( 'div', 'span', 'font' ), true ) ||
			( in_array( $tag, array( 'b', 'strong', 'i', 'em', 'u' ), true ) && in_array( $tag, $ancestors, true ) );
		if ( $unwrap ) {
			if ( 'div' === $tag ) {
				self::text( ' ', $state );
			}
			foreach ( $node->childNodes as $child ) {
				self::emit( $child, $state, $ancestors, $depth + 1 );
			}
			if ( 'div' === $tag ) {
				self::text( ' ', $state );
			}
			return;
		}
		$open = '<' . $tag;
		if ( 'a' === $tag || 'img' === $tag ) {
			foreach ( $node->attributes as $attribute ) {
				if ( 'class' !== strtolower( $attribute->name ) ) {
					$open .= ' ' . $attribute->name . '="' . htmlspecialchars( self::single_line( $attribute->value ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . '"';
				}
			}
		}
		$open .= '>';
		$void  = in_array( $tag, array( 'area', 'col', 'embed', 'hr', 'img', 'input', 'param', 'source', 'track', 'wbr' ), true );
		$close = $void ? '' : '</' . $tag . '>';
		$cost  = self::units( $open . $close );
		if ( $cost > $state['remaining'] ) {
			$state['truncated'] = true;
			return;
		}
		$state['remaining'] -= $cost;
		$state['parts'][]    = $open;
		if ( ! $void ) {
			$content_start = count( $state['parts'] );
			$ancestors[]   = $tag;
			foreach ( $node->childNodes as $child ) {
				self::emit( $child, $state, $ancestors, $depth + 1 );
			}
			if ( in_array( $tag, array( 'p', 'b', 'strong', 'i', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				$empty = true;
				for ( $index = $content_start; $index < count( $state['parts'] ); ++$index ) {
					if ( '' !== trim( $state['parts'][ $index ] ) ) {
						$empty = false;
						break;
					}
				}
				if ( $empty ) {
					$state['remaining'] += $cost;
					while ( count( $state['parts'] ) >= $content_start ) {
						$removed = array_pop( $state['parts'] );
						if ( count( $state['parts'] ) >= $content_start ) {
							$state['remaining'] += self::units( $removed );
						}
					}
					return;
				}
			}
			$state['parts'][] = $close;
		}
	}

	/** Escape text and cut only at character/entity boundaries. */
	private static function text( $text, array &$state ) {
		if ( $state['parts'] && ' ' === substr( $state['parts'][ count( $state['parts'] ) - 1 ], -1 ) ) {
			$text = ltrim( $text, ' ' );
		}
		if ( $state['truncated'] || '' === $text ) {
			return;
		}
		$escaped = htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
		$cost    = self::units( $escaped );
		if ( $cost > $state['remaining'] ) {
			$low  = 0;
			$high = mb_strlen( $text, 'UTF-8' );
			while ( $low < $high ) {
				$mid       = (int) ceil( ( $low + $high ) / 2 );
				$candidate = htmlspecialchars( mb_substr( $text, 0, $mid, 'UTF-8' ), ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
				if ( self::units( $candidate ) <= $state['remaining'] ) {
					$low = $mid;
				} else {
					$high = $mid - 1;
				}
			}
			$escaped            = htmlspecialchars( mb_substr( $text, 0, $low, 'UTF-8' ), ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
			$cost               = self::units( $escaped );
			$state['truncated'] = true;
		}
		$state['parts'][]    = $escaped;
		$state['remaining'] -= $cost;
	}

	/** Excel capacity counts UTF-16 code units, including escaped HTML markup. */
	private static function units( $text ) {
		return (int) ( strlen( mb_convert_encoding( $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
	}
}
