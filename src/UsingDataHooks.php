<?php

namespace Fandom\UsingData;

use MediaWiki\Hook\BeforeParserFetchTemplateRevisionRecordHook;
use MediaWiki\Hook\GetMagicVariableIDsHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\PPNode;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use ReflectionProperty;

/**
 * Registers and defines parser functions for UsingData.
 */
class UsingDataHooks implements
	ParserFirstCallInitHook,
	GetMagicVariableIDsHook,
	ParserGetVariableValueSwitchHook,
	BeforeParserFetchTemplateRevisionRecordHook
{
	/** @var UsingDataPPFrameDOM[] Data frames for each page */
	private array $dataFrames = [];

	/** @var bool Whether we are currently searching for data */
	private bool $isInDataSearchMode = false;

	public function __construct(
		private readonly TitleFactory $titleFactory,
		private readonly NamespaceInfo $namespaceInfo,
	) {
	}

	public function onParserFirstCallInit( $parser ): void {
		$parser->setFunctionHook( 'using', $this->renderFunctionUsing( ... ), SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'usingarg', $this->renderFunctionUsingArg( ... ), SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'data', $this->renderFunctionData( ... ), SFH_OBJECT_ARGS );
		$parser->setFunctionHook( 'ancestorname', $this->renderFunctionAcenstorName( ... ),
			SFH_OBJECT_ARGS | SFH_NO_HASH );
		$parser->setHook( 'using', $this->renderTagUsing( ... ) );
	}

	/**
	 * Tells MediaWiki that one or more magic word IDs should be treated as variables.
	 */
	public function onGetMagicVariableIDs( &$variableIDs ): void {
		$variableIDs[] = 'parentname';
		$variableIDs[] = 'selfname';
	}

	/**
	 * Handles {{PARENTNAME}}, {{SELFNAME}}
	 */
	public function onParserGetVariableValueSwitch(
		$parser, &$variableCache, $magicWordId, &$ret, $frame
	): void {
		if ( $magicWordId == 'parentname' ) {
			$ret = $this->getAncestorName( $frame, 1 );
		}
		if ( $magicWordId == 'selfname' ) {
			$ret = $this->getAncestorName( $frame, 0 );
		}
	}

	public function onBeforeParserFetchTemplateRevisionRecord(
		?LinkTarget $contextTitle, LinkTarget $title,
		bool &$skip, ?RevisionRecord &$revRecord
	): bool {
		if ( $this->isInDataSearchMode ) {
			$skip = true;
			return false;
		}

		return true;
	}

	/**
	 * Handles {{ANCESTORNAME:depth}}
	 */
	public function renderFunctionAcenstorName( Parser $parser, PPFrame $frame, array $args ): array {
		$arg = $frame->expand( $args[0] );
		return [
			$this->getAncestorName( $frame, max( 0, is_numeric( $arg ) ? intval( $arg ) : 1 ) ),
			'noparse' => true
		];
	}

	/**
	 * Returns the page title of the $depth ancestor of $frame; empty string if invalid
	 */
	private function getAncestorName( PPFrame $frame, int $depth ): string {
		while ( $depth-- && $frame != null ) {
			$frame = $frame->parent ?? null;
		}
		/** @phan-suppress-next-line PhanUndeclaredProperty PPFrame->title */
		return is_object( $frame ) && isset( $frame->title ) && is_object( $frame->title )
			/** @phan-suppress-next-line PhanUndeclaredProperty PPFrame->title */
			? wfEscapeWikiText( $frame->title->getPrefixedText() ) : '';
	}

	/**
	 * Returns a UsingData frame for a given page
	 */
	private function getDataFrame(
		string $sourcePage, ?Title $title, Parser $parser, PPFrame $frame
	): UsingDataPPFrameDOM {
		if ( !isset( $this->dataFrames[$sourcePage] ) ) {
			$this->dataFrames[$sourcePage] = new UsingDataPPFrameDOM( $frame, $sourcePage );
			$parsingTitle = $this->titleFactory->castFromPageReference( $parser->getPage() );
			if ( ( $sourcePage != '' && $sourcePage != $parsingTitle?->getPrefixedText() )
				|| $parser->getOptions()->getIsSectionPreview()
			) {
				$text = null;
				if ( $title ) {
					[ $text, $title ] = $parser->fetchTemplateAndTitle( $title );
				}
				if ( $title && $title->getPrefixedText() != $sourcePage ) {
					$this->dataFrames[$title->getPrefixedText()] = $this->dataFrames[$sourcePage];
				}
				if ( $text !== null ) {
					$this->makeDataParserAndRun( $parser,
						static function ( Parser $dataParser ) use ( $text, $title, $parser ) {
							$dataParser->preprocess( $text, $title, clone $parser->getOptions() );
							$parser->mPPNodeCount += $dataParser->mPPNodeCount;
						}
					);
				}
			}
		}
		return $this->dataFrames[$sourcePage];
	}

	private function makeDataParserAndRun( Parser $parser, callable $callback ): void {
		$hookRunnerProperty = new ReflectionProperty( $parser, 'hookRunner' );
		$originalHookRunner = $hookRunnerProperty->getValue( $parser );

		$hookContainerProperty = new ReflectionProperty( $originalHookRunner, 'container' );
		$hookContainer = $hookContainerProperty->getValue( $originalHookRunner );

		$newHookRunner = new class ( $hookContainer ) extends HookRunner {
			public function onParserClearState( $parser ): bool {
				return true;
			}
		};

		try {
			$dataParser = clone $parser;
			$hookRunnerProperty->setValue( $dataParser, $newHookRunner );
			$callback( $dataParser );
		} finally {
			$this->isInDataSearchMode = false;
		}
	}

	/**
	 * {{#using:Page#Hash|Template|Default|...}} parses Template using #data from Page's Hash fragment; or Default
	 * if no data from Page can be found. Named arguments override those in the #data tag.
	 */
	public function renderFunctionUsing( Parser $parser, PPFrame $frame, array $args ): string {
		$parse = $this->parseUsingCommons( $parser, $frame, $args );
		if ( !is_array( $parse ) ) {
			return '';
		}

		/** @var UsingDataPPFrameDOM $dataFrame */
		[ $dataFrame, $fragment, $namedArgs, $templateTitle, $defaultValue ] = $parse;
		if ( !$dataFrame->hasFragment( $fragment ) && $defaultValue !== null ) {
			return $frame->expand( $defaultValue );
		}
		[ $dom, $title ] = $this->fetchTemplate( $parser, $templateTitle );
		return $dataFrame->expandOn( $frame, $title, $dom, $namedArgs, $fragment );
	}

	/**
	 * {{#usingarg:Page#Hash|Arg|Default}} returns the value of Arg data field on Page's Hash fragment, Default if
	 * undefined.
	 */
	public function renderFunctionUsingArg( Parser $parser, PPFrame $frame, array $args ) {
		$parse = $this->parseUsingCommons( $parser, $frame, $args );
		if ( !is_array( $parse ) ) {
			return '';
		}

		/** @var UsingDataPPFrameDOM $dataFrame */
		[ $dataFrame, $fragment, $namedArgs, $argName, $defaultValue ] = $parse;
		$ret = $dataFrame->getArgumentForParser(
			$parser,
			UsingDataPPFrameDOM::normalizeFragment( $fragment ),
			$argName,
			$defaultValue === null ? '' : false
		);
		return $ret !== false ? $ret : $frame->expand( $defaultValue );
	}

	/**
	 * Parses common elements of #using syntax.
	 */
	private function parseUsingCommons( Parser $parser, PPFrame $frame, array $args ): ?array {
		if ( $this->isInDataSearchMode ) {
			return null;
		}

		$source = trim( $frame->expand( $args[0] ) );
		if ( str_contains( $source, '%' ) ) {
			$source = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $source ) );
		}
		$title = $this->titleFactory->newFromText( $source );
		$sourcePage = is_object( $title ) ? $title->getPrefixedText() : '';
		$sourceFragment = is_object( $title ) ? $title->getFragment() : '';
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
		return [ $this->getDataFrame( $sourcePage, $title, $parser, $frame ), $sourceFragment, $namedArgs, $one, $two ];
	}

	/**
	 * <using page="Page#Hash" default="Default">...</using>
	 * expands ... using the data from Page's Hash fragment; Default if undefined.
	 * This tag relies on $parser->replaceVariables($text, $frame), which may prove fragile across MW versions.
	 * Should it break, $parser->recursiveTagParse($text, $frame), in combination with either modifying the markerType,
	 * or using insertStripItem directly, is a viable short-term alternative -- but one that call certain hooks
	 * prematurely, potentially causing other extensions to misbehave slightly.
	 */
	public function renderTagUsing(
		string $text, array $args, Parser $parser, PPFrame $frame
	): array {
		if ( $this->isInDataSearchMode ) {
			return [ '', 'markerType' => 'none' ];
		}

		$source = isset( $args['page'] ) ? $parser->replaceVariables( $args['page'], $frame ) : '';
		unset( $args['page'] );
		if ( str_contains( $source, '%' ) ) {
			$source = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $source ) );
		}
		$title = $this->titleFactory->newFromText( $source );

		if ( is_object( $title ) ) {
			$dataFrame = $this->getDataFrame( $title->getPrefixedText(), $title, $parser, $frame );
			if ( $dataFrame->hasFragment( $title->getFragment() ) ) {
				$ovr = [];
				unset( $args['default'] );
				foreach ( $args as $key => $val ) {
					$ovr[$key] = $parser->replaceVariables( $val, $frame );
				}
				return [
					/** @phan-suppress-next-line PhanUndeclaredProperty PPFrame->title */
					$dataFrame->expandOn( $frame, $frame->title, $text, $ovr, $title->getFragment(), true ),
					'markerType' => 'none'
				];
			}
		}

		return [
			isset( $args['default'] ) ? $parser->replaceVariables( $args['default'], $frame ) : '',
			'markerType' => 'none'
		];
	}

	/**
	 * {{#data:Template#Hash|...}} specifies data-transcludable arguments for the page; may not be transcluded.
	 */
	public function renderFunctionData( Parser $parser, PPFrame $frame, array $args ): string {
		/** @phan-suppress-next-line PhanUndeclaredProperty PPFrame->title */
		$hostPage = $frame->title->getPrefixedText();

		$templateName = trim( $frame->expand( $args[0] ) );
		unset( $args[0] );
		if ( str_contains( $templateName, '%' ) ) {
			$templateName = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $templateName ) );
		}
		$templateTitle = $this->titleFactory->newFromText( $templateName, NS_TEMPLATE );

		$fragment = '';
		if ( is_object( $templateTitle ) ) {
			$fragment = $templateTitle->getFragment();
		} elseif ( $templateName != '' && $templateName[0] == '#' ) {
			$fragment = substr( $templateName, 1 );
		}

		if ( $frame->depth == 0 || $this->isInDataSearchMode ) {
			$this->dataFrames[$hostPage] ??= new UsingDataPPFrameDOM( $frame, $hostPage );
			$this->dataFrames[$hostPage]->addArgs( $frame, $args, $fragment );
			if ( $this->isInDataSearchMode ) {
				return '';
			}
		}
		if ( !is_object( $templateTitle ) ) {
			return '';
		}

		[ $dom, $templateTitle ] = $this->fetchTemplate( $parser, $templateTitle );
		$childFrame = $frame->newChild( $args, $templateTitle );
		/** @phan-suppress-next-line PhanUndeclaredProperty PPFrame->title */
		$childFrame->namedArgs = array_merge( $childFrame->namedArgs ?? [], [
			'data-found' => $frame->depth == 0 ? '3' : '2',
			'data-source' => $hostPage,
			'data-sourcee' => wfEscapeWikiText( $hostPage ),
			'data-fragment' => $fragment,
			'data-source-fragment' => $hostPage . ( empty( $fragment ) ? '' : ( '#' . $fragment ) ),
		] );
		return $childFrame->expand( $dom );
	}

	/**
	 * Returns template text for transclusion.
	 * @return array{0:PPNode|string|null,1:Title|null}
	 */
	private function fetchTemplate( Parser $parser, Title|string $template ): array {
		if ( $template === '' ) {
			return [ null, null ];
		}

		$title = is_object( $template ) ? $template
			: $this->titleFactory->newFromText( $template, NS_TEMPLATE );
		if ( !is_object( $title )
			 || $title->getNamespace() == NS_SPECIAL
			 || $this->namespaceInfo->isNonincludable( $title->getNamespace() )
		) {
			return is_object( $title )
				? [ '[[:' . $title->getPrefixedText() . ']]', $title ]
				: [ '[[:' . $template . ']]', null ];
		}
		[ $dom, $title ] = $parser->getTemplateDom( $title );
		return [ $dom ?: ( '[[:' . $title->getPrefixedText() . ']]' ), $title ];
	}
}
