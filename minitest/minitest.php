<?php
/**
 * Akeeba Engine
 *
 * @package   akeebaengine
 * @copyright Copyright (c)2006-2020 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

use Akeeba\Engine\Postproc\Connector\S3v4\Configuration;
use Akeeba\Engine\Postproc\Connector\S3v4\Connector;
use Akeeba\Engine\Postproc\Connector\S3v4\Input;

// Necessary for including the library
define('AKEEBAENGINE', 1);

if (!file_exists(__DIR__ . '/../vendor/autoload.php'))
{
	die ('Please run composer install before running the mini test suite.');
}

// Use Composer's autoloader to load the library
/** @var \Composer\Autoload\ClassLoader $autoloader */
$autoloader = require_once(__DIR__ . '/../vendor/autoload.php');

// Add the minitest PSR-4 path map to Composer's autoloader
$autoloader->addPsr4('Akeeba\\MiniTest\\', __DIR__);

function getAllTestClasses()
{
	static $testClasses = array();

	if (!empty($testClasses))
	{
		return $testClasses;
	}

	$folder = __DIR__ . '/Test';
	$di     = new DirectoryIterator($folder);

	foreach ($di as $entry)
	{
		if ($entry->isDot() || !$entry->isFile())
		{
			continue;
		}

		$baseName  = $entry->getBasename('.php');
		$className = '\\Akeeba\\MiniTest\\Test\\' . $baseName;

		if (!class_exists($className))
		{
			continue;
		}

		$reflectedClass = new ReflectionClass($className);

		if ($reflectedClass->isAbstract())
		{
			continue;
		}

		$testClasses[] = $className;
	}

	return $testClasses;
}

function getTestMethods($className)
{
	static $classMethodMap = array();

	if (isset($classMethodMap[$className]))
	{
		return $className;
	}

	$classMethodMap[$className] = array();

	if (!class_exists($className))
	{
		return $classMethodMap[$className];
	}

	$reflectedClass = new ReflectionClass($className);
	$methods        = $reflectedClass->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

	$classMethodMap[$className] = array_map(function (ReflectionMethod $refMethod) {
		return $refMethod->getName();
	}, $methods);

	return $classMethodMap[$className];
}

if (!file_exists(__DIR__ . '/config.php'))
{
	die ('Please rename config.dist.php to config.php and customise it before running the mini test suite.');
}

require __DIR__ . '/config.php';

global $testConfigurations;

$broken     = 0;
$failed     = 0;
$successful = 0;

