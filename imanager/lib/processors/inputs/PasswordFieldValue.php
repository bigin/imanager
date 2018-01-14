<?php namespace Imanager;

class PasswordFieldValue
{
	/**
	 * @var Password value
	 */
	public $password;

	/**
	 * @var Salt value
	 */
	public $salt;

	/**
	 * This static method is called for complex field values
	 *
	 * @param $an_array
	 *
	 * @return PasswordFieldValue object
	 */
	public static function __set_state($an_array)
	{
		$_instance = new PasswordFieldValue();
		foreach($an_array as $key => $val) {
			if(is_array($val)) $_instance->{$key} = $val;
			else $_instance->{$key} = $val;
		}
		return $_instance;
	}

	/**
	 * Compares password with input $enteredPass
	 *
	 * @param $enteredPass
	 *
	 * @return bool
	 */
	public function compare($enteredPass)
	{
		$enterdHash = sha1($enteredPass.$this->salt);
		if($enterdHash === $this->password) return true;
		return false;
	}
}