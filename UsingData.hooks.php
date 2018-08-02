<?php

namespace Foxlit;

class UsingData {
	private $dataFrames = [];
	private $searchingForData = false;

	/* Initialize parser hooks.
	 */
	static public function onParserFirstCallInit(&$parser) {
		static $instance = null;

		if ($instance == null) {
			global $wgHooks;
			$wgHooks['BeforeParserFetchTemplateAndtitle'][] = $instance = new self;
		}
		$parser->setFunctionHook( 'using', [$instance, 'usingParserFunction'], SFH_OBJECT_ARGS);
		$parser->setFunctionHook( 'usingarg', [$instance, 'usingArgParserFunction'], SFH_OBJECT_ARGS);
		$parser->setFunctionHook( 'data', [$instance, 'dataParserFunction'], SFH_OBJECT_ARGS);
		$parser->setFunctionHook( 'ancestorname', 'FXUsingData::ancestorNameFunction', SFH_OBJECT_ARGS | SFH_NO_HASH);
		$parser->setFunctionTagHook('using', [$instance, 'usingTag'], 0);

		return true;
	}

	/**
	 * Tells MediaWiki that one or more magic word IDs should be treated as variables.
	 *
	 * @access	public
	 * @return	void
	 */
	static public function onMagicWordwgVariableIDs(&$magicWords) {
		$magicWords[] = 'parentname';
		$magicWords[] = 'selfname';
	}

	/* Returns a UsingData frame for a given page
	 */
	private function getDataFrame($sourcePage, $title, &$parser, $frame) {
		global $wgHooks;
		if (!isset($this->dataFrames[$sourcePage])) {
			$this->dataFrames[$sourcePage] = new UsingDataPPFrame_DOM($frame, $sourcePage);
			if ($sourcePage != '' && ($sourcePage != $parser->getTitle()->getPrefixedText()) || $parser->getOptions()->getIsSectionPreview()) {
				list($text, $fTitle) = $parser->fetchTemplateAndTitle($title);
				if (is_object($fTitle) && $fTitle->getPrefixedText() != $sourcePage) {
					$this->dataFrames[$fTitle->getPrefixedText()] = $this->dataFrames[$sourcePage];
				}
				if (is_string($text) && $text != '') {
					$this->searchingForData = true;
					$clearStateHooks = $wgHooks['ParserClearState'];
					$wgHooks['ParserClearState'] = []; // Other extensions tend to assume the hook is only called by wgParser and reset internal state
						$subParser = clone $parser;
						$subParser->preprocess($text, $fTitle, clone $parser->getOptions());
						$subParser->mTplDomCache = []; // We might've blocked access to templates while preprocessing; should not be cached
						$subParser->mOutput = $parser->mOutput;
					$wgHooks['ParserClearState'] = empty($wgHooks['ParserClearState']) ? $clearStateHooks : array_merge($clearStateHooks, $wgHooks['ParserClearState']);
					$parser->mPPNodeCount += $subParser->mPPNodeCount;
					$this->searchingForData = false;
				}
			}
		}
		return $this->dataFrames[$sourcePage];
	}

	/* Returns the page title of the $depth ancestor of $frame; empty string if invalid */
	private static function ancestorNameHandler($frame, $depth) {
		while ($depth-- && $frame != null) {
			$frame = isset($frame->parent) ? $frame->parent : null;
		}
		return is_object($frame) && isset($frame->title) && is_object($frame->title)
		 ? wfEscapeWikiText($frame->title->getPrefixedText()) : '';
	}
	/* Handles {{ANCESTORNAME:depth}} */
	public static function ancestorNameFunction(&$parser, $frame, $args) {
		$arg = $frame->expand($args[0]);
		return [self::ancestorNameHandler($frame, max(0, is_numeric($arg) ? intval($arg) : 1)), 'noparse' => true];
	}
	/* Handles {{PARENTNAME}}, {{SELFNAME}}, {{ANCESTORNAME}} */
	public static function ancestorNameVar(&$parser, &$varCache, &$index, &$ret, &$frame) {
		if ($index == 'parentname') {
			$ret = self::ancestorNameHandler($frame, 1);
		}
		if ($index == 'selfname') {
			$ret = self::ancestorNameHandler($frame, 0);
		}
		return true;
	}

