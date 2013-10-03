#!/usr/bin/php
<?php

/*
 * llpg command line utility.
 * MIT licence, © Ege Madra, October 2013 .
 */

function help()
{
	global $argv;

	echo
"LL Parser Generator for PHP.

Usage: {$argv[0]} ...options... grammar_file

If both -p and -j options are missing it will be considered a dry run and there
will be no output file. Useful for validating your grammar.

Example: {$argv[0]} -p './myparser.php' -j 'myparserdata.json' -t 1 'my.grammar'

This would attempt produce both versions of the parser for the the grammar
defined in file 'my.grammar'. If succeeds, the driverless one will be formatted
and it will have the trim level of 1.

Options:

-p parser_file: parser_file is the path to a php file that llpg will output.
This is a single php file that is able to parse the grammar defined in
grammar_file. Has no default value.

-j json_file: json_file is the path to a json file that llpg will output to
be used by the driving version of the generator. Has no default value.

-t trim_level: trim_level is an integer that can be 0, 1 or 2. Please see
the documentation for the meaning of it. If left unspecified it is defaulted to
0.

-f: Must be used with -p option. If set, generated parser code in the
parser_filewill be formatted. Generator is much faster when it doesn't have to
format the php code.

-h: Prints this text.

-v: Prints version info.

";
	exit();
}

function exitError($msg,$dontExit=false)
{
	$fh = fopen('php://stderr','a');
	fwrite($fh,"$msg\n\n");
	fclose($fh);
	if (!$dontExit) exit();
}

$pathToLLPG=pathinfo(__FILE__,PATHINFO_DIRNAME).DIRECTORY_SEPARATOR.'LLPG.class.php';
if (!is_readable($pathToLLPG)) exitError("Error: '$pathToLLPG' does not exist or not readable.");

require_once $pathToLLPG;

$opts=getopt("p:j:t:fvh");
if (isset($opts['h'])) help();
if (isset($opts['v'])) exit("LLPG -v ".LLPG::getVersion().".\n\n");

//http://php.net/manual/en/function.getopt.php
$parameters = array(
  'f'		=> 'format',
  'p:'	=> 'parser_file:',
	'j:'	=> 'json_file:',
	't:'	=> 'trim_level:',
);

$options = getopt(implode('', array_keys($parameters)), $parameters);
$pruneargv = array();
foreach ($options as $option => $value) {
  foreach ($argv as $key => $chunk) {
    $regex = '/^'. (isset($option[1]) ? '--' : '-') . $option . '/';
    if ($chunk == $value && $argv[$key-1][0] == '-' || preg_match($regex, $chunk)) {
      array_push($pruneargv, $key);
    }
  }
}
while ($key = array_pop($pruneargv)) unset($argv[$key]);

if (sizeof($argv)!==2) exitError("Error: Illegal or wrong number of options. Please type \"{$argv[0]} -h\" for help.");

foreach ($opts as $key=>$val)
	$$key=$val;

$format=isset($options['f']);
$grammarFile=end($argv);
$trimLevel=isset($t) ? $t : 0;

$parser=new LLPG();

try{
	$parser->parseFile($grammarFile, $trimLevel);

	if (isset($p))
		$parser->outputParser($p, $format);

	if (isset($j))
		$parser->outputJSON($j);
}
catch (LLPGException $e)
{
	exitError($e->getMessage());
}

$warnings=$parser->getWarnings();
if (sizeof($warnings))
	foreach ($warnings as $w)
		exitError($w,true);
