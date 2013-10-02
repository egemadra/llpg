<?php

class LLPG{

	private static $version="0.1.0";
	private $rootDir;
	private $warnings=array();
	private $source;
	private $pos=0;
	private $line=1;
	private $curToken=null;

	private $rules=array();
	private $subRules=array();
	public $hashedRules=array();

	private $trimLevel; //0 and 2.
	private $lexerRules=array();
	public $hashedLexerRules=array();

	public $allRules;


	public static function getVersion()
	{
		return self::$version;
	}

	public function __construct()
	{
		error_reporting(E_ALL ^ E_NOTICE);
		$this->rootDir=pathinfo(__FILE__,PATHINFO_DIRNAME).DIRECTORY_SEPARATOR;
	}

	public function parseFile($filename, $trimLevel=1)
	{
		if (!is_file($filename) || !is_readable($filename))
			throw new LLPGException("Error: file '$filename' does not exist or is not readable.");

		if (!ctype_digit("$trimLevel") || +$trimLevel>2)
			throw new LLPGException("Error: Bad trim level value of '$trimLevel'. Must be 0, 1 or 2.");

		$str=file_get_contents($filename);
		return $this->parseString($str, $trimLevel);
	}

	public function parseString($str, $trimLevel=1)
	{
		$this->trimLevel=$trimLevel;
		$this->source=$str;
		$this->getSym();
		$result=$this->parse_program();
		if (!$result) throw new LLPGException("Error: Expected program. Line $this->line.");
		$this->accept("eof", true);
		$this->rules=array_merge($this->rules,$this->subRules);
		//check if there are duplicately named rules
		$ruleNames=array();
		foreach ($this->rules as $r)
		{
			$ruleNames[]=$r->name;
			$this->hashedRules[$r->name]=$r;
		}

		foreach ($this->lexerRules as $r)
		{
			$ruleNames[]=$r->name;
			$this->hashedLexerRules[$r->name]=$r;
		}

		$uniques=array_unique($ruleNames);
		if (sizeof($uniques)!=sizeof($ruleNames))
		{
			$duplicates=array_diff_assoc($ruleNames, $uniques);
			$duplicateNames=implode(', ',$duplicates);
			throw new LLPGException("Error: Following rule names are not unique in rules: '$duplicateNames'.");
		}

		//check undefined:
		if (!$this->hashedRules['program'])
			throw new LLPGException("Error: No start rule is found. The start rule is a parser rule named 'program'.");

		$this->allRules=array_merge($this->hashedRules, $this->hashedLexerRules);

		$this->checkNonLL1();

		//convert inline terminals to lexer rules:
		foreach ($this->hashedRules as $rule)
		{
			foreach ($rule->alternatives as $a)
			{
				foreach ($a->expressions as $e)
				{
					if ($e->type=='regex' || $e->type=='string')
					{
						$name=$e->type=='regex' ? "R:$e->value" : "S:$e->value";
						if ($this->hashedLexerRules[$name]) continue;
						$lr=new Rule($name);
						$lr->isLexerRule=true;

						$a=new Alternative();
						$a->expressions[]=$e;
						$lr->alternatives[]=$a;
						$this->hashedLexerRules[$name]=$lr;
					}
					elseif ($e->type=='id' && $e->flatten && !$e->isTerminal)
					{
						if ($this->hashedLexerRules[$e->value])
							throw new LLPGException("Error: Terminal expressions resolving to lexer rule can not be flattened: '$e->value'. Line $e->line.");
					}
				}
			}
		}

		$this->allRules=array_merge($this->hashedRules, $this->hashedLexerRules);

		return true;
	}

	public function outputJSON($jsonPath)
	{
		$result=@file_put_contents($jsonPath, json_encode($this->allRules));
		if (!$result) throw new LLPGException("Error: file '$jsonPath' is not writeable.");
		return true;
	}

