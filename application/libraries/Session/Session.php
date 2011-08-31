<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * CodeIgniter
 *
 * An open source application development framework for PHP 5.1.6 or newer
 *
 * @package		CodeIgniter
 * @author		ExpressionEngine Dev Team
 * @copyright	Copyright (c) 2008 - 2010, EllisLab, Inc.
 * @license		http://codeigniter.com/user_guide/license.html
 * @link		http://codeigniter.com
 * @since		Version 2.0
 * @filesource
 */


/**
 * Session Class
 *
 * The user interface defined by EllisLabs, now with puggable drivers to manage different storage mechanisms.
 * By default, the Native PHP session driver will load, but the 'sess_driver' config/param item (see above) can be
 * used to specify the 'Cookie' driver, or any other you might create.
 * Once loaded, this driver setup is a drop-in replacement for the former CI_Session library, taking its place as the
 * 'session' member of the global controller framework (e.g.: $CI->session or $this->session).
 * In keeping with the CI_Driver methodology, multiple drivers may be loaded, although this might be a bit confusing.
 * The Session library class keeps track of the most recently loaded driver as "current" to call for driver methods.
 * Ideally, one driver is loaded and all calls go directly through the main library interface. However, any methods
 * called through the specific driver will switch the "current" driver to itself before invoking the library method
 * (which will then call back into the driver for low-level operations). So, alternation between two drivers can be
 * achieved by specifying which driver to use for each call (e.g.: $this->session->native->set_userdata('foo', 'bar');
 * $this->session->cookie->userdata('foo'); $this->session->native->unset_userdata('foo');). Notice in the previous
 * example that the _native_ userdata value 'foo' would be set to 'bar', which would NOT be returned by the call for
 * the _cookie_ userdata 'foo', nor would the _cookie_ value be unset by the call to unset the _native_ 'foo' value.
 *
 * @package		CodeIgniter
 * @subpackage	Libraries
 * @category	Sessions
 * @author		Darren Hill (DChill)
 * @link		http://codeigniter.com/user_guide/libraries/sessions.html
 */
final class Session extends CI_Driver_Library {
	public $params = array();
	private $current = null;
	private $userdata = array();

	const FLASHDATA_KEY = 'flash';
	const FLASHDATA_NEW = ':new:';
	const FLASHDATA_OLD = ':old:';
	const FLASHDATA_EXP = ':exp:';
	const EXPIRATION_KEY = '__expirations';
	const TEMP_EXP_DEF = 300;

	/**
	 * Session constructor
	 *
	 * The constructor loads the configured driver ('sess_driver' in config.php or as a parameter), running
	 * routines in its constructor, and manages flashdata aging.
	 *
	 * @param   array	Configuration parameters
	 */
	public function __construct(array $params = array())
	{
		log_message('debug', 'Session Class Initialized');

		// Get valid drivers list
		$CI =& get_instance();
		$this->valid_drivers = array('Session_Native', 'Session_Cookie');
		$key = 'sess_valid_drivers';
		$drivers = (isset($params[$key])) ? $params[$key] : $CI->config->item($key);
		if ($drivers)
		{
			if (!is_array($drivers)) $drivers = array($drivers);

			// Add driver names to valid list
			foreach ($drivers as $driver)
			{
				if (!in_array(strtolower($driver), array_map('strtolower', $this->valid_drivers)))
				{
					$this->valid_drivers[] = $driver;
				}
			}
		}

		// Get driver to load
		$key = 'sess_driver';
		$driver = (isset($params[$key])) ? $params[$key] : $CI->config->item($key);
		if (!$driver) $driver = 'Native';
		if (!in_array('session_'.strtolower($driver), array_map('strtolower', $this->valid_drivers)))
		{
			$this->valid_drivers[] = 'Session_'.$driver;
		}

		// Save a copy of parameters in case drivers need access
		$this->params = $params;

		// Load driver and get array reference
		$this->load_driver($driver);
		$this->userdata =& $this->current->get_userdata();

		// Delete 'old' flashdata (from last request)
		$this->_flashdata_sweep();

		// Mark all new flashdata as old (data will be deleted before next request)
		$this->_flashdata_mark();

		// Delete expired tempdata
		$this->_tempdata_sweep();

		log_message('debug', 'Session routines successfully run');
	}

