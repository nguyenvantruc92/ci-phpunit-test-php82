<?php
/**
 * Part of ci-phpunit-test
 *
 * @author     Kenji Suzuki <https://github.com/kenjis>
 * @license    MIT License
 * @copyright  2015 Kenji Suzuki
 * @link       https://github.com/kenjis/ci-phpunit-test
 */

use PHPUnit\Framework\TestCase;

// Support PHPUnit 6.0
if (! class_exists('PHPUnit_Framework_TestCase'))
{
	class_alias('PHPUnit\Framework\TestCase', 'PHPUnit_Framework_TestCase');
}

/**
 * @property CIPHPUnitTestRequest    $request
 * @property CIPHPUnitTestDouble     $double
 * @property CIPHPUnitTestReflection $reflection
 */
#[AllowDynamicProperties]
class CIPHPUnitTestCase extends TestCase
{
	protected $_error_reporting = -1;

	/**
	 * If you have a route with closure, PHPUnit can't serialize global variables.
	 * You would see `Exception: Serialization of 'Closure' is not allowed`.
	 *
	 * PHPUnit 9.3 and later
	 *
	 * @var string[]
	 */
	protected $backupGlobalsExcludeList = ['RTR'];

	/**
	 * Detect warnings and notices in a request output
	 *
	 * @var bool
	 */
	protected $strictRequestErrorCheck = true;

	protected $restoreErrorHandler = false;

	/**
	 * @var CI_Controller CodeIgniter instance
	 */
	protected $CI;

	protected $class_map = [
		'request'    => 'CIPHPUnitTestRequest',
		'double'     => 'CIPHPUnitTestDouble',
		'reflection' => 'CIPHPUnitTestReflection',
	];

	public function setCI(CI_Controller $CI)
	{
		$this->CI = $CI;
	}

	public function getStrictRequestErrorCheck()
	{
		return $this->strictRequestErrorCheck;
	}

	public function __get($name)
	{
		if (isset($this->class_map[$name]))
		{
			$this->$name = new $this->class_map[$name]($this);
			return $this->$name;
		}

		throw new LogicException('No such property: ' . $name);
	}

	public static function setUpBeforeClass(): void
	{
		// Fix CLI args, because you may set invalid URI characters
		// For example, when you run tests on NetBeans
		$_SERVER['argv'] = [
			'index.php',
		];
		$_SERVER['argc'] = 1;

		// Reset current directroy
		chdir(FCPATH);
	}

	public static function tearDownAfterClass(): void
	{
		CIPHPUnitTestDbConnectionStore::destory();
	}

	/**
	 * Reset CodeIgniter instance and assign new CodeIgniter instance as $this->CI
	 *
	 *  @param bool $use_my_controller
	 */
	public function resetInstance($use_my_controller = false)
	{
		reset_instance();
		CIPHPUnitTest::createCodeIgniterInstance($use_my_controller);
		$this->CI =& get_instance();
	}

	protected function tearDown(): void
	{
		$this->disableStrictErrorCheck();

		if (class_exists('MonkeyPatch', false))
		{
			if (MonkeyPatchManager::isEnabled('FunctionPatcher'))
			{
				try {
					MonkeyPatch::verifyFunctionInvocations();
				} catch (Exception $e) {
					MonkeyPatch::resetFunctions();
					throw $e;
				}

				MonkeyPatch::resetFunctions();
			}

			if (MonkeyPatchManager::isEnabled('ConstantPatcher'))
			{
				MonkeyPatch::resetConstants();
			}

			if (MonkeyPatchManager::isEnabled('MethodPatcher'))
			{
				try {
					MonkeyPatch::verifyMethodInvocations();
				} catch (Exception $e) {
					MonkeyPatch::resetMethods();
					throw $e;
				}

				MonkeyPatch::resetMethods();
			}
		}
	}

	/**
	 * Request to Controller
	 *
	 * @param string       $http_method HTTP method
	 * @param array|string $argv        array of controller,method,arg|uri
	 * @param array        $params      POST parameters/Query string
	 */
	public function request($http_method, $argv, $params = [])
	{
		return $this->request->request($http_method, $argv, $params);
	}

