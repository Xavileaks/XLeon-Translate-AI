<?php
defined( 'ABSPATH' ) || exit;

final class XLT_HTML {
    private DOMDocument $document;
    private array $segments = array();
    private array $raw = array();

    public function __construct( string $html ) {
        // libxml can entity-encode non-ASCII text in script/style when serializing.
        // Keep executable contents byte-for-byte, including JSON and CSS strings.
        $prefix = 'XLT_RAW_' . bin2hex( random_bytes( 12 ) ) . '_';
        $html = preg_replace_callback( '~(<(script|style)\b(?:[^>\x22\x27]|\x22[^\x22]*\x22|\x27[^\x27]*\x27)*>)(.*?)(</\2\s*>)~is', function ( $match ) use ( $prefix ) {
            $token = $prefix . count( $this->raw );
            $this->raw[ $token ] = $match[3];
            return $match[1] . $token . $match[4];
        }, $html );
        $this->document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        try {
            $this->document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
        foreach ( iterator_to_array( $this->document->childNodes ) as $child ) {
            if ( XML_PI_NODE === $child->nodeType ) {
                $this->document->removeChild( $child );
            }
        }
        $xpath = new DOMXPath( $this->document );
        foreach ( $xpath->query( '//text() | //@alt | //@title | //@placeholder | //@aria-label | //input[@type="submit" or @type="button" or @type="reset"]/@value | //meta[@name="description"]/@content | //meta[@property="og:title" or @property="og:description"]/@content' ) as $node ) {
            if ( ! $this->excluded( $node ) ) {
                $text = trim( $node->nodeValue );
                if ( $this->translatable( $text ) ) {
                    $this->segments[] = array( $node, $text );
                }
            }
        }
    }

    public static function translatable( string $text ): bool {
        return strlen( $text ) <= 3500 && ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text ) && (bool) preg_match( '/\p{L}/u', $text )
            && ! preg_match( '~^(https?://|www\.|[^\s@]+@[^\s@]+\.[^\s@]+$)~iu', $text );
    }

    private function excluded( DOMNode $node ): bool {
        $parent = $node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
        while ( $parent instanceof DOMElement ) {
            if ( in_array( strtolower( $parent->tagName ), array( 'script', 'style', 'noscript', 'code', 'pre', 'textarea', 'svg', 'math', 'template' ), true )
                || 'no' === strtolower( $parent->getAttribute( 'translate' ) )
                || $parent->hasAttribute( 'data-xlt-ignore' )
                || 'wpadminbar' === $parent->getAttribute( 'id' )
                || preg_match( '/(?:^|\s)(notranslate|xlt-switcher)(?:\s|$)/', $parent->getAttribute( 'class' ) ) ) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    public function strings(): array {
        return array_values( array_unique( array_column( $this->segments, 1 ) ) );
    }

    public function render( array $dictionary, string $target ): string {
        foreach ( $this->segments as list( $node, $text ) ) {
            if ( isset( $dictionary[ $text ] ) ) {
                // DOMText::nodeValue inserts plain text; HTML from a model can never execute.
                $replacement = preg_replace_callback( '/^(\s*)(.*?)(\s*)$/su', static fn( $m ) => $m[1] . $dictionary[ $text ] . $m[3], $node->nodeValue );
                if ( $node instanceof DOMAttr ) {
                    $node->ownerElement->setAttribute( $node->name, $replacement );
                } else {
                    $node->nodeValue = $replacement;
                }
            }
        }
        $html = $this->document->getElementsByTagName( 'html' )->item( 0 );
        if ( $html ) {
            $html->setAttribute( 'lang', $target );
            $html->setAttribute( 'dir', 'ar' === $target ? 'rtl' : 'ltr' );
        }
        foreach ( $this->document->getElementsByTagName( 'a' ) as $link ) {
            if ( ! $this->excluded( $link ) && $link->hasAttribute( 'href' ) ) {
                $link->setAttribute( 'href', XLT_Frontend::link( $link->getAttribute( 'href' ), $target ) );
            }
        }
        // Query-based language variants in v1 are for visitors, not an SEO URL migration.
        $xpath = new DOMXPath( $this->document );
        foreach ( $xpath->query( '//meta[@name="robots"]' ) as $meta ) {
            $meta->parentNode->removeChild( $meta );
        }
        $head = $this->document->getElementsByTagName( 'head' )->item( 0 );
        if ( $head ) {
            $meta = $this->document->createElement( 'meta' );
            $meta->setAttribute( 'name', 'robots' );
            $meta->setAttribute( 'content', 'noindex,follow' );
            $head->appendChild( $meta );
        }
        return strtr( $this->document->saveHTML(), $this->raw );
    }
}
