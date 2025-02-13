<?php

namespace Fandom\UsingData;

use InvalidArgumentException;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\PPFrame_Hash;
use MediaWiki\Parser\PPNode;
use MediaWiki\Title\Title;

class UsingDataPPFrameDOM extends PPFrame_Hash {
	public PPFrame_Hash $parent; // Parent frame (from #using or #data, provides a parser if needed), data source title
	public string $sourcePage;
	public array $knownFragments = []; // Specifies which fragments have been declared
	/** @var array{0:PPFrame,1:array} */
	public array $pendingArgs = []; // Pending argument lists
	public array $serializedArgs = []; // Serialized wikitext cache for generic parsers
	public array $expandedArgs = [];
	public ?Parser $expansionParser = null; // Expanded wikitext cache for a specific parser
	public array $overrideArgs = []; // Frame and arguments overrides provided by calls to #using
	public ?PPFrame $overrideFrame = null;
	public string $expansionFragment = ''; // Current expansion fragment; pure and normalized (prefix) form
	public string $expansionNormalizedFragment = '##';

	public function __construct( PPFrame $inner, string $pageName ) {
		if ( !$inner instanceof PPFrame_Hash ) {
			throw new InvalidArgumentException( __CLASS__ . ' expects an instance of PPFrame_Hash' );
		}
		parent::__construct( $inner->preprocessor );
		$this->parent = $inner;
		$this->depth = $inner->depth + 1;
		$this->title = $inner->title;
		$this->sourcePage = $pageName;
	}

	public static function normalizeFragment( string $fragment ): string {
		return str_replace( '#', '# ', strtolower( $fragment ) ) . '##';
	}

	public function addArgs( PPFrame $frame, array $args, string $fragment ): void {
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

	public function expandOn(
		PPFrame $frame, ?Title $templateTitle, PPNode|string|null $text,
		array $moreArgs, string $fragment, bool $useRTP = false
	): string {
		if ( !$frame instanceof PPFrame_Hash ) {
			throw new InvalidArgumentException( __CLASS__ . ' expects an instance of PPFrame_Hash' );
		}
		$oldParser = $this->expansionParser;
		$oldArgs = $this->overrideArgs;
		$oldFrame = $this->overrideFrame;
		$oldFragment = $this->expansionFragment;
		$oldTitle = $this->title;
		$oldExpanded = $this->expandedArgs;

		$this->expansionParser = $frame->parser;
		$this->overrideArgs = $moreArgs;
		$this->overrideFrame = $frame;
		$this->expansionFragment = $fragment;
		$this->expansionNormalizedFragment = self::normalizeFragment( $this->expansionFragment );
		$this->title = is_object( $templateTitle ) ? $templateTitle : $frame->title;
		if ( $oldParser != null && $oldParser !== $frame->parser && !empty( $this->expandedArgs ) ) {
			$this->expandedArgs = [];
		}

		$ret = is_string( $text ) && $useRTP ?
			$this->expansionParser->replaceVariables( $text, $this ) :
			$this->expand( $text === null ? '' : $text );

		$this->overrideArgs = $oldArgs;
		$this->overrideFrame = $oldFrame;
		$this->expansionFragment = $oldFragment;
		$this->expansionNormalizedFragment = self::normalizeFragment( $this->expansionFragment );
		$this->title = $oldTitle;
		if ( $oldParser != null ) {
			$this->expansionParser = $oldParser;
			$this->expandedArgs = $oldExpanded;
		}

		return $ret;
	}

	public function hasFragment( string $fragment ): bool {
		return isset( $this->knownFragments[self::normalizeFragment( $fragment )] );
	}

	public function isEmpty(): bool {
		return !isset( $this->knownFragments[$this->expansionNormalizedFragment] );
	}

	public function getArgumentForParser(
		Parser $parser, string $normalizedFragment, ?string $arg, string|false $default = false
	): string|false {
		$arg = $normalizedFragment . $arg;
		if ( isset( $this->expandedArgs[$arg] ) && $this->expansionParser === $parser ) {
			return $this->expandedArgs[$arg];
		}
		if ( !isset( $this->serializedArgs[$arg] ) ) {
			if ( !$this->pendingArgs ) {
				return $default;
			}
			foreach ( $this->pendingArgs as &$aar ) {
				if ( isset( $aar[1][$arg] ) ) {
					$text = $aar[1][$arg];
					unset( $aar[1][$arg] );
					/** @var PPFrame $frame */
					$frame = $aar[0];
					$this->serializedArgs[$arg] = $frame->expand( $text );
					break;
				}
			}
		}

		if ( !isset( $this->serializedArgs[$arg] ) ) {
			return $default;
		}

		$ret = trim( $this->serializedArgs[$arg] );
		if ( $parser === $this->expansionParser ) {
			$this->expandedArgs[$arg] = $ret;
		}
		return $ret;
	}

	/**
	 * @suppress PhanTypeMismatchReturn
	 */
	public function getArgument( $name ): string|false {
		switch ( $name ) {
			case 'data-found':
				return $this->isEmpty() ? '' : '1';
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
				if ( isset( $this->overrideArgs[$name] ) ) {
					if ( is_object( $this->overrideArgs[$name] ) ) {
						$this->overrideArgs[$name] = $this->overrideFrame->expand(
							$this->overrideArgs[$name] );
					}
					return $this->overrideArgs[$name];
				}
				$parser = $this->expansionParser ?? $this->parent->parser;
				return $this->getArgumentForParser( $parser, $this->expansionNormalizedFragment, $name );
		}
	}
}
