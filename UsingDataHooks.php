<?php

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Revision\RevisionRecord;

class UsingDataHooks {
	public static $instance = null;

	private $dataFrames = [];

	private $searchingForData = false;

	private static $phTitle = null;

	public static function onParserFirstCallInit( Parser &$parser ) {
		if ( self::$instance === null ) {
			self::$instance = new self;
		}
		$parser->setFunctionHook( 'using', [ self::$instance, 'usingParserFunction' ], SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'usingarg', [ self::$instance, 'usingArgParserFunction' ], SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'data', [ self::$instance, 'dataParserFunction' ], SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'ancestorname', [ __CLASS__, 'ancestorNameFunction' ], SFH_OBJECT_ARGS | SFH_NO_HASH );
		$parser->setHook( 'using', [ self::$instance, 'usingTag' ] );

		return true;
	}

	/**
	 * Tells MediaWiki that one or more magic word IDs should be treated as variables.
	 *
	 * @return void
	 */
	public static function onGetMagicVariableIDs( &$magicWords ) {
		$magicWords[] = 'parentname';
		$magicWords[] = 'selfname';
	}

	/* Returns a UsingData frame for a given page
	 */
	private function getDataFrame( $sourcePage, $title, &$parser, $frame ) {
		global $wgHooks;
		if ( !isset( $this->dataFrames[$sourcePage] ) ) {
			$this->dataFrames[$sourcePage] = new UsingDataPPFrameDOM( $frame, $sourcePage );
			if ( $sourcePage != ''
				 && ( $sourcePage != $parser->getTitle()->getPrefixedText() )
				 || $parser->getOptions()->getIsSectionPreview() ) {
				[ $text, $fTitle ] = $parser->fetchTemplateAndTitle( $title );
				if ( is_object( $fTitle ) && $fTitle->getPrefixedText() != $sourcePage ) {
					$this->dataFrames[$fTitle->getPrefixedText()] = $this->dataFrames[$sourcePage];
				}
				if ( is_string( $text ) && $text != '' ) {
					$this->searchingForData = true;
					$clearStateHooks = $wgHooks['ParserClearState'];
					// Other extensions tend to assume the hook is only called by wgParser and reset internal state
					$wgHooks['ParserClearState'] = [];
					$subParser = clone $parser;
					$subParser->preprocess( $text, $fTitle, clone $parser->getOptions() );
					// We might've blocked access to templates while preprocessing; should not be cached
					$subParser->clearState();
					$subParser->getOutput()->setText( $parser->getOutput()->getText() );
					$wgHooks['ParserClearState'] = empty( $wgHooks['ParserClearState'] )
						? $clearStateHooks
						: array_merge( $clearStateHooks, $wgHooks['ParserClearState'] );
					$parser->mPPNodeCount += $subParser->mPPNodeCount;
					$this->searchingForData = false;
				}
			}
		}
		return $this->dataFrames[$sourcePage];
	}

	/* Returns the page title of the $depth ancestor of $frame; empty string if invalid */
	private static function ancestorNameHandler( $frame, $depth ) {
		while ( $depth-- && $frame != null ) {
			$frame = $frame->parent ?? null;
		}
		return is_object( $frame ) && isset( $frame->title ) && is_object( $frame->title )
		 ? wfEscapeWikiText( $frame->title->getPrefixedText() ) : '';
	}

	/* Handles {{ANCESTORNAME:depth}} */
	public static function ancestorNameFunction( &$parser, $frame, $args ) {
		$arg = $frame->expand( $args[0] );
		return [ self::ancestorNameHandler( $frame, max( 0, is_numeric( $arg ) ? intval( $arg ) : 1 ) ), 'noparse' => true ];
	}

	/* Handles {{PARENTNAME}}, {{SELFNAME}}, {{ANCESTORNAME}} */
	public static function ancestorNameVar( &$parser, &$varCache, &$index, &$ret, &$frame ) {
		if ( $index == 'parentname' ) {
			$ret = self::ancestorNameHandler( $frame, 1 );
		}
		if ( $index == 'selfname' ) {
			$ret = self::ancestorNameHandler( $frame, 0 );
		}
		return true;
	}

