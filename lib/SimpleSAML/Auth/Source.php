<?php

/**
 * This class defines a base class for authentication source.
 *
 * An authentication source is any system which somehow authenticate the user.
 *
 * @author Olav Morken, UNINETT AS.
 * @package simpleSAMLphp
 * @version $Id$
 */
abstract class SimpleSAML_Auth_Source {


	/**
	 * The authentication source identifier.
	 *
	 * This identifier can be used to look up this object, for example when returning from a login form.
	 */
	protected $authId;


	/**
	 * Constructor for an authentication source.
	 *
	 * Any authentication source which implements its own constructor must call this
	 * constructor first.
	 *
	 * @param array $info  Information about this authentication source.
	 * @param array &$config  Configuration for this authentication source.
	 */
	public function __construct($info, &$config) {
		assert('is_array($info)');
		assert('is_array($config)');

		assert('array_key_exists("AuthId", $info)');
		$this->authId = $info['AuthId'];
	}


	/**
	 * Process a request.
	 *
	 * If an authentication source returns from this function, it is assumed to have
	 * authenticated the user, and should have set elements in $state with the attributes
	 * of the user.
	 *
	 * If the authentication process requires additional steps which make it impossible to
	 * complete before returning from this function, the authentication source should
	 * save the state, and at a later stage, load the state, update it with the authentication
	 * information about the user, and call completeAuth with the state array.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	abstract public function authenticate(&$state);


	/**
	 * Complete authentication.
	 *
	 * This function should be called if authentication has completed. It will never return,
	 * except in the case of exceptions. Exceptions thrown from this page should not be caught,
	 * but should instead be passed to the top-level exception handler.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	public static function completeAuth(&$state) {
		assert('is_array($state)');
		assert('array_key_exists("LoginCompletedHandler", $state)');

		SimpleSAML_Auth_State::deleteState($state);

		$func = $state['LoginCompletedHandler'];
		assert('is_callable($func)');

		call_user_func($func, $state);
		assert(FALSE);
	}


	/**
	 * Log out from this authentication source.
	 *
	 * This function should be overridden if the authentication source requires special
	 * steps to complete a logout operation.
	 *
	 * If the logout process requires a redirect, the state should be saved. Once the
	 * logout operation is completed, the state should be restored, and completeLogout
	 * should be called with the state. If this operation can be completed without
	 * showing the user a page, or redirecting, this function should return.
	 *
	 * @param array &$state  Information about the current logout operation.
	 */
	public function logout(&$state) {
		assert('is_array($state)');

		/* Default logout handler which doesn't do anything. */
	}


	/**
	 * Complete logout.
	 *
	 * This function should be called after logout has completed. It will never return,
	 * except in the case of exceptions. Exceptions thrown from this page should not be caught,
	 * but should instead be passed to the top-level exception handler.
	 *
	 * @param array &$state  Information about the current authentication.
	 */
	public static function completeLogout(&$state) {
		assert('is_array($state)');
		assert('array_key_exists("LogoutCompletedHandler", $state)');

		SimpleSAML_Auth_State::deleteState($state);

		$func = $state['LogoutCompletedHandler'];
		assert('is_callable($func)');

		call_user_func($func, $state);
		assert(FALSE);
	}


	/**
	 * Create authentication source object from configuration array.
	 *
	 * This function takes an array with the configuration for an authentication source object,
	 * and returns the object.
	 *
	 * @param string $authId  The authentication source identifier.
	 * @param array $config  The configuration.
	 * @return SimpleSAML_Auth_Source  The parsed authentication source.
	 */
	private static function parseAuthSource($authId, $config) {
		assert('is_string($authId)');
		assert('is_array($config)');

		if (!array_key_exists(0, $config) || !is_string($config[0])) {
			throw new Exception('Invalid authentication source \'' . $authId .
				'\': First element must be a string which identifies the authentication source.');
		}

		$className = SimpleSAML_Module::resolveClass($config[0], 'Auth_Source',
			'SimpleSAML_Auth_Source');

		$info = array('AuthId' => $authId);
		unset($config[0]);
		return new $className($info, $config);
	}


	/**
	 * Retrieve authentication source.
	 *
	 * This function takes an id of an authentication source, and returns the
	 * AuthSource object.
	 *
	 * @param string $authId  The authentication source identifier.
	 * @return SimpleSAML_Auth_Source|NULL  The AuthSource object, or NULL if no authentication
	 *     source with the given identifier is found.
	 */
	public static function getById($authId) {
		assert('is_string($authId)');

		/* For now - load and parse config file. */
		$config = SimpleSAML_Configuration::getConfig('authsources.php');

		$authConfig = $config->getValue($authId, NULL);
		if ($authConfig === NULL) {
			return NULL;
		}

		if (!is_array($authConfig)) {
			throw new Exception('Invalid configuration for authentication source \'' . $authId . '\'.');
		}

		return self::parseAuthSource($authId, $authConfig);
	}


	/**
	 * Add a logout callback association.
	 *
	 * This function adds a logout callback association, which allows us to initiate
	 * a logout later based on the $assoc-value.
	 *
	 * Note that logout-associations exists per authentication source. A logout association
	 * from one authentication source cannot be called from a different authentication source.
	 *
	 * @param string $assoc  The identifier for this logout association.
	 * @param array $state  The state array passed to the authenticate-function.
	 */
	protected function addLogoutCallback($assoc, $state) {
		assert('is_string($assoc)');
		assert('is_array($state)');

		if (!array_key_exists('LogoutCallback', $state)) {
			/* The authentication requester doesn't have a logout callback. */
			return;
		}
		$callback = $state['LogoutCallback'];

		if (array_key_exists('LogoutCallbackState', $state)) {
			$callbackState = $state['LogoutCallbackState'];
		} else {
			$callbackState = array();
		}

		$id = strlen($this->authId) . ':' . $this->authId . $assoc;

		$data = array(
			'callback' => $callback,
			'state' => $callbackState,
			);


		$session = SimpleSAML_Session::getInstance();
		$session->setData('SimpleSAML_Auth_Source.LogoutCallbacks', $id, $data,
			SimpleSAML_Session::DATA_TIMEOUT_LOGOUT);
	}


	/**
	 * Call a logout callback based on association.
	 *
	 * This function calls a logout callback based on an association saved with
	 * addLogoutCallback(...).
	 *
	 * This function always returns.
	 *
	 * @param string $assoc  The logout association which should be called.
	 */
	protected function callLogoutCallback($assoc) {
		assert('is_string($assoc)');

		$id = strlen($this->authId) . ':' . $this->authId . $assoc;

		$session = SimpleSAML_Session::getInstance();

		$data = $session->getData('SimpleSAML_Auth_Source.LogoutCallbacks', $id);
		if ($data === NULL) {
			return;
		}

		assert('is_array($data)');
		assert('array_key_exists("callback", $data)');
		assert('array_key_exists("state", $data)');

		$callback = $data['callback'];
		$callbackState = $data['state'];

		call_user_func($callback, $callbackState);
	}

}

?>