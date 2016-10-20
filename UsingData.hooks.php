<?php
class FXUsingData {
	private $dataFrames = array();
	private $searchingForData = false;

	/* Returns a UsingData frame for a given page
	 */
	private function getDataFrame($sourcePage, $title, &$parser, $frame) {
		global $wgHooks;
		if (!isset($this->dataFrames[$sourcePage])) {
			$this->dataFrames[$sourcePage] = new FXUsingDataPPFrame_DOM($frame, $sourcePage);
			if ($sourcePage != '' && ($sourcePage != $parser->getTitle()->getPrefixedText()) || $parser->getOptions()->getIsSectionPreview()) {
				list($text, $fTitle) = $parser->fetchTemplateAndTitle($title);
				if (is_object($fTitle) && $fTitle->getPrefixedText() != $sourcePage) 
					$this->dataFrames[$fTitle->getPrefixedText()] = $this->dataFrames[$sourcePage];
				if (is_string($text) && $text != '') {
					$this->searchingForData = true;
					$clearStateHooks = $wgHooks['ParserClearState'];
					$wgHooks['ParserClearState'] = array(); // Other extensions tend to assume the hook is only called by wgParser and reset internal state
						$subParser = clone $parser;
						$subParser->preprocess($text, $fTitle, clone $parser->getOptions());
						$subParser->mTplDomCache = array(); // We might've blocked access to templates while preprocessing; should not be cached
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
		while ($depth-- && $frame != null) $frame = isset($frame->parent) ? $frame->parent : null;
		return is_object($frame) && isset($frame->title) && is_object($frame->title)
		 ? wfEscapeWikiText($frame->title->getPrefixedText()) : '';
	}
	/* Handles {{ANCESTORNAME:depth}} */
	public static function ancestorNameFunction(&$parser, $frame, $args) {
		$arg = $frame->expand($args[0]);
		return array(self::ancestorNameHandler($frame, max(0, is_numeric($arg) ? intval($arg): 1)), 'noparse' => true);
	}
	/* Handles {{PARENTNAME}}, {{SELFNAME}}, {{ANCESTORNAME}} */
	public static function ancestorNameVar(&$parser, &$varCache, &$index, &$ret, &$frame) {
		if ($index == 'parentname') $ret = self::ancestorNameHandler($frame, 1);
		if ($index == 'selfname') $ret = self::ancestorNameHandler($frame, 0);
		return true;
	}

	/* Parses common elements of #using syntax.
	 */
	private function usingParse(&$parser, $frame, $args) {
		if ($this->searchingForData) return '';
		
		$titleArg = trim($frame->expand($args[0]));
		if (strpos($titleArg, '%') !== false) $titleArg = str_replace(array('<', '>'), array('&lt;', '&gt;'), urldecode($titleArg));
		$title = Title::newFromText($titleArg, NS_MAIN);
		$sourcePage = is_object($title) ? $title->getPrefixedText() : '';
		$sourceHash = is_object($title) ? $title->getFragment() : '';
		$namedArgs = array();
				
		$one = null; $two = null;
		foreach ($args as $key => $val) {
			if ($key === 0) continue;
			$bits = $val->splitArg();
			if ($bits['index'] === '1') {
				$one = $frame->expand($bits['value']);
			} elseif ($bits['index'] === '2') {
				$two = $bits['value'];
			} elseif ($bits['index'] === '') {
				$namedArgs[trim($frame->expand($bits['name']))] = $bits['value'];
			}
		}

		return array($this->getDataFrame($sourcePage, $title, $parser, $frame), $sourceHash, $namedArgs, $one, $two);
	}

	/* {{#using:Page#Hash|Template|Default|...}} parses Template using #data from Page's Hash fragment; or Default
	 * if no data from Page can be found. Named arguments override those in the #data tag.
	 */
	public function usingParserFunction(&$parser, $frame, $args) {
		$parse = $this->usingParse($parser, $frame, $args);
		if (!is_array($parse)) return '';

		list($dframe, $fragment, $namedArgs, $templateTitle, $defaultValue) = $parse;
		if (!$dframe->hasFragment($fragment) && !is_null($defaultValue)) {
			return $frame->expand($defaultValue);
		}
		list ($dom, $title) = $this->fetchTemplate($parser, $templateTitle);
		return $dframe->expandUsing($frame, $title, $dom, $namedArgs, $fragment);
	}
		
	/* {{#usingarg:Page#Hash|Arg|Default}} returns the value of Arg data field on Page's Hash fragment, Default if undefined.
	 */
	public function usingArgParserFunction(&$parser, $frame, $args) {
		$parse = $this->usingParse($parser, $frame, $args);
		if (!is_array($parse)) return '';

		list($dframe, $fragment, $namedArgs, $argName, $defaultValue) = $parse;
		$ret = $dframe->getArgumentForParser($parser, FXUsingDataPPFrame_DOM::normalizeFragment($fragment), $argName, is_null($defaultValue) ? '' : false);
		return $ret !== false ? $ret : $frame->expand($defaultValue);
	}
	
	/* <using page="Page#Hash" default="Default">...</using> expands ... using the data from Page's Hash fragment; Default if undefined.
	 *
	 * This tag relies on $parser->replaceVariables($text, $frame), which may prove fragile across MW versions.
	 * Should it break, $parser->recursiveTagParse($text, $frame), in combination with either modifying the markerType, or using
	 * insertStripItem directly, is a viable short-term alternative -- but one that call certain hooks prematurely, 
	 * potentially causing other extensions to misbehave slightly.
	 */
	public function usingTag( Parser &$parser, PPFrame $frame, $text, $args) {
		if ($this->searchingForData) return '';
		$source = isset($args['page']) ? $parser->replaceVariables($args['page'], $frame) : '';
		unset($args['page']);
		if (strpos($source, '%') !== false) $source = str_replace(array('<', '>'), array('&lt;', '&gt;'), urldecode($source));
		$title = Title::newFromText($source, NS_MAIN);
		if (is_object($title)) {
			$dframe = $this->getDataFrame($title->getPrefixedText(), $title, $parser, $frame);
			if (is_object($dframe) && $dframe->hasFragment($title->getFragment())) {
				$ovr = array(); unset($args['default']);
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
		if (strpos($templateTitle, '%') !== false) $templateTitle = str_replace(array('<', '>'), array('&lt;', '&gt;'), urldecode($templateTitle));
		$templateTitleObj = Title::newFromText($templateTitle, NS_TEMPLATE);
		if (is_object($templateTitleObj)) {
			$fragment = $templateTitleObj->getFragment();
		} elseif ($templateTitle != '' && $templateTitle[0] == '#') {
			$fragment = substr($templateTitle, 1);
		}

		if ($frame->depth == 0 || $this->searchingForData) {
			if (!isset($this->dataFrames[$hostPage])) {
				$this->dataFrames[$hostPage] = new FXUsingDataPPFrame_DOM($frame, $hostPage);
			}
			$df =& $this->dataFrames[$hostPage];
			$df->addArgs($frame, $args, $fragment);
			if ($this->searchingForData) return '';
		}
		if (!is_object($templateTitleObj)) return '';
		
		list($dom, $tTitle) = $this->fetchTemplate($parser, $templateTitleObj);
		foreach ($args as $k => $v) $args[$k] = $v->node;
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
		if ($template == '' || (!is_string($template) && !is_object($template))) return '';
		$title = is_object($template) ? $template : Title::newFromText($template, NS_TEMPLATE);
		if (!is_object($title) || $title->getNamespace() == NS_SPECIAL || ($wgNonincludableNamespaces && in_array( $title->getNamespace(), $wgNonincludableNamespaces))) {
			return is_object($title) ? array('[[:'.$title->getPrefixedText().']]', $title) : array('[[:'.$template.']]', null);
		}
		list($dom, $title) = $parser->getTemplateDom($title);
		return array($dom ? $dom : ('[[:'.$title->getPrefixedText().']]'), $title);
	}

	/* Disable template expansion while looking for #data tags.
	 */
	public function onBeforeParserFetchTemplateAndtitle($parser, $title, &$skip, &$id) {
		static $phTitle = null;
		if (!$this->searchingForData) return true;
		if (is_null($phTitle)) $phTitle = Title::newFromText('UsingDataPlaceholderTitle', NS_MEDIAWIKI);
		$title = $phTitle;
		$skip = true;
		return false;
	}
	
	/* Initialize parser hooks.
	 */
	public static function onParserFirstCallInit(&$parser) {
		static $instance = null;
		if ($instance == null) {
			global $wgHooks;
			$wgHooks['BeforeParserFetchTemplateAndtitle'][] = $instance = new FXUsingData;
		}
		$parser->setFunctionHook( 'using', array($instance, 'usingParserFunction'), SFH_OBJECT_ARGS);
		$parser->setFunctionHook( 'usingarg', array($instance, 'usingArgParserFunction'), SFH_OBJECT_ARGS);
		$parser->setFunctionHook( 'data', array($instance, 'dataParserFunction'), SFH_OBJECT_ARGS);
		$parser->setFunctionHook( 'ancestorname', 'FXUsingData::ancestorNameFunction', SFH_OBJECT_ARGS | SFH_NO_HASH);
		$parser->setFunctionTagHook('using', array($instance, 'usingTag'), 0);
		return true;
	}
}

class FXUsingDataPPFrame_DOM extends PPFrame_DOM {
	var $parent, $sourcePage; // Parent frame (either from #using or #data, providing a parser if needed), data source title
	var $knownFragments = array(); // Specifies which fragments have been declared
	var $pendingArgs = null; // Pending argument lists
	var $serializedArgs = array(); // Serialized wikitext cache for generic parsers
	var $expandedArgs = array(), $expansionForParser = null; // Expanded wikitext cache for a specific parser
	var $overrideArgs = null, $overrideFrame = null; // Argument list and frame for expanding additional argument passed through #using
	var $expansionFragment = '', $expansionFragmentN = '##'; // Current expansion fragment; pure and normalized (prefix) form

	function __construct(PPFrame $inner, $pageName) {
		PPFrame_DOM::__construct($inner->preprocessor);
		$this->args = array();
		$this->parent = $inner;
		$this->depth = $inner->depth + 1;
		$this->title = $inner->title;
		$this->sourcePage = $pageName;
	}
	
	static function normalizeFragment($fragment) {
		return str_replace('#', '# ', strtolower($fragment)).'##';
	}
	
	public function addArgs($frame, $args, $fragment) {
		if (is_null($this->pendingArgs)) $this->pendingArgs = array();
		
		$namedArgs = array();
		$prefix = self::normalizeFragment($fragment);
		foreach ($args as $k => $arg) {
			if ($k == 0) continue;
			$arg = $arg->splitArg();
			if ($arg['index'] === '') {
				$namedArgs[$prefix . trim($frame->expand($arg['name']))] = $arg['value'];
			}
		}
		
		$this->pendingArgs[] = array($frame, $namedArgs);
		$this->knownFragments[$prefix] = true;
	}
	
	public function expandUsing(PPFrame $frame, $templateTitle, $text, $moreArgs, $fragment, $useRTP = false) {
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
		$this->expansionFragmentN = self::normalizeFragment($this->expansionFragment);
		$this->title = is_object($templateTitle) ? $templateTitle : $frame->title;
		if ($oldParser != null && $oldParser !== $frame->parser && !empty($this->expandedArgs))
			$this->expandedArgs = array();
		
		if (is_string($text) && $useRTP) {
			$ret = $this->expansionForParser->replaceVariables($text, $this);
		} else {
			$ret = $this->expand($text === null ? '' : $text);
		}
		
		$this->overrideArgs = $oldArgs;
		$this->expansionFragment = $oldFragment;
		$this->overrideFrame = $oldFrame;
		$this->expansionFragmentN = self::normalizeFragment($this->expansionFragment);
		$this->title =& $oldTitle;
		if ($oldParser != null) {
			$this->expansionForParser = $oldParser;
			$this->expandedArgs = $oldExpanded;
		}
				
		return $ret;
	}

	public function hasFragment($fragment) {
		return isset($this->knownFragments[self::normalizeFragment($fragment)]);
	}

	public function isEmpty() {
		return !isset($this->knownFragments[$this->expansionFragmentN]);
	}

	public function getArgumentForParser($parser, $normalizedFragment, $arg, $default = false) {
		$arg = $normalizedFragment . $arg;
		if (isset($this->expandedArgs[$arg]) && $this->expansionForParser === $parser)
			return $this->expandedArgs[$arg];
		if (!isset($this->serializedArgs[$arg])) {
			if (is_null($this->pendingArgs)) return $default;
			foreach ($this->pendingArgs as &$aar) {
				if (isset($aar[1][$arg])) {
					$text = $aar[1][$arg]; unset($aar[1][$arg]);
					$text = $aar[0]->expand($text);
					if (strpos($text, $aar[0]->parser->uniqPrefix()) !== FALSE) {
						$text = $aar[0]->parser->serialiseHalfParsedText(' '.$text); // MW bug 26731
					}
					$this->serializedArgs[$arg] = $text;
					break;
				}
			}
		}

		if (!isset($this->serializedArgs[$arg])) return $default;
		
		$ret = $this->serializedArgs[$arg];
		$ret = trim(is_array($ret) ? $parser->unserialiseHalfParsedText($ret) : $ret);
		if ($parser === $this->expansionForParser) $this->expandedArgs[$arg] = $ret;
		return $ret;
	}

	public function getArgument( $index ) {
		switch($index) {
			case 'data-found':   return $this->isEmpty() ? null : '1';
			case 'data-source':  return $this->sourcePage;
			case 'data-sourcee': return wfEscapeWikiText($this->sourcePage);
			case 'data-fragment': return $this->expansionFragment;
			case 'data-source-fragment': return $this->sourcePage . (empty($this->expansionFragment) ? '' : ('#' . $this->expansionFragment));
			default:
				if (is_array($this->overrideArgs) && isset($this->overrideArgs[$index])) {
					if (is_object($this->overrideArgs[$index])) $this->overrideArgs[$index] = $this->overrideFrame->expand($this->overrideArgs[$index]);
					return $this->overrideArgs[$index];
				}
				$p = is_null($this->expansionForParser) ? $this->parent->parser : $this->expansionForParser;
				return $this->getArgumentForParser($p, $this->expansionFragmentN, $index, false);
		}
	}
}