foreach ($testConfigurations as $description => $setup)
{
	echo "▶ " . $description . PHP_EOL;
	echo str_repeat('〰', 80) . PHP_EOL . PHP_EOL;

	// Extract the configuration options
	if (!isset($setup['configuration']))
	{
		$setup['configuration'] = array();
	}

	$configOptions = array_merge(array(
		'access'      => DEFAULT_ACCESS_KEY,
		'secret'      => DEFAULT_SECRET_KEY,
		'region'      => DEFAULT_REGION,
		'bucket'      => DEFAULT_BUCKET,
		'signature'   => DEFAULT_SIGNATURE,
		'dualstack'   => DEFAULT_DUALSTACK,
		'path_access' => DEFAULT_PATH_ACCESS,
	), $setup['configuration']);

	// Extract the test classes/methods to run
	if (!isset($setup['tests']))
	{
		$setup['tests'] = getAllTestClasses();
	}

	$tests = $setup['tests'];

	if (!is_array($tests) || (is_array($tests) && in_array('*', $tests)))
	{
		$tests = getAllTestClasses();
	}

	// Create the S3 configuration object
	$s3Configuration = new Configuration($configOptions['access'], $configOptions['secret'], $configOptions['signature'], $configOptions['region']);
	$s3Configuration->setUseDualstackUrl($configOptions['dualstack']);
	$s3Configuration->setUseLegacyPathStyle($configOptions['path_access']);

	// Create the connector object
	$s3Connector = new Connector($s3Configuration);

	// Run the tests
	foreach ($tests as $testInfo)
	{
		if (!is_array($testInfo))
		{
			$className = $testInfo;

			if (!class_exists($className))
			{
				$className = '\\Akeeba\\MiniTest\\Test\\' . $className;
			}

			if (!class_exists($className))
			{
				$broken++;
				echo "  ⁉️ Test class {$className} not found." . PHP_EOL;

				continue;
			}

			$testInfo = array_map(function ($method) use ($className) {
				return array($className, $method);
			}, getTestMethods($className));
		}

		foreach ($testInfo as $callable)
		{
			list($className, $method) = $callable;

			if (!class_exists($className))
			{
				$broken++;
				echo "  ⁉️ Test class {$className} not found." . PHP_EOL;

				continue;
			}

			if (!method_exists($className, $method))
			{
				$broken++;
				echo "  ⁉️ Method {$method} not found in test class {$className}." . PHP_EOL;

				continue;
			}

			echo "  ⏱ {$className}:{$method}…";
			$errorException = null;

			try
			{
				$result = call_user_func($className, $s3Connector, $configOptions);
			}
			catch (Exception $e)
			{
				$result         = false;
				$errorException = $e;
			}

			echo "\r  " . ($result ? '✔' : '🚨') . " {$className}:{$method}  " . PHP_EOL;

			if ($result)
			{
				$successful++;
				continue;
			}

			$failed++;

			if (is_null($errorException))
			{
				echo "    Returned false" . PHP_EOL;

				continue;
			}

			echo "    {$errorException->getCode()} – {$errorException->getMessage()}" . PHP_EOL;
			echo "    {$errorException->getFile()}({$errorException->getLine()})" . PHP_EOL . PHP_EOL;

			$errorLines = explode("\n", $errorException->getTraceAsString());

			foreach ($errorLines as $line)
			{
				echo "    $line" . PHP_EOL;
			}
		}
	}
}

echo PHP_EOL . PHP_EOL;

echo "Summary:" . PHP_EOL;
echo "  Broken     : $broken" . PHP_EOL;
echo "  Failed     : $failed" . PHP_EOL;
echo "  Successful : $successful" . PHP_EOL . PHP_EOL;

echo "Conclusion: " . PHP_EOL . "  ";

if ($failed > 0)
{
	echo "❌ FAILED 😭😭😭" . PHP_EOL;

	exit(1);
}

if ($successful === 0)
{
	echo "🔥 No tests executed! 🤪" . PHP_EOL;

	exit (3);
}

if ($broken > 0)
{
	echo "⁉️ SUCCESS but some tests are broken 🤦" . PHP_EOL;

	exit (2);
}

echo "✅ PASSED" . PHP_EOL;

exit(0);

function uploadSmall(Connector $s3, $localFile, $bucket, $remoteFile)
{
	$fileInput = Input::createFromFile($localFile);

	$s3->putObject($fileInput, $bucket, $remoteFile);
}

function uploadBig(Connector $s3, $localFile, $bucket, $remoteFile)
{
	$fileInput = Input::createFromFile($localFile);

	$s3->uploadMultipart($fileInput, $bucket, $remoteFile);
}

function downloadAndVerifySmall(Connector $s3, $localFile, $bucket, $remoteFile)
{
	$rawData = file_get_contents($localFile);
	$result  = $s3->getObject($bucket, $remoteFile, false);

	return $result === $rawData;
}

function downloadAndVerifyBig(Connector $s3, $localFile, $bucket, $remoteFile)
{
	$tempPath = tempnam(__DIR__, 'as3');
	$s3->getObject($bucket, $remoteFile, $tempPath);

	$localHash  = hash_file('sha256', $localFile);
	$remoteHash = hash_file('sha256', $tempPath);

	@unlink($tempPath);

	return $localHash === $remoteHash;
}

