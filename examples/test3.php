<?php

class Parser{

	private $source;
	private $pos=0;
	private $line=1;
	private $curToken=null;
	public $startRule=null;

	private $pred=0;
	private $backtracking=0; //implemented in generated code but not in ebnf.grammar, so its always 0.
	private $stackPointer=-1;
	private $tokens=array();

	private $errors=array();
	private $lexerRules;

	const LEXER_RULES_JSON=<<<'LEXER_RULES_JSON'
[{"n":"id","a":[{"t":"regex","v":"[a-zA-Z_][a-zA-Z0-9_]*"}]},{"n":"ignore","a":[{"t":"regex","v":"[\\s]*"},{"t":"regex","v":"\/\/[^\\n]+\\n"},{"t":"regex","v":"\/\\*(.|\\n)*?\\*\/"}]},{"n":"int","a":[{"t":"regex","v":"[0-9]+"}]},{"n":"float","a":[{"t":"regex","v":"[0-9]*\\.[0-9]+"}]},{"n":"S:=","a":[{"t":"string","v":"="}]},{"n":"S:+","a":[{"t":"string","v":"+"}]},{"n":"S:-","a":[{"t":"string","v":"-"}]},{"n":"S:*","a":[{"t":"string","v":"*"}]},{"n":"S:\/","a":[{"t":"string","v":"\/"}]}]
LEXER_RULES_JSON;

	public function parse($str)
	{
		$this->source=$str;
		$this->lexerRules=json_decode(self::LEXER_RULES_JSON);

		do{
			$t=$this->getToken();
			if ($t->type!='ignore')
				$this->tokens[]=$t;
		} while($t->type!=':EOF');

		$this->getSym();
		$this->startRule=$this->parse_program(false);
		return $this->startRule;
	}

	private function getSym()
	{
		$this->stackPointer++;
		$this->curToken=$this->tokens[$this->stackPointer];
		return $this->curToken;
	}

	private function setSym($pointer)
	{
		$this->stackPointer=$pointer;
		$this->curToken=$this->tokens[$this->stackPointer];
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
		{
			if (substr($tokenType,0,2)=='S:')
				$expected="string '".substr($tokenType,2)."'";
			elseif (substr($tokenType,0,2)=='R:')
				$expected="an expression matching the pattern '".substr($tokenType,2)."'";
			else
				$expected="'$tokenType'";

			if (substr($this->curToken->type,0,2)=='S:')
				$found="string '".substr($this->curToken->type,2)."'";
			elseif (substr($this->curToken->type,0,2)=='R:')
				$found="an expression matching the pattern '".substr($this->curToken->type,2)."'";
			else
				$found="'{$this->curToken->type}'";

			$this->errors[]="Unexpected token. Expected $expected but found $found with value '{$this->curToken->value}'. Line {$this->curToken->line}.";
		}
		return false;
	}


	/******************************************************************/
	/** Following section is auto generated by the parser generator: **/
									