	public function outputParser($path, $format=true)
	{
		$lexerRules=array();
		foreach ($this->hashedLexerRules as $name=>$lr)
		{
			$r=array("n"=>$name,"a"=>array());
			foreach ($lr->alternatives as $a)
			{
				$e=$a->expressions[0];
				$r["a"][]=array("t"=>$e->type,"v"=>$e->value);
			}
			$lexerRules[]=$r;
		}
		$jsonLexerRules=json_encode($lexerRules);
		//file_put_contents("./out/lexerRules.json", json_encode($lexerRules));

		$ruleFunctionsCode="<?php\n";
		foreach ($this->rules as $rule)
			$ruleFunctionsCode.=$this->createRuleFunction($rule);

		if ($format)
		{
			define('CLASSONLY', true);
			$formatterFile="$this->rootDir"."phpformatter-master".DIRECTORY_SEPARATOR."format.php";
			require_once $formatterFile;
			$f=new Formatter($ruleFunctionsCode);
			$ruleFunctionsCode=$f->format();
		}

		$ruleFunctionsCode=substr_replace($ruleFunctionsCode, '', 0,6);

		$template=file_get_contents("$this->rootDir"."files".DIRECTORY_SEPARATOR."master.php");
		$outContents=str_replace("/***********generatedrules**********/", $ruleFunctionsCode, $template);
		$outContents=str_replace("/*%lexer_rules%*/",$jsonLexerRules,$outContents);
		$r=@file_put_contents($path, $outContents);
		if (!$r) throw new LLPGException("Error: '$path' is not writeable.");
	}

	public function getWarnings()
	{
		return $this->warnings;
	}

	private function checkRuleForLeftRecursion($rule,&$visited)
	{
		$visited[$rule->name]=1;

		foreach ($rule->alternatives as $a)
		{
			foreach ($a->expressions as $e)
			{
				if (!$e->isTerminal)
				{
					if ($visited[$e->value])
					{
						$visitList=array_keys($visited);
						$eVisited=reset($visitList);
						$visitList[]=$eVisited;
						$path=implode(' → ',$visitList);
						throw new LLPGException("Error: Left recursion. Rule '$eVisited' can be reached from the first non terminal in one of its own alternative productions: '$path'");
					}

					$r=$this->hashedRules[$e->value];
					if (!$r) $r=$this->hashedLexerRules[$e->value];
					$this->checkRuleForLeftRecursion($r,$visited);
					array_pop($visited);//remove the rules that didn't cause any problem.
				}

				if ($e->quantifier=='1' || $e->quantifier=='+') break; //We found the first non nullable, we don't check the rest.
			}
		}
	}

	private function checkRuleForUndefined($rule,&$visited,$forUnused)
	{
		foreach ($rule->alternatives as $a)
		{
			foreach ($a->expressions as $e)
			{
				if (!$e->isTerminal)
				{
					$name=$e->value;
					if (!$this->allRules[$name]) throw new LLPGException("Error: Undefined rule. '$name' is used in a production but it is not defined anywhere in the grammar. Line $e->line.");
					if (!$visited[$name])
					{
						$visited[$name]=$name;
						if ($forUnused) $this->checkRuleForUndefined($this->allRules[$name], $visited, true);
					}
				}
			}
		}
	}

	private function checkRuleForCommonPrefix($rule, &$visited, &$followList)
	{
		$followList[]=$rule->name;
		foreach ($rule->alternatives as $a)
		{
			foreach ($a->expressions as $e)
			{
				if (!$e->predicated)
				{
					if ($e->isTerminal)
					{
						$hash="$e->type:$e->value";
						if ($visited[$hash])
						{
							$err1=implode(' → ',$visited[$hash])." → $e->value";
							$err2=reset($visited[$hash])." → ".implode(' → ',$followList)." → $e->value";
							throw new LLPGException("Error: Common prefix. Terminal '$e->value' can be interpreted as the first item of
								at least 2 alternative productions: \"$err1\" & \"$err2\".");
						}

						$visited[$hash]=$followList;
					}
					else
					{
						if ($this->allRules[$e->value])
							$this->checkRuleForCommonPrefix($this->allRules[$e->value], $visited, $followList);
					}
				}
				if ($e->quantifier=='1') break; //We found the first non nullable, we don't check the rest.
			}
		}
		$followList=array();
	}

