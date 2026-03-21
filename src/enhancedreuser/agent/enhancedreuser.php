<?php
/*
 SPDX-FileCopyrightText: © Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

/**
 * @namespace Fossology::EnhancedReuser
 * @brief Namespace for Enhanced Reuser agent
 */
namespace Fossology\EnhancedReuser;

include_once(__DIR__ . "/EnhancedReuserAgent.php");

$agent = new EnhancedReuserAgent();
$agent->scheduler_connect();
$agent->run_scheduler_event_loop();
$agent->scheduler_disconnect(0);