			private function parse_program($mustMatch){
				$found=false;$r=new Rule('program');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('id',false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
$ret=$this->accept('S:=',  true);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}
else
						{
							$this->setSym($stackPointer);
							if ($this->pred) return false;
							//if ($this->backtracking) jump to next alternative (we don't know how to do this yet).
							throw new Exception('Error: '.array_pop($this->errors));
						}
						
$ret=$this->parse_expression(true);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}
else
						{
							$this->setSym($stackPointer);
							if ($this->pred) return false;
							//if ($this->backtracking) jump to next alternative (we don't know how to do this yet).
							throw new Exception('Error: '.array_pop($this->errors));
						}
						
$ret=$this->accept(':EOF', true);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}
else
						{
							$this->setSym($stackPointer);
							if ($this->pred) return false;
							//if ($this->backtracking) jump to next alternative (we don't know how to do this yet).
							throw new Exception('Error: '.array_pop($this->errors));
						}
						
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'program'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_expression($mustMatch){
				$found=false;$r=new Rule('expression');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->parse_addexp(false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
$ret=$this->parse_subRule_2(false);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}

while(1){
$ret=$this->parse_subRule_2(false);
if (!$ret) break;
						$r->tokens[]=$ret;

					}
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'expression'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_addexp($mustMatch){
				$found=false;$r=new Rule('addexp');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->parse_multexp(false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
$ret=$this->parse_subRule_4(false);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}

while(1){
$ret=$this->parse_subRule_4(false);
if (!$ret) break;
						$r->tokens[]=$ret;

					}
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'addexp'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_multexp($mustMatch){
				$found=false;$r=new Rule('multexp');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('int',false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
}else{$this->setSym($stackPointer);
}
}if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('float',false);
if ($ret) {
$found=true;
$r->index=1;
$r->tokens[]=$ret;
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'multexp'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_subRule_1($mustMatch){
				$found=false;$r=new Rule('subRule_1');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('S:+',  false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
}else{$this->setSym($stackPointer);
}
}if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('S:-',  false);
if ($ret) {
$found=true;
$r->index=1;
$r->tokens[]=$ret;
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'subRule_1'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_subRule_2($mustMatch){
				$found=false;$r=new Rule('subRule_2');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->parse_subRule_1(false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
$ret=$this->parse_addexp(true);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}
else
						{
							$this->setSym($stackPointer);
							if ($this->pred) return false;
							//if ($this->backtracking) jump to next alternative (we don't know how to do this yet).
							throw new Exception('Error: '.array_pop($this->errors));
						}
						
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'subRule_2'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_subRule_3($mustMatch){
				$found=false;$r=new Rule('subRule_3');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('S:*',  false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
}else{$this->setSym($stackPointer);
}
}if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->accept('S:/',  false);
if ($ret) {
$found=true;
$r->index=1;
$r->tokens[]=$ret;
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'subRule_3'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


			private function parse_subRule_4($mustMatch){
				$found=false;$r=new Rule('subRule_4');

			if (!$found){
$stackPointer=$this->stackPointer;
$ret=$this->parse_subRule_3(false);
if ($ret) {
$found=true;
$r->index=0;
$r->tokens[]=$ret;
$ret=$this->parse_multexp(true);
if ($ret)
					{
						/*$found=true;*/
						$r->tokens[]=$ret;

					}
else
						{
							$this->setSym($stackPointer);
							if ($this->pred) return false;
							//if ($this->backtracking) jump to next alternative (we don't know how to do this yet).
							throw new Exception('Error: '.array_pop($this->errors));
						}
						
}else{$this->setSym($stackPointer);
}
}if ($mustMatch && !$found){
$info=$this->getLastTokenInfo();
throw new Exception("Error: No alternative found in function 'subRule_4'. $info");}
if ($found){
return $r;
}
return $found ? $r : false;
}


	/****************** End of auto generated section: ****************/
	/******************************************************************/

	private function getToken()
	{
		if (strlen($this->source)==$this->pos) return new Token (':EOF','EOF', $this->line);
		$match=array("type"=>'', "value"=>'');
		$source=substr($this->source,$this->pos);

		foreach ($this->lexerRules as $lexerRule)
		{
			$name=$lexerRule->n;
			foreach ($lexerRule->a as $e)
			{
				if ($e->t=='regex')
				{
					$r=preg_match("~^$e->v~", $source, $matches);
					if ($r && strlen($matches[0])>strlen($match["value"]))
						$match=array("type"=>"$name","value"=>$matches[0]);
				}
			}
		}

		foreach ($this->lexerRules as $lexerRule)
		{
			$name=$lexerRule->n;
			foreach ($lexerRule->a as $e)
			{
				if ($e->t=='string')
				{
					if (strpos($source, $e->v)===0)
					{
						if (strlen($e->v)>strlen($match["value"]) || $e->v==$match["value"])
							$match=array("type"=>"$name","value"=>$e->v);
					}
				}
			}
		}

		//if a match is found, create token and return:
		if ($match['value']!=='')
		{
			$t=new Token($match['type'],$match['value'],$this->line);
			$this->pos+=strlen($match["value"]);
			$this->line+=substr_count($match["value"], "\n");
			return $t;
		}

		$lastChar=substr($source, 0, 1);
		throw new Exception("Error: Lexical error. Input stream did not match any of the language constructs.
			'$lastChar' is the first character that is not recognized. Line $this->line.");

	}

	private function getLastTokenInfo()
	{
		$type=$this->curToken->type;
		$val=$this->curToken->value;
		$line=$this->curToken->line;
		return "Offending token is on line: $line, of type: '$type', has value: '$val'.";
	}
}


//Parse tree constructs.
class Rule{

	public $name;
	public $index;
	public $tokens=array();

	public function __construct($name)
	{
		$this->name=$name;
	}
}

class Token
{
	public $type;
	public $value;
	public $line;

	public function __construct($type,$value,$line)
	{
		$this->type=$type;
		$this->value=$value;
		$this->line=$line;
	}
}