	private function checkNonLL1()
	{
		//check undefined rules:
		foreach ($this->allRules as $r)
		{
			$visited=array();
			$this->checkRuleForUndefined($r, $visited, false);
		}

		//check left recursion:
		foreach ($this->rules as $r)
		{
			$visited=array();
			$this->checkRuleForLeftRecursion($r, $visited);
		}

		//check common prefix:
		foreach ($this->hashedRules as $r)
		{
			$visited=array(); $followList=array();
			$this->checkRuleForCommonPrefix($r, $visited, $followList);
		}

		//check unused (this is not an error but still useful)
		$visited=array("program"=>"program");
		if ($this->hashedLexerRules['ignore']) $visited["ignore"]="ignore";

		$this->checkRuleForUndefined($this->hashedRules['program'],$visited,true);
		if (sizeof($visited)!=sizeof($this->allRules))
		{
			$definedRuleNames=array_keys($this->allRules);
			$diff=array_diff($definedRuleNames, $visited);
			$unusedList=implode(', ',$diff);
			$this->warnings[]= "Warning: Unused rule(s). Following rules are defined but can not be reached from the start rule: '$unusedList'.";
		}

		//add EOF
		$eof=new Exp();
		$eof->predicated=false;
		$eof->quantifier='1';
		$eof->type=':EOF';
		$eof->value="EOF";
		$eof->isTerminal=true;
		$eof->line='0';

		foreach ($this->hashedRules['program']->alternatives as $a)
			$a->expressions[]=$eof;
	}

	private function parse_program()
	{
		//defintion*;
		if (!$this->parse_definition()) return false;
		while($this->parse_definition());
		return true;
	}

	private function parse_definition()
	{
		//rule
		$ret=$this->parse_rule();
		if ($ret)
		{
			if ($ret->isLexerRule)
				$this->lexerRules[]=$ret;
			else
				$this->rules[]=$ret;
			return $ret;
		}
	}

	private function parse_rule()
	{
		//ID  ':'  alternatives  ';'
		$ruleNameToken=$this->accept("id",false) ;
		if (!$ruleNameToken) return false;
		$columnOrEq=$this->accept(':',false); //this is where we tell if lexer or parser rule.
		if (!$columnOrEq) $columnOrEq=$this->accept('=',true);
		$ret=$this->parse_alternatives();
		if (!$ret) throw new LLPGException("Error: Expected alternatives. Line $this->line.");
		$this->accept(';',true);

		//we have restrictons on lexer rules:
		//rule alternatives can't have more than 1 exps, alternatives or referefernces, no @ symbol before exp, no quantifier
		if ($columnOrEq->value=='=')
		{
			foreach ($ret as $a)
			{
				if (sizeof($a->expressions)>1) throw new LLPGException("Syntax error: Lexer rules can contain only one expression per alternative. Line {$a->expressions[0]->line}.");
				foreach ($a->expressions as $e)
				{
					if ($e->predicated) throw new LLPGException("Syntax error: Lexer rules can not have predicate symbol @. Line $e->line.");
					if (!$e->isTerminal) throw new LLPGException("Syntax error: Lexer rules must define terminals only. No grouping or referencing allowed. Line $e->line.");
					if ($e->quantifier!='1') throw new LLPGException("Syntax error: Expressions in lexer don't accept quantifiers. Use regex quantifiers instead. $e->line.");
				}
			}
		}
		else
		{
			if ($ruleNameToken->value=='ignore') throw new LLPGException("Syntax error: 'ignore' is a special name, parser rules can't have that name. $ruleNameToken->line.");
		}

		$r=new Rule();
		$r->name=$ruleNameToken->value;
		$r->alternatives=$ret;
		$r->isLexerRule=$columnOrEq->value=='=';
		return $r;
	}

	private function parse_alternatives()
	{
		//alternative ('|' alternative)*
		$alternatives=array();

		$ret=$this->parse_alternative();
		if (!$ret) return false;

		$alternatives[]=$ret;

		while(1)
		{
			$ret=$this->accept('|',false);
			if (!$ret) break;

			$ret=$this->parse_alternative();
			if (!$ret) throw new LLPGException("Error: Expected alternative. Line $this->line.");

			$alternatives[]=$ret;
		}

		return $alternatives;
	}

	private function parse_alternative()
	{
		//('@'? exp)+
		$expressions=array();

		$predicated=(bool)$this->accept('@', false);
		$ret=$this->parse_exp();
		if (!$ret) return false;
		$ret->predicated=$predicated;

		$expressions[]=$ret;

		while(1)
		{
			$ret=$this->parse_exp();
			if (!$ret) break;
			$ret->predicated=$predicated;
			$expressions[]=$ret;
		}

		$a=new Alternative();
		$a->expressions=$expressions;

		return $a;
	}

