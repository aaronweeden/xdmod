<?php

require_once dirname(__FILE__) . '/../../../configuration/linker.php';

\xd_security\start_session();

$controller = new XDController(array(STATUS_LOGGED_IN, STATUS_MANAGER_ROLE), dirname(__FILE__));
$controller->registerOperation('get_summary');
$controller->registerOperation('get_messages');
$controller->registerOperation('get_levels');
$controller->invoke('REQUEST', 'xdDashboardUser');