	/**
	 * Loads session storage driver
	 *
	 * @param   string	Driver classname
	 * @return  object	Loaded driver object
	 */
	public function load_driver($driver)
	{
        if ('userdata' === $driver)
        {
            // var_dump($driver, $this->current);
            throw new Exception('Invalid Session Access, check CodeIgniter documentation.');
        }

		// Save reference to most recently loaded driver as library default
		$this->current = parent::load_driver($driver);
		return $this->current;
	}

	/**
	 * Select default session storage driver
	 *
	 * @param   string	Driver classname
	 * @return  void
	 */
	public function select_driver($driver)
	{
		// Validate driver name
		$lowername = strtolower($driver);
		if (in_array($lowername, array_map('strtolower', $this->valid_drivers)))
		{
			// See if regular or lowercase variant is loaded
			if (class_exists($driver))
			{
				$this->current = $this->$driver;
			}
			else if (class_exists($lowername))
			{
				$this->current = $this->$lowername;
			}
			else
			{
				$this->load_driver($driver);
			}
		}
	}

	/**
	 * Destroy the current session
	 *
	 * @return  void
	 */
	public function sess_destroy()
	{
		// Just call destroy on driver
		$this->current->sess_destroy();
	}

	/**
	 * Regenerate the current session
	 *
	 * @param   boolean	Destroy session data flag (default: false)
	 * @return  void
	 */
	public function sess_regenerate($destroy = false)
	{
		// Just call regenerate on driver
		$this->current->sess_regenerate($destroy);
	}

	/**
	 * Fetch a specific item from the session array
	 *
	 * @param   string	Item key
	 * @return  string	Item value
	 */
	public function userdata($item)
	{
		// Return value or FALSE if not found
		return (!isset($this->userdata[$item])) ? FALSE : $this->userdata[$item];
	}

	/**
	 * Fetch all session data
	 *
	 * @return	array	User data array
	 */
	public function all_userdata()
	{
		// Return entire array
		return (!isset($this->userdata)) ? FALSE : $this->userdata;
	}

	/**
	 * Add or change data in the "userdata" array
	 *
	 * @param   mixed	Item name or array of items
	 * @param   string	Item value or empty string
	 * @return  void
	 */
	public function set_userdata($newdata = array(), $newval = '')
	{
		// Wrap params as array if singular
		if (is_string($newdata))
		{
			$newdata = array($newdata => $newval);
		}

		// Set each name/value pair
		if (count($newdata) > 0)
		{
			foreach ($newdata as $key => $val)
			{
				$this->userdata[$key] = $val;
			}
		}

		// Tell driver data changed
		$this->current->sess_save();
	}

	/**
	 * Delete a session variable from the "userdata" array
	 *
	 * @param   mixed	Item name or array of item names
	 * @return  void
	 */
	public function unset_userdata($newdata = array())
	{
		// Wrap single name as array
		if (is_string($newdata))
		{
			$newdata = array($newdata => '');
		}

		// Unset each item name
		if (count($newdata) > 0)
		{
			foreach ($newdata as $key => $val)
			{
				unset($this->userdata[$key]);
			}
		}

		// Tell driver data changed
		$this->current->sess_save();
	}

	/**
	 * Determine if an item exists
	 *
	 * @param   string	Item name
	 * @return  boolean
	 */
	public function has_userdata($item)
	{
		// Check for item name
		return isset($this->userdata[$item]);
	}

	/**
	 * Add or change flashdata, only available until the next request
	 *
	 * @param   mixed	Item name or array of items
	 * @param   string	Item value or empty string
	 * @return  void
	 */
	public function set_flashdata($newdata = array(), $newval = '')
	{
		// Wrap item as array if singular
		if (is_string($newdata))
		{
			$newdata = array($newdata => $newval);
		}

		// Prepend each key name and set value
		if (count($newdata) > 0)
		{
			foreach ($newdata as $key => $val)
			{
				$flashdata_key = self::FLASHDATA_KEY.self::FLASHDATA_NEW.$key;
				$this->set_userdata($flashdata_key, $val);
			}
		}
	}