	private function parse_exp()
	{
		static $subRuleCount=0;
		//ID '!'? quantifier?
		//REGEX '!'? quantifier?
		//STRING '!'? quantifier?
		//'(' alternatives ')' '!'? ('=' ID)? quantifier?

		//first 3 is the same except id is nonterminal:
		foreach (array("id","regex","string") as $type)
		{
			$token=$this->accept($type,false);
			if ($token)
			{
				if ($type=='id')
					$flatten=(bool)$this->accept('<',false);
				$unwanted=(bool)$this->accept('!',false);
				$q=$this->parse_quantifier();

				$e=new Exp();

				$e->unwanted=$unwanted;
				$e->quantifier=$q ? $q->value : '1';
				$e->type=$type;
				$e->value=$token->value;
				$e->isTerminal=$type!='id';
				$e->line=$token->line;
				if ($flatten)
					$e->flatten=$flatten;
				//$this->ids["I:$token->value"]=$token->value;
				return $e;
			}
		}

		$ret=$this->accept('(',false);
		$parenLine=$ret->line;
		if ($ret)
		{
			$ret=$this->parse_alternatives();
			if (!$ret) throw new LLPGException("Error: Expected alternatives. Line $this->line.");
			$this->accept(')',true);

			$flatten=(bool)$this->accept('<',false);
			$unwanted=(bool)$this->accept('!',false);

			//optional renameing
			$eq=$this->accept('=',false);
			$newName='';
			if ($eq)
				$newName=$this->accept('id',true);

			$q=$this->parse_quantifier();

			//fake the code generator:
			$subRuleCount++;
			$r=new Rule();
			$r->alternatives=$ret;
			$r->name=$eq ? $newName->value : "subRule_$subRuleCount";
			$this->subRules[]=$r;

			$e=new Exp();
			$e->unwanted=$unwanted;
			$e->flatten=$flatten;
			$e->quantifier=$q ? $q->value : '1';
			$e->type='alternatives';
			$e->value=$r->name;
			$e->isTerminal=false;
			$e->line=$parenLine;

			return $e;
		}

		return false;
	}

	private function parse_quantifier()
	{
		//'?' | '*' | '+'
		foreach (array("?","*","+") as $q)
		{
			$ret=$this->accept($q,false);
			if ($ret) return $ret;
		}
		return false;
	}

	/***************************************************/
	private function getSym()
	{
		$t=$this->getToken();
		$this->curToken=$t;
		return $this->curToken;
	}

	private function accept($tokenType,$mustMatch)
	{
		if ($tokenType==$this->curToken->type)
		{
			$retVal=$this->curToken;
			$this->getSym();
			return $retVal;
		}

		if ($mustMatch)
			throw new LLPGException("Error: Expected '$tokenType' but found '{$this->curToken->type}' with value '{$this->curToken->value}'. Line $this->line.");
		return false;
	}

	private function createCall($exp,$mustMatch)
	{
		$sMustMatch=$mustMatch ? 'true' : 'false';
		$str="\$ret=";
		$val=str_replace("'", "\'", $exp->value);

		switch($exp->type)
		{
			case 'string':
				$str.="\$this->accept('S:$val',  $sMustMatch);\n";
				break;
			case 'id':
				$rule=$this->allRules[$exp->value];
				if ($rule->isLexerRule)
					$str.="\$this->accept('$rule->name',$sMustMatch);\n";
				else
					$str.="\$this->parse_$exp->value($sMustMatch);\n";
				break;
			case 'alternatives':
				$str.="\$this->parse_$exp->value($sMustMatch);\n";
				break;
			case ':EOF':
				$str.="\$this->accept(':EOF', $sMustMatch);\n";
				break;
			case 'regex':
				$str.="\$this->accept('R:$val', $sMustMatch);\n";
				break;
			default:
				throw new LLPGException('unidentified exp type.');
		}
		return $str;
	}

