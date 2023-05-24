<?php

namespace UsingData;

use Config;
use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\GetMagicVariableIDsHook;
use MediaWiki\Hook\ParserClearStateHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Revision\RevisionRecord;
use Parser;
use PPFrame;
use Title;

class UsingDataHooks implements
	ParserFirstCallInitHook,
	GetMagicVariableIDsHook,
	BeforeParserFetchTemplateRevisionRecordHook,
	ParserGetVariableValueSwitchHook,
	ParserClearStateHook
{
	public function __construct(
		private Config $config,
		private array $dataFrames = [],
		private bool $searchingForData = false
	) {
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'using', [ $this, 'usingParserFunction' ], SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'usingarg', [ $this, 'usingArgParserFunction' ], SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'data', [ $this, 'dataParserFunction' ], SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'ancestorname', [ $this, 'ancestorNameFunction' ], SFH_OBJECT_ARGS | SFH_NO_HASH );
		$parser->setHook( 'using', [ $this, 'usingTag' ] );
	}

	/** @inheritDoc */
	public function onGetMagicVariableIDs( &$variableIDs ) {
		$variableIDs[] = 'parentname';
		$variableIDs[] = 'selfname';
	}

	/** Returns a UsingData frame for a given page */
	private function getDataFrame( $sourcePage, ?Title $title, Parser &$parser, PPFrame $frame ): UsingDataPPFrameDOM {
		if ( isset( $this->dataFrames[$sourcePage] ) ) {
			return $this->dataFrames[$sourcePage];
		}

		$this->dataFrames[$sourcePage] = new UsingDataPPFrameDOM( $frame, $sourcePage );
		if (
			(
				$sourcePage === '' ||
				$sourcePage === Title::castFromPageReference( $parser->getPage() )
					->getPrefixedText()
			) && !$parser->getOptions()->getIsSectionPreview() ) {
			return $this->dataFrames[$sourcePage];
		}

		[ $text, $fTitle ] = $parser->fetchTemplateAndTitle( $title );
		if ( is_object( $fTitle ) && $fTitle->getPrefixedText() != $sourcePage ) {
			$this->dataFrames[$fTitle->getPrefixedText()] = $this->dataFrames[$sourcePage];
		}

		if ( is_string( $text ) && $text != '' ) {
			$this->searchingForData = true;

			$subParser = clone $parser;
			$subParser->preprocess( $text, $fTitle, clone $parser->getOptions() );

			$parser->mPPNodeCount += $subParser->mPPNodeCount;

			$this->searchingForData = false;

		}
		return $this->dataFrames[$sourcePage];
	}

	/**
	 * Reset handler state between parse() calls.
	 * @param Parser $parser
	 * @return void
	 */
	public function onParserClearState( $parser ): void {
		$this->dataFrames = [];
	}

	/** Returns the page title of the $depth ancestor of $frame; empty string if invalid */
	private function ancestorNameHandler( PPFrame $frame, int $depth ): string {
		while ( $depth-- && $frame !== null ) {
			$frame = $frame->parent ?? null;
		}
		return is_object( $frame?->title ) ? wfEscapeWikiText( $frame->title->getPrefixedText() ) : '';
	}

	/** Handles {{ANCESTORNAME:depth}} */
	public function ancestorNameFunction( Parser $parser, PPFrame $frame, array $args ) {
		$arg = $frame->expand( $args[0] );
		$depth = max( 0, is_numeric( $arg ) ? (int)$arg : 1 );
		return [ $this->ancestorNameHandler( $frame, $depth ), 'noparse' => true ];
	}

	/**
	 * @inheritDoc
	 * Handles {{PARENTNAME}}, {{SELFNAME}}, {{ANCESTORNAME}}
	 */
	public function onParserGetVariableValueSwitch( $parser, &$variableCache, $magicWordId, &$ret, $frame ) {
		if ( $magicWordId === 'parentname' ) {
			$ret = $this->ancestorNameHandler( $frame, 1 );
		}
		if ( $magicWordId === 'selfname' ) {
			$ret = $this->ancestorNameHandler( $frame, 0 );
		}
	}

	/** Parses common elements of #using syntax. */
	private function usingParse( Parser &$parser, PPFrame $frame, array $args ) {
		$title = Title::newFromText( $this->sanitizeTitleName( $frame->expand( $args[0] ) ) );
		$sourcePage = $title?->getPrefixedText() ?? '';
		$sourceHash = $title?->getFragment() ?? '';
		$namedArgs = [];

		$one = null;
		$two = null;
		foreach ( $args as $key => $val ) {
			if ( $key === 0 ) {
				continue;
			}
			$bits = $val->splitArg();
			// It looks like indexes are now integers, it is a change from legacy implementation
			if ( $bits['index'] === 1 ) {
				$one = $frame->expand( $bits['value'] );
			} elseif ( $bits['index'] === 2 ) {
				$two = $bits['value'];
			} elseif ( $bits['index'] === '' ) {
				$namedArgs[trim( $frame->expand( $bits['name'] ) )] = $bits['value'];
			}
		}
		return [ $this->getDataFrame( $sourcePage, $title, $parser, $frame ), $sourceHash, $namedArgs, $one, $two ];
	}

	/**
	 * {{#using:Page#Hash|Template|Default|...}} parses Template using #data from Page's Hash fragment; or Default
	 * if no data from Page can be found. Named arguments override those in the #data tag.
	 */
	public function usingParserFunction( Parser $parser, PPFrame $frame, array $args ) {
		if ( $this->searchingForData ) {
			return '';
		}

		/** @var UsingDataPPFrameDOM $dframe */
		[ $dframe, $fragment, $namedArgs, $templateTitle, $defaultValue ] = $this->usingParse( $parser, $frame, $args );

		if ( !$dframe->hasFragment( $fragment ) && $defaultValue !== null ) {
			return $frame->expand( $defaultValue );
		}
		[ $dom, $title ] = $this->fetchTemplate( $parser, $templateTitle );
		return $dframe->expandUsing( $frame, $title, $dom, $namedArgs, $fragment );
	}

	/**
	 * {{#usingarg:Page#Hash|Arg|Default}}
	 * returns the value of Arg data field on Page's Hash fragment, Default if undefined.
	 */
	public function usingArgParserFunction( Parser &$parser, PPFrame $frame, array $args ) {
		if ( $this->searchingForData ) {
			return '';
		}
		/** @var UsingDataPPFrameDOM $dframe */
		[ $dframe, $fragment, $namedArgs, $argName, $defaultValue ] = $this->usingParse( $parser, $frame, $args );

		$ret = $dframe->getArgumentForParser(
			$parser,
			UsingDataPPFrameDOM::normalizeFragment( $fragment ),
			$argName,
			$defaultValue === null ? '' : false
		);
		return $ret !== false ? $ret : $frame->expand( $defaultValue );
	}

	/**
	 * <using page="Page#Hash" default="Default">...</using>
	 * expands ... using the data from Page's Hash fragment; Default if undefined.
	 * This tag relies on $parser->replaceVariables($text, $frame), which may prove fragile across MW versions.
	 * Should it break, $parser->recursiveTagParse($text, $frame),
	 * in combination with either modifying the markerType, or using
	 * insertStripItem directly, is a viable short-term alternative -- but one that call certain hooks prematurely,
	 * potentially causing other extensions to misbehave slightly.
	 */
	public function usingTag( $text, array $args, Parser $parser, PPFrame $frame ): array {
		if ( $this->searchingForData ) {
			return [ '', 'markerType' => 'none' ];
		}

		$source = isset( $args['page'] ) ? $parser->replaceVariables( $args['page'], $frame ) : '';
		unset( $args['page'] );
		$title = Title::newFromText( $this->sanitizeTitleName( $source ) );
		if ( $title ) {
			$dframe = $this->getDataFrame( $title->getPrefixedText(), $title, $parser, $frame );
			if ( $dframe->hasFragment( $title->getFragment() ) ) {
				$ovr = [];
				unset( $args['default'] );
				foreach ( $args as $key => $val ) {
					$ovr[$key] = $parser->replaceVariables( $val, $frame );
				}
				return [
					$dframe->expandUsing( $frame, $frame->title, $text, $ovr, $title->getFragment(), true ),
					'markerType' => 'none'
				];
			}
		}

		return [
			isset( $args['default'] ) ? $parser->replaceVariables( $args['default'], $frame ) : '',
			'markerType' => 'none'
		];
	}

	/** {{#data:Template#Hash|...}} specifies data-transcludable arguments for the page; may not be transcluded. */
	public function dataParserFunction( Parser &$parser, PPFrame $frame, $args ): string {
		$templateTitle = $this->sanitizeTitleName( $frame->expand( $args[0] ) );
		$hostPage = $frame->title->getPrefixedText();
		unset( $args[0] );
		$fragment = '';

		$templateTitleObj = Title::newFromText( $templateTitle, NS_TEMPLATE );
		if ( $templateTitleObj ) {
			$fragment = $templateTitleObj->getFragment();
		} elseif ( str_starts_with( $templateTitle, '#' ) ) {
			$fragment = substr( $templateTitle, 1 );
		}

		if ( $frame->depth == 0 || $this->searchingForData ) {
			if ( !isset( $this->dataFrames[$hostPage] ) ) {
				$this->dataFrames[$hostPage] = new UsingDataPPFrameDOM( $frame, $hostPage );
			}
			$this->dataFrames[$hostPage]->addArgs( $frame, $args, $fragment );
			if ( $this->searchingForData ) {
				return '';
			}
		}

		if ( !$templateTitleObj ) {
			return '';
		}

		[ $dom, $tTitle ] = $this->fetchTemplate( $parser, $templateTitleObj );
		$cframe = $frame->newChild( $args, $tTitle );
		$cframe->namedArgs['data-found'] = $frame->depth == 0 ? '3' : '2';
		$cframe->namedArgs['data-source'] = $hostPage;
		$cframe->namedArgs['data-sourcee'] = wfEscapeWikiText( $hostPage );
		$cframe->namedArgs['data-fragment'] = $fragment;
		$cframe->namedArgs['data-source-fragment'] = $hostPage . ( empty( $fragment ) ? '' : ( '#' . $fragment ) );
		return $cframe->expand( $dom );
	}

	/** Returns template text for transclusion. */
	private function fetchTemplate( Parser $parser, $template ): array {
		if ( $template == '' || ( !is_string( $template ) && !is_object( $template ) ) ) {
			return [ '', null ];
		}

		$title = is_object( $template ) ? $template : Title::newFromText( $template, NS_TEMPLATE );
		if ( !$title ||
			$title->getNamespace() === NS_SPECIAL ||
			(
				$this->config->has( 'NonincludableNamespaces' ) &&
				in_array( $title->getNamespace(), $this->config->get( 'NonincludableNamespaces' ) )
			)
		) {
			return $title ? [ '[[:' . $title->getPrefixedText() . ']]', $title ] : [ '[[:' . $template . ']]', null ];
		}

		[ $dom, $title ] = $parser->getTemplateDom( $title );
		return [ $dom ?: ( '[[:' . $title->getPrefixedText() . ']]' ), $title ];
	}

	/** @inheritDoc */
	public function onBeforeParserFetchTemplateRevisionRecord(
		?LinkTarget $contextTitle,
		LinkTarget $title,
		bool &$skip,
		?RevisionRecord &$revRecord
	) {
		if ( !$this->searchingForData ) {
			return true;
		}

		$title = Title::newFromText( 'UsingDataPlaceholderTitle', NS_MEDIAWIKI );
		$skip = true;

		return false;
	}

	private function sanitizeTitleName( string $title ): string {
		$trimmedTitle = trim( $title );
		if ( str_contains( $trimmedTitle, '%' ) ) {
			return str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $trimmedTitle ) );
		}
		return $trimmedTitle;
	}
}
