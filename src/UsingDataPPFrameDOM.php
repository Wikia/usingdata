<?php

class UsingDataPPFrameDOM extends PPFrame_Hash {
	public $parent; // Parent frame (either from #using or #data, providing a parser if needed), data source title
	public $sourcePage;
	public $knownFragments = []; // Specifies which fragments have been declared
	public $pendingArgs = null; // Pending argument lists
	public $serializedArgs = []; // Serialized wikitext cache for generic parsers
	public $expandedArgs = [];
	public $expansionForParser = null; // Expanded wikitext cache for a specific parser
	public $overrideArgs = null; // Argument list and frame for expanding additional argument passed through #using
	public $overrideFrame = null;
	public $expansionFragment = ''; // Current expansion fragment; pure and normalized (prefix) form
	public $expansionFragmentN = '##';

	public function __construct( PPFrame $inner, $pageName ) {
		parent::__construct( $inner->preprocessor );
		$this->args = [];
		$this->parent = $inner;
		$this->depth = $inner->depth + 1;
		$this->title = $inner->title;
		$this->sourcePage = $pageName;
	}

	public static function normalizeFragment( $fragment ) {
		return str_replace( '#', '# ', strtolower( $fragment ) ) . '##';
	}

	public function addArgs( $frame, $args, $fragment ) {
		if ( $this->pendingArgs === null ) {
			$this->pendingArgs = [];
		}

		$namedArgs = [];
		$prefix = self::normalizeFragment( $fragment );
		foreach ( $args as $k => $arg ) {
			if ( $k == 0 ) {
				continue;
			}
			$arg = $arg->splitArg();
			if ( $arg['index'] === '' ) {
				$namedArgs[$prefix . trim( $frame->expand( $arg['name'] ) )] = $arg['value'];
			}
		}

		$this->pendingArgs[] = [ $frame, $namedArgs ];
		$this->knownFragments[$prefix] = true;
	}

	public function expandUsing( PPFrame $frame, $templateTitle, $text, $moreArgs, $fragment, $useRTP = false ) {
		$oldParser = $this->expansionForParser;
		$oldExpanded = $this->expandedArgs;
		$oldArgs = $this->overrideArgs;
		$oldFrame = $this->overrideFrame;
		$oldFragment = $this->expansionFragment;
		$oldTitle =& $this->title;

		$this->expansionForParser = $frame->parser;
		$this->expansionFragment = $fragment;
		$this->overrideArgs = $moreArgs;
		$this->overrideFrame = $frame;
		$this->expansionFragmentN = self::normalizeFragment( $this->expansionFragment );
		$this->title = is_object( $templateTitle ) ? $templateTitle : $frame->title;
		if ( $oldParser != null && $oldParser !== $frame->parser && !empty( $this->expandedArgs ) ) {
			$this->expandedArgs = [];
		}

		$ret = is_string( $text ) && $useRTP ?
			$this->expansionForParser->replaceVariables( $text, $this ) :
			$this->expand( $text === null ? '' : $text );

		$this->overrideArgs = $oldArgs;
		$this->expansionFragment = $oldFragment;
		$this->overrideFrame = $oldFrame;
		$this->expansionFragmentN = self::normalizeFragment( $this->expansionFragment );
		$this->title =& $oldTitle;
		if ( $oldParser != null ) {
			$this->expansionForParser = $oldParser;
			$this->expandedArgs = $oldExpanded;
		}

		return $ret;
	}

	public function hasFragment( $fragment ) {
		return isset( $this->knownFragments[self::normalizeFragment( $fragment )] );
	}

	public function isEmpty() {
		return !isset( $this->knownFragments[$this->expansionFragmentN] );
	}

	public function getArgumentForParser( $parser, $normalizedFragment, $arg, $default = false ) {
		$arg = $normalizedFragment . strval( $arg );
		if ( isset( $this->expandedArgs[$arg] ) && $this->expansionForParser === $parser ) {
			return $this->expandedArgs[$arg];
		}
		if ( !isset( $this->serializedArgs[$arg] ) ) {
			if ( $this->pendingArgs === null ) {
				return $default;
			}
			foreach ( $this->pendingArgs as &$aar ) {
				if ( isset( $aar[1][$arg] ) ) {
					$text = $aar[1][$arg];
					unset( $aar[1][$arg] );
					$text = $aar[0]->expand( $text );
					if ( str_contains( $text, Parser::MARKER_PREFIX ) ) {
						$text = $aar[0]->parser->serialiseHalfParsedText( ' ' . $text ); // MW bug 26731
					}
					$this->serializedArgs[$arg] = $text;
					break;
				}
			}
		}

		if ( !isset( $this->serializedArgs[$arg] ) ) {
			return $default;
		}

		$ret = $this->serializedArgs[$arg];
		$ret = trim( is_array( $ret ) ? $parser->unserialiseHalfParsedText( $ret ) : $ret );
		if ( $parser === $this->expansionForParser ) {
			$this->expandedArgs[$arg] = $ret;
		}
		return $ret;
	}

	public function getArgument( $index ) {
		switch ( $index ) {
			case 'data-found':
				return $this->isEmpty() ? null : '1';
			case 'data-source':
				return $this->sourcePage;
			case 'data-sourcee':
				return wfEscapeWikiText( $this->sourcePage );
			case 'data-fragment':
				return $this->expansionFragment;
			case 'data-source-fragment':
				return $this->sourcePage . (
					empty( $this->expansionFragment ) ?
						'' :
						( '#' . $this->expansionFragment )
					);
			default:
				if ( is_array( $this->overrideArgs ) && isset( $this->overrideArgs[$index] ) ) {
					if ( is_object( $this->overrideArgs[$index] ) ) {
						$this->overrideArgs[$index] = $this->overrideFrame->expand( $this->overrideArgs[$index] );
					}
					return $this->overrideArgs[$index];
				}
				$p = $this->expansionForParser === null ? $this->parent->parser : $this->expansionForParser;
				return $this->getArgumentForParser( $p, $this->expansionFragmentN, $index, false );
		}
	}
}