	private function createFoundPart($exp)
	{
		if (!$exp->unwanted)
		{
			if (!$exp->flatten)
				$str="\$r->tokens[]=\$ret;\n";
			else
				$str="if (is_a(\$ret,'Rule')) foreach (\$ret->tokens as \$t) \$r->tokens[]=\$t; else \$r->tokens[]=\$ret;\n";
		}
		else
			$str="//token was unwanted, not added.\n";
		return $str;
	}

	private function createRuleFunction($rule)
	{
		$str="
			private function parse_$rule->name(\$mustMatch){
				\$found=false;\$r=new Rule('$rule->name');\n
			";

		foreach ($rule->alternatives as $altIndex=>$alt)
		{
			$str.="if (!\$found){\n";
			$initial=false;
			$initialFound=false;
			foreach ($alt->expressions as $i=>$exp)
			{
				$initial=!$initialFound && ($exp->quantifier=='1'||$exp->quantifier=='+');
				if ($initial) $initialFound=true;
				if ($initial)
				{
					if ($exp->predicated)
						$str.="\$this->pred++;\n";
					$str.="\$stackPointer=\$this->stackPointer;\n";
					$str.=$this->createCall($exp, false);
					$str.="if (\$ret) {\n";
					if ($exp->predicated)
						$str.="\$this->pred--;\n";
					$str.="\$found=true;\n";
					$str.="\$r->index=$altIndex;\n";
					$str.=$this->createFoundPart($exp);
				}
				else
				{
					$mustMatch=$exp->quantifier=='1' || $exp->quantifier=='+';
					$str.=$this->createCall($exp, $mustMatch);
					$foundCode=$this->createFoundPart($exp);
					$str.="if (\$ret)
					{
						/*\$found=true;*/
						$foundCode
					}\n";
					if ($mustMatch)
					{
						$str.="else
						{
							\$this->setSym(\$stackPointer);
							if (\$this->pred) return false;
							//if (\$this->backtracking) jump to next alternative (we don't know how to do this yet).
							throw new Exception('Error: '.array_pop(\$this->errors));
						}
						";
					}
					$str.="\n";
				}

				if ($exp->quantifier=='+' || $exp->quantifier=='*')
				{
					$str.="while(1){\n";
					$str.=$this->createCall($exp, false);
					$foundCode=$this->createFoundPart($exp);
					$str.="if (!\$ret) break;
						$foundCode
					}\n";
				}
			}
			$str.="}else{";
			if ($exp->predicated) $str.="\$this->pred--;\n";
			$str.="\$this->setSym(\$stackPointer);\n";
			if ($initialFound) $str.="}\n"; //if (\$ret)
			$str.="}"; //if (!\$found)
		}

		//TODO: make a helpful error message.
		$str.="if (\$mustMatch && !\$found){\n";
		$str.="\$info=\$this->getLastTokenInfo();\n";
		$str.="throw new Exception(\"Error: No alternative found in function '$rule->name'. \$info\");}\n";
		$str.="if (\$found){\n";
		if ($this->trimLevel==2)
			$str.="if (sizeof(\$r->tokens)==1) return \$r->tokens[0];\n";
		elseif ($this->trimLevel==1)
			$str.="if (sizeof(\$r->tokens)==1 && is_a(\$r->tokens[0],'Rule')) return \$r->tokens[0];\n";
		$str.="return \$r;\n";
		$str.="}\n";
		$str.="return \$found ? \$r : false;\n";
		$str.="}\n\n";//function block

		return $str;
	}

	/***********************************************************/
	//***************** ebnf.grammar tokenizer *****************/

	private function advanceChar()
	{
		$char=substr($this->source, $this->pos, 1);
		$this->pos++;
		return $char;
	}

