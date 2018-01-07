<?php namespace Imanager;

class FieldMapper extends Mapper
{
	/**
	 * @var array of the objects of type Field
	 */
	public $fields = array();

	/**
	 * @var int - Fields counter
	 */
	public $total = 0;

	/**
	 * @var string - Path of the buffered fields
	 */
	public $path = null;

	/**
	 * Init fields of a category
	 *
	 * @since 3.0
	 * @param $category_id
	 *
	 * @return array
	 */
	public function init($category_id)
	{
		$this->path = IM_BUFFERPATH.'fields/'.(int) $category_id.'.fields.php';

		if(!file_exists(dirname($this->path))) {
			Util::install($this->path);
		}
		if(file_exists($this->path)) {
			$this->fields = include($this->path);
			$this->total = count($this->fields);
			return true;
		}
		unset($this->fields);
		$this->fields = null;
		$this->total = 0;

		return false;
	}

	public function countFields(array $fields=array())
	{$locfields = !empty($fields) ? $fields : $this->fields; return count($locfields);}

	/**
	 *
	 * @param $stat
	 * @param array $fields
	 *
	 * @return bool|mixed
	 */
	public function getField($stat, array $fields=array())
	{
		$locfields = !empty($fields) ? $fields : $this->fields;
		// nothing to select
		if(empty($fields)) {
			if(!$this->countFields() || $this->countFields() <= 0) { return false; }
		}

		// only id is entered
		if(is_numeric($stat)) {
			foreach($locfields as $fieldkey => $field) {
				if((int) $field->id == (int) $stat) return $field;
			}
		}

		if(false !== strpos($stat, '='))
		{
			$data = explode('=', $stat, 2);
			$key = strtolower(trim($data[0]));
			$val = trim($data[1]);
			if(false !== strpos($key, ' ')) return false;

			// Searching for the field name
			if($key == 'name') return isset($locfields[$val]) ? $locfields[$val] : false;

			foreach($locfields as $fieldkey => $field) {
				foreach($field as $k => $v) {
					// looking for the field id
					if($key == 'id' && (int) $field->id == (int) $val) return $field;
					if($key == $k && $val == $v) return $field;
				}
			}
		} else {
			if(isset($locfields[$stat])) return $locfields[$stat];
		}
		return false;
	}


	/**
	 * A public method for sorting the fields
	 *
	 * You can sort fields by using any attribute
	 * Default sortng attribute is "position":
	 * FieldMapper::sort('position', 'DESC', $your_fields_array)
	 *
	 * @param string $filterby
	 * @param string $order
	 * @param array $fields
	 *
	 * @return boolean|array of Field objects
	 */
	public function sort($filterby = null, $order = 'asc', array $fields = array())
	{
		$localFields = !empty($fields) ? $fields : $this->fields;

		if(empty($localFields)) return false;

		$this->filterby = ($filterby) ? $filterby : $this->imanager->config->filterByFields;

		usort($localFields, array($this, 'sortObjects'));
		// Sort in DESCENDING order
		if(strtolower($order) != 'asc') $localFields = $this->reverseFields($localFields);
		// Reviese field ids
		$localFields = $this->reviseFieldIds($localFields);

		if(!empty($fields)) return $localFields;

		$this->fields = $localFields;

		return $this->fields;
	}


	/**
	 * Reverse the array of fields
	 *
	 * @param array $fieldcontainer An array of objects
	 * @return boolean|array
	 */
	private function reverseFields($fieldcontainer)
	{
		if(!is_array($fieldcontainer)) return false;
		return array_reverse($fieldcontainer);
	}


	/**
	 * Revise keys of the array of fields and changes these into real field Ids
	 *
	 * @param array $fieldcontainer An array of objects
	 * @return boolean|array
	 */
	private function reviseFieldIds($fieldcontainer)
	{
		if(!is_array($fieldcontainer)) return false;
		$result = array();
		foreach($fieldcontainer as $val)
			$result[$val->name] = $val;
		return $result;
	}


	/**
	 * Sorts the field objects by an attribut
	 *
	 * @param $a $b objects to be sorted
	 * @return boolean
	 */
	private function sortObjects($a, $b)
	{
		$a = $a->{$this->filterby};
		$b = $b->{$this->filterby};
		if(is_numeric($a))
		{
			if($a == $b) {return 0;}
			else
			{
				if($b > $a) {return -1;}
				else {return 1;}
			}
		} else {return strcasecmp($a, $b);}
	}

}