	/* Parses common elements of #using syntax.
	 */
	private function usingParse(&$parser, $frame, $args) {
		if ($this->searchingForData) {
			return '';
		}

		$titleArg = trim($frame->expand($args[0]));
		if (strpos($titleArg, '%') !== false) {
			$titleArg = str_replace(['<', '>'], ['&lt;', '&gt;'], urldecode($titleArg));
		}
		$title = \Title::newFromText($titleArg, NS_MAIN);
		$sourcePage = is_object($title) ? $title->getPrefixedText() : '';
		$sourceHash = is_object($title) ? $title->getFragment() : '';
		$namedArgs = [];

		$one = null;
		$two = null;
		foreach ($args as $key => $val) {
			if ($key === 0) {
				continue;
			}
			$bits = $val->splitArg();
			if ($bits['index'] === '1') {
				$one = $frame->expand($bits['value']);
			} elseif ($bits['index'] === '2') {
				$two = $bits['value'];
			} elseif ($bits['index'] === '') {
				$namedArgs[trim($frame->expand($bits['name']))] = $bits['value'];
			}
		}

		return [$this->getDataFrame($sourcePage, $title, $parser, $frame), $sourceHash, $namedArgs, $one, $two];
	}

	/* {{#using:Page#Hash|Template|Default|...}} parses Template using #data from Page's Hash fragment; or Default
	 * if no data from Page can be found. Named arguments override those in the #data tag.
	 */
	public function usingParserFunction(&$parser, $frame, $args) {
		$parse = $this->usingParse($parser, $frame, $args);
		if (!is_array($parse)) {
			return '';
		}

		list($dframe, $fragment, $namedArgs, $templateTitle, $defaultValue) = $parse;
		if (!$dframe->hasFragment($fragment) && !is_null($defaultValue)) {
			return $frame->expand($defaultValue);
		}
		list($dom, $title) = $this->fetchTemplate($parser, $templateTitle);
		return $dframe->expandUsing($frame, $title, $dom, $namedArgs, $fragment);
	}

	/* {{#usingarg:Page#Hash|Arg|Default}} returns the value of Arg data field on Page's Hash fragment, Default if undefined.
	 */
	public function usingArgParserFunction(&$parser, $frame, $args) {
		$parse = $this->usingParse($parser, $frame, $args);
		if (!is_array($parse)) {
			return '';
		}

		list($dframe, $fragment, $namedArgs, $argName, $defaultValue) = $parse;
		$ret = $dframe->getArgumentForParser($parser, UsingDataPPFrame_DOM::normalizeFragment($fragment), $argName, is_null($defaultValue) ? '' : false);
		return $ret !== false ? $ret : $frame->expand($defaultValue);
	}

	/* <using page="Page#Hash" default="Default">...</using> expands ... using the data from Page's Hash fragment; Default if undefined.
	 *
	 * This tag relies on $parser->replaceVariables($text, $frame), which may prove fragile across MW versions.
	 * Should it break, $parser->recursiveTagParse($text, $frame), in combination with either modifying the markerType, or using
	 * insertStripItem directly, is a viable short-term alternative -- but one that call certain hooks prematurely,
	 * potentially causing other extensions to misbehave slightly.
	 */
	public function usingTag(\Parser &$parser, \PPFrame $frame, $text, $args) {
		if ($this->searchingForData) {
			return '';
		}

		$source = isset($args['page']) ? $parser->replaceVariables($args['page'], $frame) : '';
		unset($args['page']);
		if (strpos($source, '%') !== false) {
			$source = str_replace(['<', '>'], ['&lt;', '&gt;'], urldecode($source));
		}
		$title = \Title::newFromText($source, NS_MAIN);
		if (is_object($title)) {
			$dframe = $this->getDataFrame($title->getPrefixedText(), $title, $parser, $frame);
			if (is_object($dframe) && $dframe->hasFragment($title->getFragment())) {
				$ovr = [];
unset($args['default']);
				foreach ($args as $key => $val) {
					$ovr[$key] = $parser->replaceVariables($val, $frame);
				}
				return $dframe->expandUsing($frame, $frame->title, $text, $ovr, $title->getFragment(), true);
			}
		}
		return isset($args['default']) ? $parser->replaceVariables($args['default'], $frame) : '';
	}

