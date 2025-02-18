<?php
/**
 *
 * @package    mahara
 * @subpackage auth-saml
 * @author     Piers Harding <piers@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 */

defined('INTERNAL') || die();

$config = new stdClass();
$config->version = 2022020100;
$config->release = '1.11.0';
$config->name = 'saml';
$config->requires_config = 1;
$config->requires_parent = 0;
