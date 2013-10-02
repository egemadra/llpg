<?php

class Parser{

	private $source;
	private $pos=0;
	private $line=1;
	private $curToken=null;
	private $startRule=null;

	private $trimLevel;

	private $pred=0;
	private $backtracking=0; //implemented in generated code but not in ebnf.grammar, so its always 0.
	private $stackPointer=-1;
	private $tokens=array();

	private $errors=array();
	private $lexerRules=array();

	//driver specific. $allRules but because deserialized from json, has objects instead of arrays:
	private $ruleData;


	public function parse($str, $jsonData, $trimLevel=1)
	{
		//driver specific:
		$this->ruleData=json_decode($jsonData);
		$this->trimLevel=$trimLevel;

		$this->source=$str;
		foreach ($this->ruleData as $r)
		{
			if ($r->isLexerRule)
				$this->lexerRules[$r->name]=$r;
		}
		//$this->lexerRules=json_decode(file_get_contents("../lexerRules.json"));

		do{
			$t=$this->getToken();
			if ($t->type!='ignore')
				$this->tokens[]=$t;
		} while($t->type!=':EOF');

		$this->getSym();

		//driver specific:
		$this->startRule=$this->drive('program',false);
		if (!$this->startRule)
			exit("Error: Program expected.");

		return $this->startRule;
	}


	private function makeCall($exp, $mustMatch)
	{
		switch($exp->type)
		{
			case 'string':
				$ret=$this->accept("S:$exp->value",  $mustMatch); break;
			case 'id':
				$ruleName=$exp->value;
				$rule=$this->ruleData->$ruleName;
				if ($rule->isLexerRule)
					$ret=$this->accept($rule->name,$mustMatch);
				else
					$ret=$this->drive($exp->value,$mustMatch);
				break;
			case 'alternatives':
				$ret=$this->drive($exp->value,$mustMatch);
				break;
			case ':EOF':
				$ret=$this->accept(':EOF', $mustMatch); break;
			case 'regex':
				$ret=$this->accept("R:$exp->value", $mustMatch); break;
			default:
				exit('unidentified exp type.');
		}

		return $ret;
	}

	private function processFoundPart($exp, $rule, $ret)
	{
		if (!$exp->unwanted)
		{
			if (!$exp->flatten)
				$rule->tokens[]=$ret;
			else
			{
				if (is_a($ret,'Rule'))
					foreach ($ret->tokens as $t)
						$rule->tokens[]=$t;
				else
					$rule->tokens[]=$ret;;
			}
		}
		//else token was unwanted, not added.
	}

	private function drive($ruleName, $mustMatch)
	{
		$found = false;
		$r = new Rule($ruleName);

		$base=$this->ruleData->$ruleName;

		foreach ($base->alternatives as $altIndex=>$a)
		{
			$initial=false;
			$initialFound=false;
			$stackPointer=$this->stackPointer;

			foreach ($a->expressions as $exp)
			{
				$initial=!$initialFound && ($exp->quantifier=='1'||$exp->quantifier=='+');
				if ($initial) $initialFound=true;
				if ($initial)
				{
					if ($exp->predicated) $this->pred++;
					$ret=$this->makeCall($exp, false);

					if ($ret)
					{
						if ($exp->predicated) $this->pred--;
						$found=true;
						$r->index=$altIndex;
						$this->processFoundPart($exp, $r, $ret);
					}
					else
					{
						if ($exp->predicated) $this->pred--;
						$this->setSym($stackPointer);
						break;
					}
				}
				else //not initial
				{
					$secondaryMustMatch=$exp->quantifier=='1' || $exp->quantifier=='+';
					$ret=$this->makeCall($exp, $secondaryMustMatch);
					if (!$ret)
					{
						if ($secondaryMustMatch)
						{
							$this->setSym($stackPointer);
							if ($this->pred) return false;
							exit("Error: ".array_pop($this->errors));
						}
					}
					else
						$this->processFoundPart($exp, $r, $ret);
				}

				//common in both initial and not initial: loops:
				if ($ret)
					if ($exp->quantifier=='+' || $exp->quantifier=='*')
					{
						while(1)
						{
							$ret=$this->makeCall($exp, false);
							if (!$ret) break;
							$this->processFoundPart($exp, $r, $ret);
						}
					}
			}

			if ($found)
			{
				//shortens fully:
				if ($this->trimLevel==2 && sizeof($r->tokens)==1) return $r->tokens[0];
				//leaves one parent rule:
				if ($this->trimLevel==1 && sizeof($r->tokens)==1 && is_a($r->tokens[0],'Rule')) return $r->tokens[0];
				return $r;
			}
		}

		//not found:
		//TODO: make a helpful error message.
		if ($mustMatch)
		{
			$info=$this->getLastTokenInfo();
			exit("Error: No alternative found in function '$ruleName'. $info");
		}

		return false;
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

	private function getToken()
	{
		if (strlen($this->source)==$this->pos) return new Token (':EOF','EOF', $this->line);
		$match=array("type"=>'', "value"=>'');
		$source=substr($this->source,$this->pos);

		foreach ($this->lexerRules as $name=>$lexerRule)
		{
			foreach ($lexerRule->alternatives[0]->expressions as $e)
			{
				if ($e->type=='regex')
				{
					$matches=array();
					$r=preg_match("~^$e->value~", $source, $matches);
					if ($r && strlen($matches[0])>strlen($match["value"]))
						$match=array("type"=>"$name","value"=>$matches[0]);
				}
			}
		}

		foreach ($this->lexerRules as $name=>$lexerRule)
		{
			foreach ($lexerRule->alternatives[0]->expressions as $e)
			{
				if ($e->type=='string')
				{
					$value=$e->value;
					if (strpos($source, $value)===0)
					{
						if (strlen($value)>strlen($match["value"]) || $value==$match["value"])
							$match=array("type"=>"$name","value"=>$value);
					}
				}
			}
		}

		/*
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
		*/

		//if a match is found, create token and return:
		if ($match['value']!=='')
		{
			$t=new Token($match['type'],$match['value'],$this->line);
			$this->pos+=strlen($match["value"]);
			$this->line+=substr_count($match["value"], "\n");
			return $t;
		}

		$lastChar=substr($source, 0, 1);
		exit("Error: Lexical error. Input stream did not match any of the language constructs.
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

	public function __construct($type,$value,$line=null)
	{
		$this->type=$type;
		$this->value=$value;
		$this->line=$line;
	}
}

