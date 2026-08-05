<?php
// SPDX-License-Identifier: MIT
/**
 * Copyright (c) 2026 Mateusz Bartocha <contact@bestcoding.net>
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class Bccli extends Module
{
    public function __construct()
    {
        $this->name = 'bccli';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Mateusz Bartocha';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'BC CLI';
        $this->description = 'Console commands for shop maintenance, starting with thumbnail regeneration.';
    }
}