	private function getToken()
	{
		while(1)
		{
			$char=$this->advanceChar();
			$source=substr($this->source,$this->pos);

			//*************************************************** eof:
			if ($char === false) return new Token("eof", "", $this->line);

			//*************************************************** comments:
			if ($char == '/')
			{
				$next = $this->advanceChar();

				if ($next == '/') //line comment
				{
					$start = $this->pos;
					while (1)
					{
						$next = $this->advanceChar();
						if ($next == "\n" || $next === false) continue 2; //return new Token('comment', substr($this->source, $start, $this->pos - 1 -$start));
					}
				}

				if ($next == '*') //block comment
				{
					$start = $this->pos;
					while (1)
					{
						$next = $this->advanceChar();

						if ($next === false)
							return new Token("syn", "Block comment past end of file.", $this->line);

						if ($next == '*')
						{
							$next = $this->advanceChar();
							if ($next === false) return new Token("syn", "Block comment past end of file.", $this->line);
							if ($next == '/') continue 2; // return new Token("comment", substr($this->source, $start, $this->pos - 2 - $start));
							$this->pos--;
						}
					}
				}
			}

			//*************************************************** whitespace:
			if (in_array($char, array("\t"," ","\r"))) continue;
			if ($char=="\n")
			{
				$this->line++;
				continue;
			}

			//*************************************************** string literals:
			if ($char=="'")//string literal:
			{
				$buf='';
				$delimiter=$char;

				while (1)
				{
					$next=$this->advanceChar();
					if ($next===false) return new Token("syn","String literal past end of file.", $this->line);

					//escape seq
					if ($delimiter=="'" && $next=='\\')
					{
						$next=$this->advanceChar(); //add to the sequence whatever it is.
						if ($next===false) return new Token("syn","String literal past end of file.", $this->line);
						$buf.=$next;
						continue;
					}

					if ($next==$delimiter)
					{
						return new Token("string",$buf,$this->line);
					}
					$buf.=$next;
				}
			}

			if (strpos(";:+*?|=@!<(){}", $char)!==false) return new Token($char,$char,$this->line);

			//check if identifier
			if ($char>='a' && $char<='z' || $char>='A' && $char<='Z' || $char=='_')
			{
				$start=$this->pos-1;
				//find anything until not in a-z A-Z 0-9 _
				while(1)
				{
					$next=substr($this->source,$this->pos,1);
					if (!($next >= 'a' && $next <= 'z' || $next >= 'A' && $next <= 'Z' || $next=='_' || $next>='0' && $next<='9') || $next===false)
					{
						//found:
						$id=substr($this->source,$start,$this->pos-$start);
						return new Token("id", $id,$this->line);
					}
					$this->pos++;
				}
			}

			//**************************** codepoint: '&' HEX+ | '#' DIGIT+ ;
			if ($char=='&' || $char=='#')
			{
				$source=substr($this->source,$this->pos);
				$matches=array();
				$pattern=$char=='&' ? "/[A-Fa-f0-9]+/" : "/[0-9]/+";
				$prefix=$char=='&' ? 'hexa' : '';
				$r=preg_match($pattern, $source,$matches);
				if (!$r) new Token("syn","Character range symbol '$char' is not followed by a {$prefix}decimal number.", $this->line);
				$this->pos+=sizeof($matches[0]);
				return new Token($char,$matches[0]);
			}

			//***************************************************find regex:
			if ($char=='~')
			{
				$buf='';
				$line=$this->line;
				while (1)
				{
					$next = $this->advanceChar();
					if ($next === false) return new Token("syn", "Regex pattern past end of file.", $line);

					//escape seq, we define only one: ~
					if ($next=='\\')
					{
						$next=$this->advanceChar();
						if ($next===false) return new Token("syn","Regex pattern past end of file.", $line);
						if ($next=='~')
						{
							$buf.="\\~";
							continue;
						}
						else
							$buf.="\\";
					}

					if ($next=='~')
					{
						if (@preg_match("~^$buf~", null)===false)
							return new Token("syn","Regex pattern is not valid.", $line);
						return new Token("regex",$buf,$line);
					}
					$buf.=$next;
				}
			}

			//nothing is found:
			return new Token("lexical_error", "'$char'",$this->line);
		}
	}
}


class Alternative{
	public $expressions=array();
}

class Exp{
	public $type;
	public $value;
	public $isTerminal;
	public $quantifier;
	public $predicated=false;
	public $unwanted=false;
	public $flatten=false;
	public $line;
}

class Rule{
	public function __construct($name=''){$this->name=$name;}
	public $isLexerRule=false;
	public $name;
	public $alternatives=array();
}

class Token
{
	public $type;
	public $value;
	public $line;

	public function __construct($type,$value,$line=null)
	{
		$this->type=$type;
		$this->value=$value;
		$this->line=$line;
	}
}

class LLPGException extends Exception
{
	public function __construct($message, $code = 0, Exception $previous = null)
	{
			parent::__construct($message, $code, $previous);
	}
}