	/**
	 * Keeps existing flashdata available to next request.
	 *
	 * @param   string	Item key
	 * @return  void
	 */
	public function keep_flashdata($key)
	{
		// 'old' flashdata gets removed.  Here we mark all
		// flashdata as 'new' to preserve it from _flashdata_sweep()
		$old_flashdata_key = self::FLASHDATA_KEY.self::FLASHDATA_OLD.$key;
		$value = $this->userdata($old_flashdata_key);

		$new_flashdata_key = self::FLASHDATA_KEY.self::FLASHDATA_NEW.$key;
		$this->set_userdata($new_flashdata_key, $value);
	}

	/**
	 * Fetch a specific flashdata item from the session array
	 *
	 * @param   string	Item key
	 * @return  string
	 */
	public function flashdata($key)
	{
		// Prepend key and retrieve value
		$flashdata_key = self::FLASHDATA_KEY.self::FLASHDATA_OLD.$key;
		return $this->userdata($flashdata_key);
	}

	/**
	 * Add or change tempdata, only available
	 * until expiration
	 *
	 * @param   mixed	Item name or array of items
	 * @param   string	Item value or empty string
	 * @param   int		Item lifetime in seconds or 0 for default
	 * @return  void
	 */
	public function set_tempdata($newdata = array(), $newval = '', $expire = 0)
	{
		// Set expiration time
		$expire = time() + ($expire ? $expire : self::TEMP_EXP_DEF);

		// Wrap item as array if singular
		if (is_string($newdata))
		{
			$newdata = array($newdata => $newval);
		}

		// Get or create expiration list
		$expirations = $this->userdata(self::EXPIRATION_KEY);
		if (!$expirations)
		{
			$expirations = array();
		}

		// Prepend each key name and set value
		if (count($newdata) > 0)
		{
			foreach ($newdata as $key => $val)
			{
				$tempdata_key = self::FLASHDATA_KEY.self::FLASHDATA_EXP.$key;
				$expirations[$tempdata_key] = $expire;
				$this->set_userdata($tempdata_key, $val);
			}
		}

		// Update expiration list
		$this->set_userdata(self::EXPIRATION_KEY, $expirations);
	}

	/**
	 * Delete a temporary session variable from the "userdata" array
	 *
	 * @param   mixed	Item name or array of item names
	 * @return  void
	 */
	public function unset_tempdata($newdata = array())
	{
		// Get expirations list
		$expirations = $this->userdata(self::EXPIRATION_KEY);
		if (!$expirations || !count($expirations))
		{
			// Nothing to do
			return;
		}

		// Wrap single name as array
		if (is_string($newdata))
		{
			$newdata = array($newdata => '');
		}

		// Prepend each item name and unset
		if (count($newdata) > 0)
		{
			foreach ($newdata as $key => $val)
			{
				$tempdata_key = self::FLASHDATA_KEY.self::FLASHDATA_EXP.$key;
				unset($expirations[$tempdata_key]);
				$this->unset_userdata($tempdata_key);
			}
		}

		// Update expiration list
		$this->set_userdata(self::EXPIRATION_KEY, $expirations);
	}

	/**
	 * Fetch a specific tempdata item from the session array
	 *
	 * @param   string	Item key
	 * @return  string
	 */
	public function tempdata($key)
	{
		// Prepend key and return value
		$tempdata_key = self::FLASHDATA_KEY.self::FLASHDATA_EXP.$key;
		return $this->userdata($tempdata_key);
	}

	/**
	 * Identifies flashdata as 'old' for removal
	 * when _flashdata_sweep() runs.
	 *
	 * @access	private
	 * @return	void
	 */
	private function _flashdata_mark()
	{
		$userdata = $this->all_userdata();
		foreach ($userdata as $name => $value)
		{
			$parts = explode(self::FLASHDATA_NEW, $name);
			if (is_array($parts) && count($parts) === 2)
			{
				$new_name = self::FLASHDATA_KEY.self::FLASHDATA_OLD.$parts[1];
				$this->set_userdata($new_name, $value);
				$this->unset_userdata($name);
			}
		}
	}

	/**
	 * Removes all flashdata marked as 'old'
	 *
	 * @access	private
	 * @return	void
	 */
	private function _flashdata_sweep()
	{
		$userdata = $this->all_userdata();
		foreach ($userdata as $key => $value)
		{
			if (strpos($key, self::FLASHDATA_OLD))
			{
				$this->unset_userdata($key);
			}
		}
	}