	/* Parses common elements of #using syntax.
	 */
	private function usingParse( &$parser, $frame, $args ) {
		if ( $this->searchingForData ) {
			return '';
		}

		$titleArg = trim( $frame->expand( $args[0] ) );
		if ( strpos( $titleArg, '%' ) !== false ) {
			$titleArg = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $titleArg ) );
		}
		$title = \Title::newFromText( $titleArg, NS_MAIN );
		$sourcePage = is_object( $title ) ? $title->getPrefixedText() : '';
		$sourceHash = is_object( $title ) ? $title->getFragment() : '';
		$namedArgs = [];

		$one = null;
		$two = null;
		foreach ( $args as $key => $val ) {
			if ( $key === 0 ) {
				continue;
			}
			$bits = $val->splitArg();
			// It looks like indexes are now integers, it is a change from legacy implementation
			if ( $bits['index'] === '1' ) {
				$one = $frame->expand( $bits['value'] );
			} elseif ( $bits['index'] === '2' ) {
				$two = $bits['value'];
			} elseif ( $bits['index'] === '' ) {
				$namedArgs[trim( $frame->expand( $bits['name'] ) )] = $bits['value'];
			}
		}
		return [ $this->getDataFrame( $sourcePage, $title, $parser, $frame ), $sourceHash, $namedArgs, $one, $two ];
	}

	/* {{#using:Page#Hash|Template|Default|...}} parses Template using #data from Page's Hash fragment; or Default
	 * if no data from Page can be found. Named arguments override those in the #data tag.
	 */
	public function usingParserFunction( &$parser, $frame, $args ) {
		$parse = $this->usingParse( $parser, $frame, $args );
		if ( !is_array( $parse ) ) {
			return '';
		}

		[ $dframe, $fragment, $namedArgs, $templateTitle, $defaultValue ] = $parse;
		if ( !$dframe->hasFragment( $fragment ) && $defaultValue !== null ) {
			return $frame->expand( $defaultValue );
		}
		[ $dom, $title ] = $this->fetchTemplate( $parser, $templateTitle );
		return $dframe->expandUsing( $frame, $title, $dom, $namedArgs, $fragment );
	}

	/* {{#usingarg:Page#Hash|Arg|Default}} returns the value of Arg data field on Page's Hash fragment, Default if undefined.
	 */
	public function usingArgParserFunction( &$parser, $frame, $args ) {
		$parse = $this->usingParse( $parser, $frame, $args );
		if ( !is_array( $parse ) ) {
			return '';
		}

		[ $dframe, $fragment, $namedArgs, $argName, $defaultValue ] = $parse;
		$ret = $dframe->getArgumentForParser(
			$parser,
			UsingDataPPFrameDOM::normalizeFragment( $fragment ),
			$argName,
			$defaultValue === null ? '' : false
		);
		return $ret !== false ? $ret : $frame->expand( $defaultValue );
	}

	/* <using page="Page#Hash" default="Default">...</using>
	 * expands ... using the data from Page's Hash fragment; Default if undefined.
	 * This tag relies on $parser->replaceVariables($text, $frame), which may prove fragile across MW versions.
	 * Should it break, $parser->recursiveTagParse($text, $frame), in combination with either modifying the markerType, or using
	 * insertStripItem directly, is a viable short-term alternative -- but one that call certain hooks prematurely,
	 * potentially causing other extensions to misbehave slightly.
	 */
	public function usingTag( $text, array $args, Parser $parser, PPFrame $frame ): string {
		if ( $this->searchingForData ) {
			return '';
		}

		$source = isset( $args['page'] ) ? $parser->replaceVariables( $args['page'], $frame ) : '';
		unset( $args['page'] );
		if ( strpos( $source, '%' ) !== false ) {
			$source = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $source ) );
		}
		$title = \Title::newFromText( $source, NS_MAIN );
		if ( is_object( $title ) ) {
			$dframe = $this->getDataFrame( $title->getPrefixedText(), $title, $parser, $frame );
			if ( is_object( $dframe ) && $dframe->hasFragment( $title->getFragment() ) ) {
				$ovr = [];
				unset( $args['default'] );
				foreach ( $args as $key => $val ) {
					$ovr[$key] = $parser->replaceVariables( $val, $frame );
				}
				return $dframe->expandUsing( $frame, $frame->title, $text, $ovr, $title->getFragment(), true );
			}
		}
		return isset( $args['default'] ) ? $parser->replaceVariables( $args['default'], $frame ) : '';
	}

	/* {{#data:Template#Hash|...}} specifies data-transcludable arguments for the page; may not be transcluded. */
	public function dataParserFunction( Parser &$parser, PPFrame $frame, $args ) {
		$templateTitle = trim( $frame->expand( $args[0] ) );
		$hostPage = $frame->title->getPrefixedText();
		unset( $args[0] );
		$fragment = '';

		if ( strpos( $templateTitle, '%' ) !== false ) {
			$templateTitle = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $templateTitle ) );
		}

		$templateTitleObj = \Title::newFromText( $templateTitle, NS_TEMPLATE );
		if ( is_object( $templateTitleObj ) ) {
			$fragment = $templateTitleObj->getFragment();
		} elseif ( $templateTitle != '' && $templateTitle[0] == '#' ) {
			$fragment = substr( $templateTitle, 1 );
		}

		if ( $frame->depth == 0 || $this->searchingForData ) {
			if ( !isset( $this->dataFrames[$hostPage] ) ) {
				$this->dataFrames[$hostPage] = new UsingDataPPFrameDOM( $frame, $hostPage );
			}
			$df =& $this->dataFrames[$hostPage];
			$df->addArgs( $frame, $args, $fragment );
			if ( $this->searchingForData ) {
				return '';
			}
		}
		if ( !is_object( $templateTitleObj ) ) {
			return '';
		}

		[ $dom, $tTitle ] = $this->fetchTemplate( $parser, $templateTitleObj );
		foreach ( $args as $k => $v ) {
			// Line below breaks #data processing, but it exists in old implementation
			 $args[$k] = $v->node;
		}
		$cframe = $frame->newChild( $args, $tTitle );
		$nargs =& $cframe->namedArgs;
		$nargs['data-found'] = $frame->depth == 0 ? '3' : '2';
		$nargs['data-source'] = $hostPage;
		$nargs['data-sourcee'] = wfEscapeWikiText( $hostPage );
		$nargs['data-fragment'] = $fragment;
		$nargs['data-source-fragment'] = $hostPage . ( empty( $fragment ) ? '' : ( '#' . $fragment ) );
		return $cframe->expand( $dom );
	}

	/* Returns template text for transclusion.
	 */
	private function fetchTemplate( $parser, $template ) {
		global $wgNonincludableNamespaces;

		if ( $template == '' || ( !is_string( $template ) && !is_object( $template ) ) ) {
			return '';
		}

		$title = is_object( $template ) ? $template : \Title::newFromText( $template, NS_TEMPLATE );
		if ( !is_object( $title )
			 || $title->getNamespace() == NS_SPECIAL
			 || ( $wgNonincludableNamespaces && in_array( $title->getNamespace(), $wgNonincludableNamespaces ) ) ) {
			return is_object( $title )
				? [ '[[:' . $title->getPrefixedText() . ']]', $title ]
				: [ '[[:' . $template . ']]', null ];
		}
		[ $dom, $title ] = $parser->getTemplateDom( $title );
		return [ $dom ? $dom : ( '[[:' . $title->getPrefixedText() . ']]' ), $title ];
	}

	public static function onBeforeParserFetchTemplateAndtitle( ?LinkTarget $contextTitle, LinkTarget $title,
																	  bool &$skip, ?RevisionRecord &$revRecord ): bool {
		if ( !self::$instance->searchingForData ) {
			return true;
		}
		if ( self::$phTitle === null ) {
			self::$phTitle = \Title::newFromText( 'UsingDataPlaceholderTitle', NS_MEDIAWIKI );
		}

		$title = self::$phTitle;
		$skip = true;

		return false;
	}
}
