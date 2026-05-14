<?php
/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

$loader = $GLOBALS['container']->get('twig.loader');
$loader->addPath(dirname(__FILE__) . '/template');

require_once dirname(__FILE__) . '/EnhancedReuseDiffViewPlugin.php';
