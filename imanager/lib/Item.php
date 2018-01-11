<?php namespace Imanager;

/**
 * Class Item
 *
 * @package Imanager
 */
class Item extends FieldMapper
{
	/**
	 * @var int|null - Category id
	 */
	public $categoryid = null;

	/**
	 * @var int|null - Item id
	 */
	public $id = null;

	/**
	 * @var null|string - Item name
	 */
	public $name = null;

	/**
	 * @var null|string - Item label
	 */
	public $label = null;

	/**
	 * @var null|int - Item position
	 */
	public $position = null;

	/**
	 * @var null|boolean - Active/inactive flag
	 */
	public $active = null;

	/**
	 * @var null|string - Timestamp
	 */
	public $created = null;

	/**
	 * @var null|boolean - Timestamp
	 */
	public $updated = null;

	/**
	 * Item constructor.
	 *
	 * @param $category_id
	 */
	public function __construct($category_id)
	{
		$this->categoryid = (int) $category_id;

		settype($this->categoryid, 'integer');
		settype($this->id, 'integer');
		settype($this->position, 'integer');
		settype($this->active, 'boolean');
		settype($this->created, 'integer');
		settype($this->updated, 'integer');

		unset($this->fields);
		unset($this->total);
		unset($this->path);
		unset($this->imanager);

		//parent::init($this->categoryid);
	}

	/**
	 * Restricted parent init.
	 * Used to prevent the writing of external properties in item
	 * objects buffer, is a kind of lazy init method
	 *
	 * @param $name
	 */
	public function init($categoryid) { if(!isset($this->imanager)) { parent::init($categoryid);} }

	/**
	 * Restricted parent init.
	 * Used to prevent the deformation of the properties in item objects
	 *
	 * @param $name
	 */
	public function __get($name)
	{
		if($name == 'fields') {
			$this->init($this->categoryid);
			return $this->{$name};
		}
	}

	/**
	 * This static method is called for items exported by var_export()
	 *
	 * @param $an_array
	 *
	 * @return Item
	 */
	public static function __set_state($an_array)
	{
		$_instance = new Item($an_array['categoryid']);
		foreach($an_array as $key => $val) {
			if(is_array($val)) $_instance->{$key} = $val;
			else $_instance->{$key} = $val;
		}
		return $_instance;
	}

	/**
	 * Retrives item attributes array
	 */
	private function getAttributes() {
		return array('categoryid', 'id', 'name', 'label', 'position', 'active', 'options', 'created', 'updated');
	}

	/**
	 * Returns next available id
	 *
	 * @return int
	 */
	private function getNextId()
	{
		$ids = array();
		$maxid = 1;
		if(file_exists(IM_BUFFERPATH.'items/'.(int) $this->categoryid.'.items.php')) {
			$items = include(IM_BUFFERPATH.'items/'.(int) $this->categoryid.'.items.php');
			if(is_array($items)) { $maxid = (max(array_keys($items))+1);}
		}
		return $maxid;
	}

	/**
	 * A secure method to set the value of a field
	 *
	 * @param string $fieldname - Fieldname or attribute
	 * @param int|string|boolean|array $value
	 * @param bool $sanitize
	 *
	 * @return bool
	 */
	public function set($fieldname, $value, $sanitize=true)
	{
		$this->init($this->categoryid);
		$attributeKey = strtolower(trim($fieldname));
		$isAttribute = !in_array($attributeKey, $this->getAttributes()) ? false : true;
		if(!$isAttribute && !isset($this->fields[$fieldname])) {
			MsgReporter::setError('err_fieldname_exists');
			return false;
		}
		if($isAttribute) {
			if(in_array($attributeKey, array('categoryid', 'id', 'position', 'created', 'updated'))) {
				$this->$attributeKey = (int) $value;
				if($this->$attributeKey) return true;
			} elseif($attributeKey == 'name' || $attributeKey == 'label') {
				$this->$attributeKey = $this->imanager->sanitizer->text($value,
					array('maxLength' => $this->imanager->config->maxItemNameLength)
				);
				if($this->$attributeKey) return true;
			} elseif($attributeKey == 'active') {
				$this->$attributeKey = (boolean) $value;
				if($this->$attributeKey) return true;
			}
			MsgReporter::setError('err_setting_attribute', array('attribute' => $attributeKey));
			return false;
		}

		$field = $this->fields[$fieldname];

		$inputClassName = __NAMESPACE__.'\Input'.ucfirst($field->type);
		$Input = new $inputClassName($field);
		//if(!is_array($value)) {
		if(!$sanitize) {
			if(false === $Input->prepareInput($value)) { return false; }
			$this->{$fieldname} = $Input->value;
		} else {
			if(false === $Input->prepareInput($value, true)) {return false; }
			$this->{$fieldname} = $Input->value;
		}
		return true;
	}

	/**
	 * Removes redundant item object attributes
	 */
	public function declutter()
	{
		// Remove any other item attributes
		foreach($this as $key => $value) {
			if($key != 'fields' && !in_array($key, $this->getAttributes()) && !array_key_exists($key, $this->fields)) {
				unset($this->$key);
			}
		}
		unset($this->fields);
	}

	/**
	 * Save item
	 *
	 * @return bool
	 */
	public function save()
	{
		$this->init($this->categoryid);
		$sanitizer = $this->imanager->sanitizer;
		$config = $this->imanager->config;
		$now = time();

		$this->id = (!$this->id) ? $this->getNextId() : (int) $this->id;

		if(!$this->created) $this->created = $now;
		$this->updated = $now;
		if(!$this->position) $this->position = (int) $this->id;

		// Set empty values to default defined field value
		foreach($this->fields as $key => $field) {
			if(!isset($this->{$field->name}) || !$this->{$field->name}) $this->{$field->name} = $field->default;
		}

		$im = $this->imanager->itemMapper;
		$im->init($this->categoryid);

		// Clean-up item object by removing redundant item object attributes
		$this->declutter();

		$im->items[$this->id] = $this;

		// Create a backup if necessary
		if($config->backupItems) {
			Util::createBackup(dirname($im->path).'/', basename($im->path, '.php'), '.php');
		}

		$export = var_export($im->items, true);
		file_put_contents($im->path, '<?php return ' . $export . '; ?>');

		return true;
	}
}