	/**
	 * Disable strict error check
	 */
	public function disableStrictErrorCheck()
	{
		if ($this->restoreErrorHandler) {
			restore_error_handler();
			$this->restoreErrorHandler = false;
		}
	}

	/**
	 * Enable strict error check
	 */
	public function enableStrictErrorCheck()
	{
		if ($this->restoreErrorHandler) {
			throw new LogicException('Already strict error check mode');
		}

		set_error_handler(
			function ($errno, $errstr, $errfile, $errline) {
				throw new RuntimeException($errstr . ' on line ' . $errline . ' in file ' . $errfile);
			}
		);

		$this->restoreErrorHandler = true;
	}

	/**
	 * Request to Controller using ajax request
	 *
	 * @param string       $http_method HTTP method
	 * @param array|string $argv        array of controller,method,arg|uri
	 * @param array        $params      POST parameters/Query string
	 */
	public function ajaxRequest($http_method, $argv, $params = [])
	{
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
		return $this->request($http_method, $argv, $params);
	}

	/**
	 * Get Mock Object
	 *
	 * $email = $this->getMockBuilder('CI_Email')
	 *	->setMethods(['send'])
	 *	->getMock();
	 * $email->method('send')->willReturn(TRUE);
	 *
	 *  will be
	 *
	 * $email = $this->getDouble('CI_Email', ['send' => TRUE]);
	 *
	 * @param  string $classname
	 * @param  array  $params             [method_name => return_value]
	 * @param  bool   $enable_constructor enable constructor or not
	 * @return mixed  PHPUnit mock object
	 */
	public function getDouble($classname, $params, $enable_constructor = false)
	{
		return $this->double->getDouble($classname, $params, $enable_constructor);
	}

	/**
	 * Verifies that method was called exactly $times times
	 *
	 * $loader->expects($this->exactly(2))
	 * 	->method('view')
	 * 	->withConsecutive(
	 *		['shop_confirm', $this->anything(), TRUE],
	 * 		['shop_tmpl_checkout', $this->anything()]
	 * 	);
	 *
	 *  will be
	 *
	 * $this->verifyInvokedMultipleTimes(
	 * 	$loader,
	 * 	'view',
	 * 	2,
	 * 	[
	 * 		['shop_confirm', $this->anything(), TRUE],
	 * 		['shop_tmpl_checkout', $this->anything()]
	 * 	]
	 * );
	 *
	 * @param mixed  $mock   PHPUnit mock object
	 * @param string $method
	 * @param int    $times
	 * @param array  $params arguments
	 */
	public function verifyInvokedMultipleTimes($mock, $method, $times, $params = null)
	{
		$this->double->verifyInvokedMultipleTimes(
			$mock, $method, $times, $params
		);
	}

	/**
	 * Verifies a method was invoked at least once
	 *
	 * @param mixed  $mock   PHPUnit mock object
	 * @param string $method
	 * @param array  $params arguments
	 */
	public function verifyInvoked($mock, $method, $params = null)
	{
		$this->double->verifyInvoked($mock, $method, $params);
	}

	/**
	 * Verifies that method was invoked only once
	 *
	 * @param mixed  $mock   PHPUnit mock object
	 * @param string $method
	 * @param array  $params arguments
	 */
	public function verifyInvokedOnce($mock, $method, $params = null)
	{
		$this->double->verifyInvokedOnce($mock, $method, $params);
	}

	/**
	 * Verifies that method was not called
	 *
	 * @param mixed  $mock   PHPUnit mock object
	 * @param string $method
	 * @param array  $params arguments
	 */
	public function verifyNeverInvoked($mock, $method, $params = null)
	{
		$this->double->verifyNeverInvoked($mock, $method, $params);
	}

	public function warningOff()
	{
		$this->_error_reporting = error_reporting(
			E_ALL & ~E_WARNING & ~E_NOTICE
		);
	}

	public function warningOn()
	{
		error_reporting($this->_error_reporting);
	}

