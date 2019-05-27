<?php

if (!defined('_PS_VERSION_')) {
	exit;
}

class AfterShip extends Module {
	public function __construct()
	{
		$this->name = 'aftership';
        $this->tab = 'shipping_logistics';
		$this->version = '1.0.9';
		$this->author = 'AfterShip';
		$this->need_instance = 0;

		parent::__construct();

		$this->displayName = $this->l('AfterShip');
		$this->description = $this->l('AfterShip Connector');
        $this->ps_versions_compliancy = array('min' => '1.5.0.17', 'max' => _PS_VERSION_);
	}

	public function install()
	{
		if (parent::install() == false) {
			return false;
		}
		return true;
	}
}
