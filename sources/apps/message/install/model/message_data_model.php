<?php
defined('IN_YUNCMS') or exit('No permission resources.');
class message_data_model extends Model {
	public function __construct() {
		$this->setting = 'default';
		$this->table_name = 'message_data';
		$this->_username = cookie('_username');
		$this->_userid = cookie('_userid');
		parent::__construct();
	}
}
?>