	/**
	 * Asserts HTTP response code
	 *
	 * @param int $code
	 */
	public function assertResponseCode($code)
	{
		$status = $this->request->getStatus();
		$actual = $status['code'];

		$this->assertSame(
			$code,
			$actual,
			'Status code is not ' . $code . ' but ' . $actual . '.'
		);
	}

	/**
	 * Asserts HTTP response header
	 *
	 * @param string $name  header name
	 * @param string $value header value
	 */
	public function assertResponseHeader($name, $value)
	{
		$CI =& get_instance();
		$actual = $CI->output->get_header($name);

		if ($actual === null)
		{
			$this->fail("The '$name' header is not set.\nNote that `assertResponseHeader()` can only assert headers set by `\$this->output->set_header()`");
		}

		$this->assertEquals(
			$value,
			$actual,
			"The '$name' header is not '$value' but '$actual'."
		);
	}

	/**
	 * Asserts HTTP response cookie
	 *
	 * @param string       $name            cookie name
	 * @param string|array $value           cookie value|array of cookie params
	 * @param bool         $allow_duplicate whether to allow duplicated cookies
	 */
	public function assertResponseCookie($name, $value, $allow_duplicate = false)
	{
		$CI =& get_instance();
		$cookies = isset($CI->output->_cookies[$name])
			? $CI->output->_cookies[$name] : null;

		if ($cookies === null)
		{
			$this->fail("The cookie '$name' is not set.\nNote that `assertResponseCookie()` can only assert cookies set by `\$this->input->set_cookie()`");
		}

		$count = count($cookies);
		if ($count > 1 && ! $allow_duplicate)
		{
			$values = [];
			foreach ($cookies as $key => $val)
			{
				$values[] = "'{$val['value']}'";
			}
			$values = implode(' and ', $values);
			$this->fail("You have more than one cookie '$name'. The values are $values.\nIf it is okay, please set `true` as the 3rd argument of `assertResponseCookie()`");
		}

		// Get the last cookie
		$cookie = $cookies[$count - 1];
		if (is_string($value))
		{
			$this->assertEquals(
				$value,
				$cookie['value'],
				"The cookie '$name' value is not '$value' but '{$cookie['value']}'."
			);
			return;
		}

		// In case of $this->anything()
		if (
			$value instanceof PHPUnit_Framework_Constraint_IsAnything
			|| $value instanceof PHPUnit\Framework\Constraint\IsAnything
		)
		{
			$this->assertTrue(true);
			return;
		}

		foreach ($value as $key => $val)
		{
			$this->assertEquals(
				$value[$key],
				$cookie[$key],
				"The cookie '$name' $key is not '{$value[$key]}' but '{$cookie[$key]}'."
			);
		}
	}

	/**
	 * Asserts Redirect
	 *
	 * @param string $uri  URI to redirect
	 * @param int    $code response code
	 */
	public function assertRedirect($uri, $code = null)
	{
		$status = $this->request->getStatus();

		if ($status['redirect'] === null)
		{
			$this->fail('redirect() is not called.');
		}

		if (! function_exists('site_url'))
		{
			$CI =& get_instance();
			$CI->load->helper('url');
		}

		if (! preg_match('#^(\w+:)?//#i', $uri))
		{
			$uri = site_url($uri);
		}
		$absolute_url = $uri;
		$expected = 'Redirect to ' . $absolute_url;

		$this->assertSame(
			$expected,
			$status['redirect'],
			'URL to redirect is not ' . $expected . ' but ' . $status['redirect'] . '.'
		);

		if ($code !== null)
		{
			$this->assertSame(
				$code,
				$status['code'],
				'Status code is not ' . $code . ' but ' . $status['code'] . '.'
			);
		}
	}

	/**
	 * Asserts the message is logged
	 *
	 * @param string $level
	 * @param string $message
	 */
	public function assertLogged($level, $message)
	{
		$result = CIPHPUnitTestLogger::didLog($level, $message);
		$this->assertTrue($result);
	}

	/**
	 * Testing Environment or not?
	 *
	 * @return bool
	 */
	public static function isTestingEnv()
	{
		return (ENVIRONMENT === 'testing');
	}
}