	/**
	 * Removes all expired tempdata
	 *
	 * @access	private
	 * @return	void
	 */
	private function _tempdata_sweep()
	{
		// Get expirations list
		$expirations = $this->userdata(self::EXPIRATION_KEY);
		if (!$expirations || !count($expirations))
		{
			// Nothing to do
			return;
		}

		// Unset expired elements
		$now = time();
		$userdata = $this->all_userdata();
		foreach ($userdata as $key => $value)
		{
			if (strpos($key, self::FLASHDATA_EXP) && $expirations[$key] < $now)
			{
				unset($expirations[$key]);
				$this->unset_userdata($key);
			}
		}

		// Update expiration list
		$this->set_userdata(self::EXPIRATION_KEY, $expirations);
	}
}
// END Session Class


/**
 * SessionDriver Class
 *
 * Extend this class to make a new Session driver.
 * A Session driver basically manages an array of name/value pairs with some sort of storage mechanism.
 * To make a new driver, derive from (extend) SessionDriver. Overload the initialize method and read or create
 * session data. Then implement a save handler to write changed data to storage (sess_save), a destroy handler
 * to remove deleted data (sess_destroy), and an access handler to expose the data (get_userdata).
 * Put your driver in the libraries/Session/drivers folder anywhere in the loader paths. This includes the application
 * directory, the system directory, or any path you add with $CI->load->add_package_path().
 * Your driver must be named Session_<name>, where <name> is capitalized, and your filename must be Session_<name>.EXT,
 * preferably also capitalized. (e.g.: Session_Foo in libraries/Session/drivers/Session_Foo.php)
 * Then specify the driver by setting 'sess_driver' in your config file or as a parameter when loading the Session
 * object. (e.g.: $config['sess_driver'] = 'foo'; OR $CI->load->driver('session', array('sess_driver' => 'foo')); )
 * Already provided are the Native driver, which manages the native PHP $_SESSION array, and
 * the Cookie driver, which manages the data in a browser cookie, with optional extra storage in a database table.
 *
 * @package	 CodeIgniter
 * @subpackage  Libraries
 * @category	Sessions
 * @author	  Darren Hill (DChill)
 */
abstract class SessionDriver extends CI_Driver {
	/**
	 * Decorate
	 *
	 * Decorates the child with the parent driver lib's methods and properties
	 *
	 * @param	object	Parent library object
	 * @return	void
	 */
	public function decorate($parent)
	{
		// Call base class decorate first
		parent::decorate($parent);

		// Call initialize method now that driver has access to $this->parent
		$this->initialize();
	}

	/**
	 * __call magic method
	 *
	 * Handles access to the parent driver library's methods
	 *
	 * @param   string	Library method name
	 * @param   array	Method arguments (default: none)
	 * @return	mixed
	 */
	public function __call($method, $args = array())
	{
		// Make sure the parent library uses this driver
		$this->parent->select_driver(get_class($this));
		return parent::__call($method, $args);
	}

	/**
	 * Initialize driver
	 *
	 * @return  void
	 */
	protected function initialize()
	{
		// Overload this method to implement initialization
	}

	/**
	 * Save the session data
	 *
	 * Data in the array has changed - perform any storage synchronization necessary
	 * The child class MUST implement this abstract method!
	 *
	 * @return  void
	 */
	abstract public function sess_save();

	/**
	 * Destroy the current session
	 *
	 * Clean up storage for this session - it has been terminated
	 * The child class MUST implement this abstract method!
	 *
	 * @return  void
	 */
	abstract public function sess_destroy();

	/**
	 * Regenerate the current session
	 *
	 * Regenerate the session id
	 * The child class MUST implement this abstract method!
	 *
	 * @param   boolean	Destroy session data flag (default: false)
	 * @return  void
	 */
	abstract public function sess_regenerate($destroy = false);

	/**
	 * Get a reference to user data array
	 *
	 * Give array access to the main Session object
	 * The child class MUST implement this abstract method!
	 *
	 * @return  array	Reference to userdata
	 */
	abstract public function &get_userdata();
}
// END SessionDriver Class


/* End of file Session.php */
/* Location: ./system/libraries/Session/Session.php */
?>