	/* {{#data:Template#Hash|...}} specifies data-transcludable arguments for the page; may not be transcluded.
	 */
	public function dataParserFunction(&$parser, $frame, $args) {
		$templateTitle = trim($frame->expand($args[0]));
		$hostPage = $frame->title->getPrefixedText();
		unset($args[0]);
		$fragment = '';

		if (strpos($templateTitle, '%') !== false) {
			$templateTitle = str_replace(['<', '>'], ['&lt;', '&gt;'], urldecode($templateTitle));
		}

		$templateTitleObj = \Title::newFromText($templateTitle, NS_TEMPLATE);
		if (is_object($templateTitleObj)) {
			$fragment = $templateTitleObj->getFragment();
		} elseif ($templateTitle != '' && $templateTitle[0] == '#') {
			$fragment = substr($templateTitle, 1);
		}

		if ($frame->depth == 0 || $this->searchingForData) {
			if (!isset($this->dataFrames[$hostPage])) {
				$this->dataFrames[$hostPage] = new UsingDataPPFrame_DOM($frame, $hostPage);
			}
			$df =& $this->dataFrames[$hostPage];
			$df->addArgs($frame, $args, $fragment);
			if ($this->searchingForData) {
				return '';
			}
		}
		if (!is_object($templateTitleObj)) {
			return '';
		}

		list($dom, $tTitle) = $this->fetchTemplate($parser, $templateTitleObj);
		foreach ($args as $k => $v) {
			$args[$k] = $v->node;
		}
		$cframe = $frame->newChild($args, $tTitle);
		$nargs =& $cframe->namedArgs;
		$nargs['data-found'] = $frame->depth == 0 ? '3' : '2';
		$nargs['data-source'] = $hostPage;
		$nargs['data-sourcee'] = wfEscapeWikiText($hostPage);
		$nargs['data-fragment'] = $fragment;
		$nargs['data-source-fragment'] = $hostPage . (empty($fragment) ? '' : ('#' . $fragment));
		return $cframe->expand($dom);
	}

	/* Returns template text for transclusion.
	 */
	private function fetchTemplate($parser, $template) {
		global $wgNonincludableNamespaces;

		if ($template == '' || (!is_string($template) && !is_object($template))) {
			return '';
		}

		$title = is_object($template) ? $template : \Title::newFromText($template, NS_TEMPLATE);
		if (!is_object($title) || $title->getNamespace() == NS_SPECIAL || ($wgNonincludableNamespaces && in_array( $title->getNamespace(), $wgNonincludableNamespaces))) {
			return is_object($title) ? ['[[:'.$title->getPrefixedText().']]', $title] : ['[[:'.$template.']]', null];
		}
		list($dom, $title) = $parser->getTemplateDom($title);
		return [$dom ? $dom : ('[[:'.$title->getPrefixedText().']]'), $title];
	}

	/* Disable template expansion while looking for #data tags.
	 */
	public function onBeforeParserFetchTemplateAndtitle($parser, $title, &$skip, &$id) {
		static $phTitle = null;

		if (!$this->searchingForData) {
			return true;
		}
		if (is_null($phTitle)) {
			$phTitle = \Title::newFromText('UsingDataPlaceholderTitle', NS_MEDIAWIKI);
		}

		$title = $phTitle;
		$skip = true;

		return false;
	}
}
